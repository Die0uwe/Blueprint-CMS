<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Marketplace;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;

/**
 * PackageManager — Marketplace installatie engine
 *
 * Verantwoordelijkheden:
 * - Packages downloaden (ZIP) en uitpakken
 * - module.json / theme.json valideren
 * - Installeren naar modules/ of themes/ map
 * - DB-registratie bijhouden in cf_marketplace_installed
 * - Updates detecteren en uitvoeren
 * - Verwijdering met cleanup
 */
final class PackageManager
{
    private function downloadPath(): string { return CF_ROOT . '/storage/marketplace/downloads'; }
    private function modulesPath(): string  { return CF_ROOT . '/modules'; }
    private function themesPath(): string   { return CF_ROOT . '/themes'; }

    public function __construct(
        private readonly Connection   $db,
        private readonly CacheManager $cache,
    ) {}

    // ─── INSTALLATIE ─────────────────────────────────────────────────────────

    /**
     * Installeer een package vanuit een ZIP URL.
     * Stappen: download → validate → extract → register → run installer
     *
     * @throws PackageException bij fouten
     */
    public function install(string $packageSlug, string $downloadUrl): InstallResult
    {
        $packageSlug = ManifestValidator::assertSlug($packageSlug);
        $this->ensureDirectories();

        // 1. Download ZIP (SSRF-veilig, met grootte-limiet)
        $zipPath = $this->downloadPath() . '/dl_' . SafeFs::randomSuffix() . '.zip';
        (new SafeDownloader())->fetch($downloadUrl, $zipPath);

        return $this->installZip($zipPath, $packageSlug);
    }

    /**
     * Installeer vanuit een geüploaded ZIP bestand.
     * De slug komt uit het (gevalideerde) manifest, niet uit de bestandsnaam.
     */
    public function installFromUpload(string $tmpPath, string $originalName): InstallResult
    {
        $this->ensureDirectories();

        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
            throw new PackageException('Alleen .zip-bestanden zijn toegestaan.');
        }
        $zipPath = $this->downloadPath() . '/up_' . SafeFs::randomSuffix() . '.zip';
        if (!move_uploaded_file($tmpPath, $zipPath)) {
            throw new PackageException('Upload kon niet worden opgeslagen.');
        }

