<?php

final class BlueprintVendorAutoloader
{
    private static array $prefixes = [];

    public static function register(array $psr4Map): void
    {
        self::$prefixes = $psr4Map;
        spl_autoload_register([self::class, 'loadClass'], true, true);
    }

    public static function loadClass(string $class): bool
    {
        $prefixes = self::$prefixes;
        uksort($prefixes, static fn($a, $b) => strlen($b) <=> strlen($a));

        foreach ($prefixes as $prefix => $dirs) {
            if ($prefix === '' || !str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $relativePath = str_replace('\\', '/', $relative) . '.php';
            foreach ($dirs as $dir) {
                $file = rtrim($dir, '/') . '/' . $relativePath;
                if (is_file($file)) {
                    require $file;
                    return true;
                }
            }
        }
        return false;
    }
}
