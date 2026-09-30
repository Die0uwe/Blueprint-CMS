<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Plugin;

use CommunityFusion\Core\Marketplace\ManifestValidator;
use CommunityFusion\Core\Marketplace\PackageException;

/**
 * PluginManifest — valideert plugin.json (onbetrouwbare invoer uit een ZIP of map).
 *
 * Schema: zie docs/plugins.md. Verplicht: slug, name, version. Overig optioneel:
 * author, description, requires{php,blueprint}, class, autoload{ns:dir}, permissions[],
 * settings[], hooks[], blocks[], routes[].
 */
final class PluginManifest
{
    public const NAMESPACE_PREFIX = 'CommunityFusion\\Plugins\\';
    public const SETTING_TYPES = ['string', 'int', 'bool', 'json', 'encrypted'];

    /**
     * @param array<string,mixed> $m
     * @return array<string,mixed>
     * @throws PackageException
     */
    public static function validate(array $m, ?string $expectedSlug, string $cmsVersion, string $phpVersion = PHP_VERSION): array
    {
        foreach (array_keys($m) as $k) {
            if (is_string($k) && str_starts_with($k, '_')) {
                unset($m[$k]);
            }
        }
        $slug = $m['slug'] ?? null;
        if (!is_string($slug)) {
            throw new PackageException("Verplicht veld 'slug' ontbreekt in plugin.json.");
        }
        ManifestValidator::assertSlug($slug);
        if ($expectedSlug !== null && $slug !== $expectedSlug) {
            throw new PackageException("Plugin-slug '{$slug}' komt niet overeen met de mapnaam '{$expectedSlug}'.");
        }
        if (!isset($m['name']) || !is_string($m['name']) || trim($m['name']) === '' || mb_strlen($m['name']) > 100) {
            throw new PackageException("Veld 'name' ontbreekt of is ongeldig (1-100 tekens).");
        }
        if (!isset($m['version']) || !is_string($m['version']) || !preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]{1,32})?$/', $m['version'])) {
            throw new PackageException("Veld 'version' ontbreekt of is geen geldige versie (bijv. 1.0.0).");
        }
        foreach (['author' => 100, 'description' => 500] as $f => $max) {
            if (isset($m[$f]) && (!is_string($m[$f]) || mb_strlen($m[$f]) > $max)) {
                throw new PackageException("Veld '{$f}' is ongeldig (tekst, maximaal {$max} tekens).");
            }
        }

        self::checkRequires($m['requires'] ?? [], $cmsVersion, $phpVersion);
        self::checkClassAndAutoload($m);
        self::checkPermissions($m['permissions'] ?? [], $slug);
        self::checkSettings($m['settings'] ?? []);
        self::checkNameList($m['hooks'] ?? [], 'hooks', '/^[a-z][a-z0-9_.]{1,60}$/', 50);
        self::checkNameList($m['blocks'] ?? [], 'blocks', '/^[A-Z][A-Za-z0-9_]{0,60}$/', 50);
        if (!empty($m['blocks']) && empty($m['autoload'])) {
            throw new PackageException("Blocks vragen om een 'autoload'-map (de klassen moeten laadbaar zijn).");
        }
        self::checkRoutes($m['routes'] ?? []);

        return $m;
    }

    private static function checkRequires(mixed $req, string $cmsVersion, string $phpVersion): void
    {
        if (!is_array($req)) {
            throw new PackageException("Veld 'requires' moet een object zijn.");
        }
        $checks = ['php' => $phpVersion, 'blueprint' => $cmsVersion];
        foreach ($req as $key => $constraint) {
            if (!isset($checks[$key])) {
                throw new PackageException("Onbekende vereiste '{$key}' (toegestaan: php, blueprint).");
            }
            if (!is_string($constraint) || !preg_match('/^>=\s*\d+(?:\.\d+){0,2}$/', $constraint)) {
                throw new PackageException("Vereiste '{$key}' moet de vorm '>=x.y.z' hebben.");
            }
            $min = trim(substr($constraint, 2));
            if (version_compare($checks[$key], $min, '<')) {
                $what = $key === 'php' ? 'PHP' : 'Blueprint CMS';
                throw new PackageException("Deze plugin vereist {$what} {$constraint}; hier draait {$checks[$key]}.");
            }
        }
    }

    /** @param array<string,mixed> $m */
    private static function checkClassAndAutoload(array $m): void
    {
        $classPrefix = self::NAMESPACE_PREFIX;
        if (isset($m['class'])) {
            $c = $m['class'];
            if (!is_string($c) || !preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*$/', $c) || !str_starts_with($c, $classPrefix)) {
                throw new PackageException("Veld 'class' moet een klasse in {$classPrefix}… zijn.");
            }
            if (empty($m['autoload'])) {
                throw new PackageException("Een 'class' vraagt om een 'autoload'-map.");
            }
        }
        if (isset($m['autoload'])) {
            if (!is_array($m['autoload']) || $m['autoload'] === [] || count($m['autoload']) > 5) {
                throw new PackageException("Veld 'autoload' moet 1-5 namespace → map paren bevatten.");
            }
            foreach ($m['autoload'] as $ns => $dir) {
                if (!is_string($ns) || !preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+$/', $ns) || !str_starts_with($ns, $classPrefix)) {
                    throw new PackageException("Autoload-namespace moet met {$classPrefix} beginnen en op \\ eindigen.");
                }
                self::assertRelativePath($dir, 'autoload');
            }
        }
    }

    /** @param mixed $perms */
    private static function checkPermissions(mixed $perms, string $slug): void
    {
        if (!is_array($perms) || count($perms) > 50) {
            throw new PackageException("Veld 'permissions' moet een lijst zijn (maximaal 50).");
        }
        foreach ($perms as $p) {
            if (!is_string($p) || !preg_match('/^' . preg_quote($slug, '/') . '\.[a-z0-9_.-]{1,50}$/', $p)) {
                throw new PackageException("Permissies moeten met '{$slug}.' beginnen (bijv. {$slug}.use).");
            }
        }
    }

    /** @param mixed $settings */
    private static function checkSettings(mixed $settings): void
    {
        if (!is_array($settings) || count($settings) > 50) {
            throw new PackageException("Veld 'settings' moet een lijst zijn (maximaal 50).");
        }
        $seen = [];
        foreach ($settings as $s) {
            if (!is_array($s) || !isset($s['key'], $s['type']) || !is_string($s['key']) || !is_string($s['type'])
                || !preg_match('/^[a-z0-9_]{1,50}$/', $s['key']) || !in_array($s['type'], self::SETTING_TYPES, true)) {
                throw new PackageException("Elke instelling heeft een 'key' (a-z0-9_) en een 'type' (" . implode(', ', self::SETTING_TYPES) . ') nodig.');
            }
            if (isset($seen[$s['key']])) {
                throw new PackageException("Instelling '{$s['key']}' staat dubbel in het manifest.");
            }
            $seen[$s['key']] = true;
            if (isset($s['label']) && (!is_string($s['label']) || mb_strlen($s['label']) > 100)) {
                throw new PackageException("Label van instelling '{$s['key']}' is ongeldig.");
            }
        }
    }

    /** @param mixed $list */
    private static function checkNameList(mixed $list, string $field, string $pattern, int $max): void
    {
        if (!is_array($list) || count($list) > $max) {
            throw new PackageException("Veld '{$field}' moet een lijst zijn (maximaal {$max}).");
        }
        foreach ($list as $item) {
            if (!is_string($item) || !preg_match($pattern, $item)) {
                throw new PackageException("Ongeldige waarde in '{$field}'.");
            }
        }
    }

    /** @param mixed $routes */
    private static function checkRoutes(mixed $routes): void
    {
        if (!is_array($routes) || count($routes) > 10) {
            throw new PackageException("Veld 'routes' moet een lijst bestanden zijn (maximaal 10).");
        }
        foreach ($routes as $file) {
            self::assertRelativePath($file, 'routes');
            if (!str_ends_with((string)$file, '.php')) {
                throw new PackageException("Route-bestanden moeten op .php eindigen.");
            }
        }
    }

    private static function assertRelativePath(mixed $path, string $field): void
    {
        if (!is_string($path) || $path === '' || strlen($path) > 200 || str_contains($path, "\0")
            || str_contains($path, '\\') || str_contains($path, ':') || str_starts_with($path, '/')) {
            throw new PackageException("Veld '{$field}' bevat een ongeldig relatief pad.");
        }
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                throw new PackageException("Veld '{$field}' mag niet naar een bovenliggende map wijzen.");
            }
        }
    }
}
