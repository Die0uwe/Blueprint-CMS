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
        $this->ensureDirectories();

        // 1. Download ZIP
        $zipPath = $this->download($packageSlug, $downloadUrl);

        // 2. Valideer en extraheer
        $manifest = $this->extractAndValidate($zipPath, $packageSlug);

        // 3. Kopieer naar juiste locatie
        $installPath = $this->deployPackage($packageSlug, $manifest);

        // 4. Registreer in DB
        $this->registerInstalled($manifest, $installPath);

        // 5. Voer module installer uit indien aanwezig
        $this->runModuleInstaller((string) $manifest['slug'], $manifest);

        // 6. Verwijder tijdelijke bestanden
        $this->cleanup($zipPath);

        // 7. Invalideer relevante caches
        $this->cache->delete("marketplace.installed");
        $this->cache->delete("modules.all");

        return new InstallResult(
            success:     true,
            slug:        $packageSlug,
            name:        $manifest['name'] ?? $packageSlug,
            version:     $manifest['version'] ?? '1.0.0',
            type:        $manifest['type'] ?? 'module',
            installPath: $installPath,
        );
    }

    /**
     * Installeer vanuit een geüploaded ZIP bestand.
     */
    public function installFromUpload(string $tmpPath, string $originalName): InstallResult
    {
        $this->ensureDirectories();

        // Bepaal slug uit bestandsnaam (my-module-v1.0.zip → my-module)
        $slug = preg_replace('/[-_]v?\d[\d.]*$/', '', pathinfo($originalName, PATHINFO_FILENAME));
        $slug = strtolower(preg_replace('/[^a-z0-9-]/', '-', $slug));

        $zipPath = $this->downloadPath() . '/' . $slug . '.zip';
        if (!move_uploaded_file($tmpPath, $zipPath)) {
            throw new PackageException('Kan het geüploade ZIP-bestand niet opslaan');
        }

        $manifest    = $this->extractAndValidate($zipPath, $slug);
        $installPath = $this->deployPackage($slug, $manifest);
        $this->registerInstalled($manifest, $installPath);
        $this->runModuleInstaller((string) $manifest['slug'], $manifest);
        $this->cleanup($zipPath);

        $this->cache->delete("marketplace.installed");
        $this->cache->delete("modules.all");

        return new InstallResult(true, $slug, $manifest['name'] ?? $slug, $manifest['version'] ?? '1.0.0', $manifest['type'] ?? 'module', $installPath);
    }

    // ─── DEÏNSTALLATIE ───────────────────────────────────────────────────────

    /**
     * Verwijder een package volledig.
     * Core-pakketten kunnen niet worden verwijderd.
     */
    public function uninstall(string $slug): bool
    {
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
                } catch (\Throwable) {}
            }
        }

        // Verwijder bestanden
        $installPath = $installed['install_path'];
        if ($installPath && is_dir($installPath)) {
            $this->deleteDirectory($installPath);
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

        // Verwijder bestaande installatie (zonder uninstall hook)
        $installed = $this->getInstalled($slug);
        if ($installed && $installed['install_path'] && is_dir($installed['install_path'])) {
            $this->deleteDirectory($installed['install_path']);
        }

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

    private function download(string $slug, string $url): string
    {
        $zipPath = $this->downloadPath() . "/{$slug}.zip";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $content = curl_exec($ch);
        $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error   = curl_error($ch);
        curl_close($ch);

        if ($error || $status !== 200) {
            throw new PackageException("Download mislukt voor '{$slug}': HTTP {$status} — {$error}");
        }

        if (file_put_contents($zipPath, $content) === false) {
            throw new PackageException("Kan ZIP niet opslaan naar {$zipPath}");
        }

        return $zipPath;
    }

    private function extractAndValidate(string $zipPath, string $slug): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
            throw new PackageException("Ongeldige package-slug '{$slug}' (alleen a-z, 0-9 en '-')");
        }
        $extractTo = $this->downloadPath() . "/{$slug}_extracted";

        // Verwijder eventuele vorige extractie
        if (is_dir($extractTo)) $this->deleteDirectory($extractTo);
        mkdir($extractTo, 0755, true);

        $zip = new \ZipArchive();
        $result = $zip->open($zipPath);
        if ($result !== true) {
            throw new PackageException("Kan ZIP niet openen (code: {$result})");
        }

        // Veiligheidscheck: geen path traversal in ZIP entries
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();
                throw new PackageException("Onveilige ZIP entry gedetecteerd: {$name}");
            }
        }

        $zip->extractTo($extractTo);
        $zip->close();

        // Vind manifest — kan in root of in submap zitten
        $manifest = null;
        $manifestPath = null;
        foreach (['module.json', 'theme.json', '*/module.json', '*/theme.json'] as $pattern) {
            $found = glob($extractTo . '/' . $pattern);
            if (!empty($found)) {
                $manifestPath = $found[0];
                $decoded      = json_decode((string) file_get_contents($manifestPath), true);
                $manifest     = is_array($decoded) ? $decoded : null;
                // theme.json heeft vaak geen 'type'-veld: afleiden uit de bestandsnaam.
                if ($manifest !== null && basename($manifestPath) === 'theme.json') {
                    $manifest['type'] = 'theme';
                }
                break;
            }
        }

        if (!$manifest) {
            $this->deleteDirectory($extractTo);
            throw new PackageException("Geen module.json of theme.json gevonden in ZIP");
        }

        // Valideer verplichte velden
        foreach (['slug', 'name', 'version'] as $field) {
            if (empty($manifest[$field])) {
                $this->deleteDirectory($extractTo);
                throw new PackageException("Verplicht veld '{$field}' ontbreekt in manifest");
            }
        }

        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', (string) $manifest['slug']) !== 1
            || !in_array($manifest['type'] ?? 'module', ['module', 'theme'], true)) {
            $this->deleteDirectory($extractTo);
            throw new PackageException("Ongeldige slug of type in manifest");
        }

        // Sla extractie-pad op in manifest voor deployPackage()
        $manifest['_extracted_path'] = dirname($manifestPath);

        return $manifest;
    }

    /**
     * Kopieer de uitgepakte package naar modules/ of themes/.
     * De bronmap komt uit het manifest (`_extracted_path`): die kan een submap van de ZIP zijn,
     * en het manifest heet module.json of theme.json.
     */
    private function deployPackage(string $slug, array $manifest): string
    {
        $type       = ($manifest['type'] ?? 'module') === 'theme' ? 'theme' : 'module';
        $sourcePath = $this->downloadPath() . "/{$slug}_extracted";
        // Bestemming volgt het (gevalideerde) manifest, zodat map en registratie nooit uiteenlopen.
        $destSlug   = (string) ($manifest['slug'] ?? $slug);
        $destPath   = ($type === 'theme' ? $this->themesPath() : $this->modulesPath()) . "/{$destSlug}";

        $realSource = (string) ($manifest['_extracted_path'] ?? $sourcePath);
        if (!is_dir($realSource)) {
            $realSource = $sourcePath;
        }

        // Verwijder bestaande installatie pas nu de nieuwe bron zeker bestaat
        if (is_dir($destPath)) {
            $this->deleteDirectory($destPath);
        }

        $this->copyDirectory($realSource, $destPath);
        $this->deleteDirectory($sourcePath);

        return $destPath;
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

    private function copyDirectory(string $from, string $to): void
    {
        if (!is_dir($to)) mkdir($to, 0755, true);

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            $target = $to . '/' . $items->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($target)) mkdir($target, 0755, true);
            } else {
                copy($item->getRealPath(), $target);
            }
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) return;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($path);
    }

    private function cleanup(string $zipPath): void
    {
        if (file_exists($zipPath)) unlink($zipPath);
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: PackageManager.php | Role: Core | Version: 1.0.0             ║
// ║  Created: 2026-06-06 | Status: New                                  ║
// ║  Notes: Download, validate, extract, deploy, register, update       ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ        ║
// ╚══════════════════════════════════════════════════════════════════════╝