        return $this->installZip($zipPath, null);
    }

    /**
     * Gemeenschappelijke installatiestappen: inspecteren → valideren → atomisch deployen
     * → registreren → installer draaien. Bij een fout na het deployen wordt de vorige
     * versie teruggezet.
     */
    private function installZip(string $zipPath, ?string $expectedSlug): InstallResult
    {
        $workDir = null;
        try {
            [$manifest, $root, $workDir] = $this->extractAndValidate($zipPath, $expectedSlug);
            $slug = $manifest['slug'];
            $type = $manifest['type'];

            $deploy = $this->deployPackage($slug, $type, $root);
            try {
                $this->registerInstalled($manifest, $deploy['dest']);
                $this->runModuleInstaller($slug, $manifest);
            } catch (\Throwable $e) {
                $this->rollbackDeploy($deploy);
                throw $e instanceof PackageException ? $e : new PackageException('Installatie mislukt: ' . $e->getMessage(), 0, $e);
            }
            $this->commitDeploy($deploy);
        } finally {
            $this->cleanup($zipPath);
            if ($workDir !== null) {
                SafeFs::deleteTree($this->downloadPath(), $workDir);
            }
        }

        $this->cache->delete("marketplace.installed");
        $this->cache->delete("modules.all");

        return new InstallResult(
            success:     true,
            slug:        $slug,
            name:        $manifest['name'],
            version:     $manifest['version'],
            type:        $type,
            installPath: $deploy['dest'],
        );
    }

    // ─── DEÏNSTALLATIE ───────────────────────────────────────────────────────

    /**
     * Verwijder een package volledig.
     * Core-pakketten kunnen niet worden verwijderd.
     */
    public function uninstall(string $slug): bool
    {
        ManifestValidator::assertSlug($slug);
        $installed = $this->getInstalled($slug);
        if (!$installed) {
            throw new PackageException("Package '{$slug}' is niet geïnstalleerd.");
        }

        // Check of het een core module is
        $module = $this->db->fetchOne("SELECT is_core FROM cf_modules WHERE slug = ?", [$slug]);
        if ($module && (bool)$module['is_core']) {
            throw new PackageException("Core module '{$slug}' kan niet worden verwijderd.");
        }

        // Voer uninstall() uit op de module indien mogelijk
        $manifestPath = ($installed['type'] === 'theme' ? $this->themesPath() : $this->modulesPath()) . "/{$slug}/module.json";
        if (file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            $class    = $manifest['class'] ?? null;
            if ($class && class_exists($class)) {
                try {
                    (new $class($this->getApp()))->uninstall();
                } catch (\Throwable $e) {
                    error_log("Uninstall-hook van '{$slug}' mislukt: " . $e->getMessage());
                }
            }
        }

        // Verwijder bestanden — alleen binnen modules/ of themes/, nooit een ander pad uit de DB
        $installPath = $installed['install_path'];
        if ($installPath && is_dir($installPath)) {
            $base = $installed['type'] === 'theme' ? $this->themesPath() : $this->modulesPath();
            SafeFs::deleteTree($base, $installPath);
        }

        // Verwijder uit DB
        $this->db->execute("DELETE FROM cf_marketplace_installed WHERE package_slug = ?", [$slug]);
        $this->db->execute("DELETE FROM cf_modules WHERE slug = ?", [$slug]);

        $this->cache->delete("marketplace.installed");
        $this->cache->delete("modules.all");

        return true;
    }

    // ─── UPDATES ─────────────────────────────────────────────────────────────

    /**
     * Controleer op updates voor alle geïnstalleerde packages.
     * Vergelijkt geïnstalleerde versies met de marketplace catalogus.
     *
     * @return array<string, string> slug → beschikbare versie
     */
    public function checkForUpdates(): array
    {
        return $this->cache->remember('marketplace.updates', 3600, function() {
            $installed = $this->db->fetchAll("SELECT package_slug, version FROM cf_marketplace_installed");
            $updates   = [];

            foreach ($installed as $pkg) {
                $latest = $this->db->fetchOne(
                    "SELECT version FROM cf_marketplace_packages WHERE slug = ?",
                    [$pkg['package_slug']]
                );
                if ($latest && version_compare($latest['version'], $pkg['version'], '>')) {
                    $updates[$pkg['package_slug']] = $latest['version'];
                }
            }

            return $updates;
        });
    }

    /**
     * Update een package naar de nieuwste versie.
     */
    public function update(string $slug): InstallResult
    {
        $package = $this->db->fetchOne(
            "SELECT * FROM cf_marketplace_packages WHERE slug = ?",
            [$slug]
        );

        if (!$package || empty($package['download_url'])) {
            throw new PackageException("Geen download URL beschikbaar voor '{$slug}'.");
        }

        // Geen pre-delete meer: deployPackage() vervangt de map atomisch en zet bij een
        // fout de vorige versie terug.
        return $this->install($slug, $package['download_url']);
    }

    // ─── ENABLE / DISABLE ────────────────────────────────────────────────────

    public function enable(string $slug): void
    {
        $this->db->execute("UPDATE cf_marketplace_installed SET is_enabled = 1 WHERE package_slug = ?", [$slug]);
        $this->db->execute("UPDATE cf_modules SET is_enabled = 1 WHERE slug = ?", [$slug]);
        $this->cache->delete("modules.all");
    }

    public function disable(string $slug): void
    {
        // Core modules kunnen niet worden uitgeschakeld
        $module = $this->db->fetchOne("SELECT is_core FROM cf_modules WHERE slug = ?", [$slug]);
        if ($module && (bool)$module['is_core']) {
            throw new PackageException("Core module '{$slug}' kan niet worden uitgeschakeld.");
        }

        $this->db->execute("UPDATE cf_marketplace_installed SET is_enabled = 0 WHERE package_slug = ?", [$slug]);
        $this->db->execute("UPDATE cf_modules SET is_enabled = 0 WHERE slug = ?", [$slug]);
        $this->cache->delete("modules.all");
    }

    // ─── CATALOGUS ────────────────────────────────────────────────────────────

    /**
     * Haal marketplace catalogus op met optionele filters.
     */
    public function getCatalog(
        string  $type     = '',
        string  $search   = '',
        string  $sortBy   = 'downloads',
        int     $limit    = 20,
        int     $offset   = 0,
    ): array {
        $cacheKey = "marketplace.catalog.{$type}.{$search}.{$sortBy}.{$limit}.{$offset}";
        return $this->cache->remember($cacheKey, 300, function() use ($type, $search, $sortBy, $limit, $offset) {
            $where    = ['1=1'];
            $bindings = [];

            if (!empty($type)) {
                $where[]    = 'type = ?';
                $bindings[] = $type;
            }

            if (!empty($search)) {
                $where[]    = '(name LIKE ? OR description LIKE ? OR slug LIKE ?)';
                $term       = '%' . $search . '%';
                $bindings   = [...$bindings, $term, $term, $term];
            }

            $orderMap = [
                'downloads' => 'downloads DESC',
                'name'      => 'name ASC',
                'newest'    => 'created_at DESC',
                'rating'    => 'rating DESC',
                'featured'  => 'is_featured DESC, downloads DESC',
            ];
            $order = $orderMap[$sortBy] ?? 'downloads DESC';

            $sql = "SELECT * FROM cf_marketplace_packages WHERE " . implode(' AND ', $where) . " ORDER BY {$order} LIMIT ? OFFSET ?";

            return $this->db->fetchAll($sql, [...$bindings, $limit, $offset]);
        });
    }

    /**
     * Geef alle geïnstalleerde packages terug.
     */
    public function getInstalledPackages(): array
    {
        return $this->cache->remember('marketplace.installed', 60, function() {
            return $this->db->fetchAll(
                "SELECT mi.*, mp.name, mp.description, mp.icon_url, mp.is_featured, mp.is_verified, mp.downloads
                 FROM cf_marketplace_installed mi
                 LEFT JOIN cf_marketplace_packages mp ON mp.slug = mi.package_slug
                 ORDER BY mi.type, mi.package_slug"
            );
        });
    }

    public function getInstalled(string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM cf_marketplace_installed WHERE package_slug = ?",
            [$slug]
        );
    }

    public function isInstalled(string $slug): bool
    {
        return $this->getInstalled($slug) !== null;
    }

    // ─── PRIVATE HELPERS ─────────────────────────────────────────────────────

    /**
     * Inspecteer, pak uit in een unieke werkmap en valideer het manifest.
     *
     * @return array{0:array<string,mixed>,1:string,2:string} [manifest, bronmap, werkmap]
     * @throws PackageException
     */
    private function extractAndValidate(string $zipPath, ?string $expectedSlug): array
    {
        $workDir = $this->downloadPath() . '/work_' . SafeFs::randomSuffix();
        if (!mkdir($workDir, 0755, true) && !is_dir($workDir)) {
            throw new PackageException('Kan werkmap niet aanmaken.');
        }

        try {
            $zip = new \ZipArchive();
            $result = $zip->open($zipPath);
            if ($result !== true) {
                throw new PackageException("Kan ZIP niet openen (code: {$result})");
            }
            try {
                (new ZipInspector())->inspect($zip);   // weigert vóór uitpakken
                if (!$zip->extractTo($workDir)) {
                    throw new PackageException('Uitpakken mislukt.');
                }
            } finally {
                $zip->close();
            }

            // Manifest in de root of in één submap
            $manifestPath = null;
            foreach (['module.json', 'theme.json', '*/module.json', '*/theme.json'] as $pattern) {
                $found = glob($workDir . '/' . $pattern);
                if (!empty($found)) {
                    $manifestPath = $found[0];
                    break;
                }
            }
            if ($manifestPath === null) {
                throw new PackageException('Geen module.json of theme.json gevonden in ZIP');
            }

            $raw = json_decode((string)file_get_contents($manifestPath), true);
            if (!is_array($raw)) {
                throw new PackageException('Manifest is geen geldige JSON.');
            }
            $manifest = (new ManifestValidator())->validate($raw, $expectedSlug);

            $expectedFile = $manifest['type'] === 'theme' ? 'theme.json' : 'module.json';
            if (basename($manifestPath) !== $expectedFile) {
                throw new PackageException("Type '{$manifest['type']}' vraagt om {$expectedFile}.");
            }

            // Bronmap = map van het manifest; moet binnen de werkmap liggen
            $manifestDir = dirname($manifestPath);
            $root = $manifestDir === $workDir ? $workDir : SafeFs::assertInside($workDir, $manifestDir);
        } catch (\Throwable $e) {
            SafeFs::deleteTree($this->downloadPath(), $workDir);
            throw $e instanceof PackageException ? $e : new PackageException($e->getMessage(), 0, $e);
        }

        return [$manifest, $root, $workDir];
    }

    /**
     * Zet het pakket klaar via een tijdelijke map en verwissel dat atomisch met de bestaande
     * installatie (die als back-up blijft staan tot commitDeploy()).
     *
     * @return array{dest:string,backup:?string,base:string}
     */
    private function deployPackage(string $slug, string $type, string $sourceRoot): array
    {
        ManifestValidator::assertSlug($slug);
        $base = realpath($type === 'theme' ? $this->themesPath() : $this->modulesPath());
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

    /** Zet de vorige versie terug (of verwijder de nieuwe als er geen vorige was). */
    private function rollbackDeploy(array $deploy): void
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

    private function commitDeploy(array $deploy): void
    {
        if ($deploy['backup'] !== null) {
            SafeFs::deleteTree($deploy['base'], $deploy['backup']);
        }
    }

    private function registerInstalled(array $manifest, string $installPath): void
    {
        $slug    = $manifest['slug'];
        $type    = $manifest['type'] ?? 'module';
        $version = $manifest['version'] ?? '1.0.0';

        // cf_marketplace_installed bijwerken
        $this->db->execute(
            "INSERT INTO cf_marketplace_installed (package_slug, type, version, install_path)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE version = VALUES(version), installed_at = NOW(), install_path = VALUES(install_path)",
            [$slug, $type, $version, $installPath]
        );

        // cf_modules bijwerken als het een module is
        if ($type === 'module') {
            $this->db->execute(
                "INSERT INTO cf_modules (slug, name, version, author, description, is_enabled)
                 VALUES (?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE version = VALUES(version), is_enabled = 1",
                [$slug, $manifest['name'] ?? $slug, $version, $manifest['author'] ?? '', $manifest['description'] ?? '']
            );
        }
    }

    private function runModuleInstaller(string $slug, array $manifest): void
    {
        $class = $manifest['class'] ?? null;
        if (!$class || !class_exists($class)) return;

        try {
            $moduleFile = $this->modulesPath() . "/{$slug}/src/" . basename(str_replace('\\', '/', $class)) . '.php';
            if (file_exists($moduleFile)) require_once $moduleFile;

            if (class_exists($class)) {
                $app      = $this->getApp();
                $instance = new $class($app);

                // Kritiek: geen enkele ModuleInterface-implementatie in deze
                // codebase heeft een eigen __construct() — de $app-parameter
                // hierboven wordt dus door PHP genegeerd (klasse zonder
                // constructor accepteert stilzwijgend extra args). `$this->app`
                // wordt UITSLUITEND gezet door boot(Application $app). Zonder
                // eerst boot() aan te roepen crasht install() op een
                // ongeïnitialiseerde typed property zodra het `$this->app`
                // aanraakt (bv. DiscordModule::install() → Connection ophalen)
                // — een crash die deze try/catch tot nu toe stil slikte, dus
                // de module-specifieke tabellen (bv. cf_discord_role_mapping)
                // werden via déze marketplace-flow nooit daadwerkelijk
                // aangemaakt. boot() vóór install() aanroepen lost dit op.
                $instance->boot($app);
                $instance->install();
            }
        } catch (\Throwable $e) {
            // Log maar gooi geen exception — installatie is al geslaagd
            error_log("Module installer fout voor {$slug}: " . $e->getMessage());
        }
    }

    private function getApp(): mixed
    {
        return \CommunityFusion\Core\Application::getInstance();
    }

    private function ensureDirectories(): void
    {
        foreach ([$this->downloadPath(), $this->modulesPath(), $this->themesPath()] as $dir) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
        }
    }

    private function cleanup(string $zipPath): void
    {
        if (is_file($zipPath)) {
            unlink($zipPath);
        }
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: PackageManager.php | Role: Core | Version: 1.1.0             ║
// ║  Created: 2026-06-06 | Status: New                                  ║
// ║  Notes: Download, validate, extract, deploy, register, update       ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ        ║
// ╚══════════════════════════════════════════════════════════════════════╝
