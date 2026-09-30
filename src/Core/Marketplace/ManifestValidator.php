<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Marketplace;

/**
 * ManifestValidator — harde controle van module.json / theme.json uit een pakket.
 *
 * Een manifest komt uit een ZIP en is dus onbetrouwbare invoer. Alles wat later
 * in een pad, een klassenaam of de database belandt wordt hier afgedwongen.
 */
final class ManifestValidator
{
    /** Slug: kleine letters, cijfers en koppeltekens, 1-64 tekens, geen rand-koppelteken. */
    public const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';

    public const TYPES = ['module', 'theme'];

    /** Toegestane namespaces voor de hoofdklasse van een pakket. */
    public const CLASS_PREFIXES = ['CommunityFusion\\Modules\\', 'CommunityFusion\\Plugins\\'];

    /** Geeft de slug terug als hij geldig is, anders PackageException. */
    public static function assertSlug(string $slug): string
    {
        if (!preg_match(self::SLUG_PATTERN, $slug)) {
            throw new PackageException("Ongeldige slug '" . self::printable($slug) . "': alleen a-z, 0-9 en '-', maximaal 64 tekens.");
        }
        return $slug;
    }

    /**
     * Valideer en normaliseer een manifest.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,mixed> Schoon manifest (sleutels met een '_' voorvoegsel zijn verwijderd)
     * @throws PackageException
     */
    public function validate(array $manifest, ?string $expectedSlug = null): array
    {
        // Interne sleutels (zoals _extracted_path) mogen nooit uit een pakket komen.
        foreach (array_keys($manifest) as $key) {
            if (is_string($key) && str_starts_with($key, '_')) {
                unset($manifest[$key]);
            }
        }

        $slug = $manifest['slug'] ?? null;
        if (!is_string($slug)) {
            throw new PackageException("Verplicht veld 'slug' ontbreekt in manifest.");
        }
        self::assertSlug($slug);
        if ($expectedSlug !== null && $slug !== $expectedSlug) {
            throw new PackageException("Manifest-slug '{$slug}' komt niet overeen met de gevraagde slug '{$expectedSlug}'.");
        }

        $name = $manifest['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 100) {
            throw new PackageException("Veld 'name' ontbreekt of is ongeldig (1-100 tekens).");
        }

        $version = $manifest['version'] ?? null;
        if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]{1,32})?$/', $version)) {
            throw new PackageException("Veld 'version' ontbreekt of is geen geldige versie (bijv. 1.0.0).");
        }

        $type = $manifest['type'] ?? 'module';
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw new PackageException("Onbekend pakkettype; toegestaan: " . implode(', ', self::TYPES) . '.');
        }
        $manifest['type'] = $type;

        if (isset($manifest['class'])) {
            $this->assertClass($manifest['class'], $type);
        }

        if (isset($manifest['autoload'])) {
            $this->assertRelativePath($manifest['autoload'], 'autoload');
        }

        if (isset($manifest['permissions'])) {
            if (!is_array($manifest['permissions'])) {
                throw new PackageException("Veld 'permissions' moet een lijst zijn.");
            }
            foreach ($manifest['permissions'] as $perm) {
                if (!is_string($perm) || !preg_match('/^[a-z0-9_.-]{1,64}$/', $perm)) {
                    throw new PackageException('Ongeldige permissienaam in manifest.');
                }
            }
        }

        return $manifest;
    }

    private function assertClass(mixed $class, string $type): void
    {
        if ($type === 'theme') {
            throw new PackageException("Een thema mag geen 'class' (PHP) bevatten.");
        }
        if (!is_string($class) || !preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*$/', $class)) {
            throw new PackageException("Veld 'class' is geen geldige klassenaam.");
        }
        foreach (self::CLASS_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return;
            }
        }
        throw new PackageException("Klasse '{$class}' valt buiten de toegestane namespaces (" . implode(', ', self::CLASS_PREFIXES) . ').');
    }

    private function assertRelativePath(mixed $path, string $field): void
    {
        if (!is_string($path) || $path === '' || strlen($path) > 200
            || str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, ':')
            || str_starts_with($path, '/')) {
            throw new PackageException("Veld '{$field}' is geen geldig relatief pad.");
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                throw new PackageException("Veld '{$field}' mag niet naar een bovenliggende map wijzen.");
            }
        }
    }

    private static function printable(string $value): string
    {
        return mb_substr(preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '', 0, 40);
    }
}
