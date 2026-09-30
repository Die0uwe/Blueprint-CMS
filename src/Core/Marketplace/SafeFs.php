<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Marketplace;

/**
 * SafeFs — verwijderen en kopiëren met containment-check.
 *
 * Verwijderen gebeurt alleen BINNEN een vooraf bepaalde basismap (nooit de basismap
 * zelf) en volgt nooit symlinks. Dit voorkomt dat een foute slug of pad iets buiten
 * modules/, themes/ of de werkmap wist.
 */
final class SafeFs
{
    /** @throws PackageException als $path niet strikt binnen $base ligt */
    public static function assertInside(string $base, string $path): string
    {
        $realBase = realpath($base);
        $realPath = realpath($path);
        if ($realBase === false || $realPath === false) {
            throw new PackageException('Pad bestaat niet of is niet toegankelijk.');
        }
        $prefix = rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realPath . DIRECTORY_SEPARATOR, $prefix) || $realPath === $realBase) {
            throw new PackageException('Pad valt buiten de toegestane map.');
        }
        return $realPath;
    }

    /** Verwijder een map recursief; alleen strikt binnen $base. Geen fout als de map er niet is. */
    public static function deleteTree(string $base, string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path)) {
            throw new PackageException('Een symlink wordt niet verwijderd via deze route.');
        }
        $real = self::assertInside($base, $path);
        if (!is_dir($real)) {
            unlink($real);
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isLink() || !$item->isDir()) {
                unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }
        rmdir($real);
    }

    /** Kopieer een map; symlinks worden overgeslagen. */
    public static function copyTree(string $from, string $to): void
    {
        if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) {
            throw new PackageException('Kan doelmap niet aanmaken.');
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            if ($item->isLink()) {
                continue;
            }
            $target = $to . '/' . $items->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new PackageException('Kan submap niet aanmaken.');
                }
            } elseif (!copy($item->getPathname(), $target)) {
                throw new PackageException('Kopiëren mislukt.');
            }
        }
    }

    public static function randomSuffix(): string
    {
        return bin2hex(random_bytes(6));
    }
}
