<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Marketplace;

/**
 * AtomicDeploy — zet een map klaar via een tijdelijke map en verwisselt die met rename().
 * De vorige versie blijft als back-up staan tot commit(); rollback() zet hem terug.
 * Gedeeld door PackageManager (modules/themes) en PluginManager (plugins).
 */
final class AtomicDeploy
{
    /** @return array{dest:string,backup:?string,base:string} */
    public static function deploy(string $base, string $slug, string $sourceRoot): array
    {
        ManifestValidator::assertSlug($slug);
        $base = realpath($base);
        if ($base === false) {
            throw new PackageException('Doelmap ontbreekt.');
        }
        $dest    = $base . '/' . $slug;
        $staging = $base . '/.' . $slug . '.new-' . SafeFs::randomSuffix();
        $backup  = null;

        try {
            SafeFs::copyTree($sourceRoot, $staging);
            if (is_dir($dest)) {
                $backup = $base . '/.' . $slug . '.bak-' . SafeFs::randomSuffix();
                if (!rename($dest, $backup)) {
                    throw new PackageException('Kan bestaande installatie niet veiligstellen.');
                }
            }
            if (!rename($staging, $dest)) {
                if ($backup !== null) {
                    rename($backup, $dest);
                }
                throw new PackageException('Kan nieuwe installatie niet activeren.');
            }
        } catch (\Throwable $e) {
            if (is_dir($staging)) {
                SafeFs::deleteTree($base, $staging);
            }
            throw $e instanceof PackageException ? $e : new PackageException($e->getMessage(), 0, $e);
        }

        return ['dest' => $dest, 'backup' => $backup, 'base' => $base];
    }

    /** @param array{dest:string,backup:?string,base:string} $deploy */
    public static function rollback(array $deploy): void
    {
        try {
            SafeFs::deleteTree($deploy['base'], $deploy['dest']);
            if ($deploy['backup'] !== null) {
                rename($deploy['backup'], $deploy['dest']);
            }
        } catch (\Throwable $e) {
            error_log('Rollback van pakket mislukt: ' . $e->getMessage());
        }
    }

    /** @param array{dest:string,backup:?string,base:string} $deploy */
    public static function commit(array $deploy): void
    {
        if ($deploy['backup'] !== null) {
            SafeFs::deleteTree($deploy['base'], $deploy['backup']);
        }
    }
}
