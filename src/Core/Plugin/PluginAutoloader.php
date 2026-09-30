<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Plugin;

/**
 * PluginAutoloader — PSR-4 autoloading voor plugins tijdens runtime.
 *
 * Geüploade plugins staan niet in composer.json; deze loader leest de "autoload"-
 * map uit plugin.json. Bestanden worden alleen geladen als ze na realpath()
 * binnen de plugin-map liggen (geen symlink-ontsnapping).
 */
final class PluginAutoloader
{
    /** @var array<string, list<array{0:string,1:string}>> namespace-prefix → [[pluginDir, subdir]] */
    private static array $maps = [];
    private static bool $registered = false;

    /** @param array<string,string> $psr4 namespace (eindigend op \) => relatieve map */
    public static function register(string $pluginDir, array $psr4): void
    {
        $root = realpath($pluginDir);
        if ($root === false) {
            return;
        }
        foreach ($psr4 as $prefix => $subdir) {
            self::$maps[$prefix][] = [$root, trim($subdir, '/')];
        }
        if (!self::$registered) {
            spl_autoload_register([self::class, 'load']);
            self::$registered = true;
        }
    }

    public static function load(string $class): void
    {
        foreach (self::$maps as $prefix => $dirs) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
            if (!preg_match('#^[A-Za-z0-9_/]+$#', $relative)) {
                continue;
            }
            foreach ($dirs as [$root, $sub]) {
                $file = realpath($root . '/' . ($sub !== '' ? $sub . '/' : '') . $relative . '.php');
                if ($file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
                    require_once $file;
                    return;
                }
            }
        }
    }

    /** Alleen voor tests. */
    public static function reset(): void
    {
        self::$maps = [];
    }
}
