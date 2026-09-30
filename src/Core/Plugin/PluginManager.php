<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Plugin;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Block\BlockRegistry;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Hook\HookManager;
use CommunityFusion\Core\Marketplace\AtomicDeploy;
use CommunityFusion\Core\Marketplace\PackageException;
use CommunityFusion\Core\Marketplace\SafeFs;
use CommunityFusion\Core\Marketplace\ZipInspector;
use CommunityFusion\Core\Security\Crypto;

/**
 * PluginManager — ontdekt, activeert en laadt plugins uit plugins/{slug}/plugin.json.
 *
 * Staat los van core-modules (modules/). Hergebruikt de geharde bouwstenen van de
 * Marketplace (ZipInspector, SafeFs, AtomicDeploy). Elke statuswijziging wordt in
 * cf_audit_log vastgelegd. Een fout in één plugin mag de site nooit onderuit halen.
 */
final class PluginManager
{
    public const MANIFEST = 'plugin.json';

    /** @var array<string,string> slug → foutmelding van plugins die niet konden laden (deze request) */
    private array $loadErrors = [];

    public function __construct(
        private readonly Connection $db,
        private readonly string $pluginsDir,
        private readonly string $cmsVersion,
        private readonly ?AuditLogger $audit = null,
    ) {}

    // ─── ONTDEKKEN ───────────────────────────────────────────────────────────

    /**
     * Scan plugins/ en vergelijk met cf_plugins.
     *
     * @return list<array{slug:string,name:string,version:string,status:string,active:bool,errors:list<string>,needs_migration:bool,manifest:?array<string,mixed>}>
     *   status: active | inactive | discovered | invalid | error
     */
    public function discover(): array
    {
        $rows = [];
        foreach ($this->db->fetchAll('SELECT slug, name, version, active FROM cf_plugins') as $r) {
            $rows[$r['slug']] = $r;
        }

        $result = [];
        foreach ($this->listDirs() as $slug) {
            $errors = [];
            $manifest = null;
            try {
                $manifest = $this->readManifest($slug);
            } catch (PackageException $e) {
                $errors[] = $e->getMessage();
            }
            $row = $rows[$slug] ?? null;
            $active = $row !== null && (int)$row['active'] === 1;
            if ($manifest === null) {
                $status = 'invalid';
            } elseif (isset($this->loadErrors[$slug])) {
                $status = 'error';
                $errors[] = $this->loadErrors[$slug];
            } else {
                $status = $active ? 'active' : ($row !== null ? 'inactive' : 'discovered');
            }
            $result[] = [
                'slug'            => $slug,
                'name'            => (string)($manifest['name'] ?? $row['name'] ?? $slug),
                'version'         => (string)($manifest['version'] ?? $row['version'] ?? ''),
                'status'          => $status,
                'active'          => $active,
                'errors'          => $errors,
                'needs_migration' => $manifest !== null && $this->pendingMigrations($slug) !== [],
                'manifest'        => $manifest,
            ];
            unset($rows[$slug]);
        }
        // In de database, maar de map is weg
        foreach ($rows as $slug => $row) {
            $result[] = ['slug' => (string)$slug, 'name' => (string)$row['name'], 'version' => (string)$row['version'],
                'status' => 'invalid', 'active' => false, 'errors' => ['Map plugins/' . $slug . ' ontbreekt.'],
                'needs_migration' => false, 'manifest' => null];
        }
        return $result;
    }

    /** @return array<string,mixed> gevalideerd manifest @throws PackageException */
    public function readManifest(string $slug): array
    {
        $dir = $this->dirOf($slug);
        $file = $dir . '/' . self::MANIFEST;
        if (!is_file($file) || is_link($file)) {
            throw new PackageException('plugin.json ontbreekt.');
        }
        $raw = json_decode((string)file_get_contents($file), true);
        if (!is_array($raw)) {
            throw new PackageException('plugin.json is geen geldige JSON.');
        }
        return PluginManifest::validate($raw, $slug, $this->cmsVersion);
    }

    // ─── STATUS ──────────────────────────────────────────────────────────────

    /** @param array{id?:?int,username?:?string} $actor */
    public function activate(string $slug, array $actor = []): void
    {
        $manifest = $this->readManifest($slug);

        // Migraties eerst: mislukt dat, dan wordt de plugin niet actief.
        $applied = $this->migrate($slug);

        foreach ($manifest['permissions'] ?? [] as $perm) {
            $this->db->execute(
                'INSERT IGNORE INTO cf_permissions (name, `group`, description) VALUES (?, ?, ?)',
                [$perm, 'plugins', 'Plugin ' . $manifest['name']]
            );
        }
        $this->db->execute(
            'INSERT INTO cf_plugins (slug, name, version, active) VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), active = 1',
            [$slug, $manifest['name'], $manifest['version']]
        );
        $this->audit('plugin.activate', $actor, ['slug' => $slug, 'version' => $manifest['version'], 'migrations' => $applied]);
    }

    /** @param array{id?:?int,username?:?string} $actor */
    public function deactivate(string $slug, array $actor = []): void
    {
        ManifestSlug::assert($slug);
        $this->db->execute('UPDATE cf_plugins SET active = 0 WHERE slug = ?', [$slug]);
        $this->db->execute('UPDATE cf_modules SET is_enabled = 0 WHERE slug = ?', ['plugin-' . $slug]);
        $this->audit('plugin.deactivate', $actor, ['slug' => $slug]);
    }

    /**
     * Verwijder een plugin. Data (tabellen `cf_plg_{slug}_*`) blijft standaard staan;
     * met $dropData wordt ook die verwijderd.
     *
     * @param array{id?:?int,username?:?string} $actor
     */
    public function uninstall(string $slug, bool $dropData = false, array $actor = []): void
    {
        ManifestSlug::assert($slug);
        $manifest = null;
        try {
            $manifest = $this->readManifest($slug);
        } catch (PackageException) {
            // kapotte plugin moet ook verwijderd kunnen worden
        }

        $this->db->execute('DELETE FROM cf_plugins WHERE slug = ?', [$slug]);
        $this->db->execute('DELETE FROM cf_modules WHERE slug = ?', ['plugin-' . $slug]);
        foreach ($manifest['permissions'] ?? [] as $perm) {
            $this->db->execute('DELETE FROM cf_permissions WHERE name = ? AND `group` = ?', [$perm, 'plugins']);
        }
        $dropped = [];
        if ($dropData) {
            $dropped = $this->dropTables($slug);
            $this->db->execute('DELETE FROM cf_plugin_migrations WHERE slug = ?', [$slug]);
        }

        $dir = $this->pluginsDir . '/' . $slug;
        if (is_dir($dir)) {
            SafeFs::deleteTree($this->pluginsDir, $dir);
        }
        $this->audit('plugin.uninstall', $actor, ['slug' => $slug, 'drop_data' => $dropData, 'dropped_tables' => $dropped]);
    }

    // ─── MIGRATIES ───────────────────────────────────────────────────────────

    /**
     * Voer openstaande migraties uit (plugins/{slug}/migrations/NNN_naam.sql, op volgorde).
     * Elk statement wordt door PluginSqlGuard gecontroleerd vóór er iets wordt uitgevoerd.
     *
     * @return list<string> Namen van de zojuist uitgevoerde migraties
     * @throws PackageException
     */
    public function migrate(string $slug): array
    {
        ManifestSlug::assert($slug);
        $guard = new PluginSqlGuard();
        $pending = $this->pendingMigrations($slug);

        // Eerst ALLES controleren, dan pas uitvoeren: geen half uitgevoerde set door een foute laatste file.
        $prepared = [];
        foreach ($pending as $name => $path) {
            $prepared[$name] = $guard->check((string)file_get_contents($path), $slug);
        }

        $applied = [];
        foreach ($prepared as $name => $statements) {
            foreach ($statements as $sql) {
                try {
                    $this->db->execute($sql);
                } catch (\PDOException $e) {
                    throw new PackageException("Migratie {$name} mislukt: " . $e->getMessage(), 0, $e);
                }
            }
            $this->db->execute('INSERT IGNORE INTO cf_plugin_migrations (slug, migration) VALUES (?, ?)', [$slug, $name]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** @return array<string,string> bestandsnaam → pad, nog niet uitgevoerd */
    public function pendingMigrations(string $slug): array
    {
        $dir = $this->dirOf($slug) . '/migrations';
        if (!is_dir($dir)) {
            return [];
        }
        $done = [];
        foreach ($this->db->fetchAll('SELECT migration FROM cf_plugin_migrations WHERE slug = ?', [$slug]) as $r) {
            $done[$r['migration']] = true;
        }
        $files = [];
        foreach (scandir($dir) ?: [] as $f) {
            $path = $dir . '/' . $f;
            if ($f === '.' || $f === '..') {
                continue;
            }
            if (is_link($path) || !is_file($path) || !preg_match('/^\d{3,}_[a-z0-9_]+\.sql$/', $f)) {
                throw new PackageException("Ongeldig bestand in migrations/: {$f} (verwacht 001_naam.sql).");
            }
            if (!isset($done[$f])) {
                $files[$f] = $path;
            }
        }
        ksort($files);
        return $files;
    }

    // ─── LADEN (elke request) ────────────────────────────────────────────────

    /**
     * Laad alle actieve plugins. Wordt aangeroepen vanuit Application::boot() ná loadModules().
     * Een fout in één plugin wordt gelogd en geïsoleerd; de rest blijft werken.
     */
    public function loadActive(HookManager $hooks, BlockRegistry $blocks): void
    {
        try {
            $rows = $this->db->fetchAll('SELECT slug, settings_json FROM cf_plugins WHERE active = 1 ORDER BY slug');
        } catch (\Throwable) {
            return; // tabel ontbreekt (nog niet gemigreerd) of DB niet beschikbaar
        }
        foreach ($rows as $row) {
            $slug = (string)$row['slug'];
            try {
                $this->loadOne($slug, $this->decodeSettings($row['settings_json'] ?? null, $slug), $hooks, $blocks);
            } catch (\Throwable $e) {
                $this->loadErrors[$slug] = $e->getMessage();
                error_log("Plugin '{$slug}' kon niet laden: " . $e->getMessage());
            }
        }
    }

    /** @param array<string,mixed> $settings */
    private function loadOne(string $slug, array $settings, HookManager $hooks, BlockRegistry $blocks): void
    {
        $manifest = $this->readManifest($slug);
        $dir = SafeFs::assertInside($this->pluginsDir, $this->pluginsDir . '/' . $slug);

        if (!empty($manifest['autoload'])) {
            PluginAutoloader::register($dir, $manifest['autoload']);
        }
        $context = new PluginContext($slug, $dir, $manifest, $hooks, $blocks, $settings);

        foreach ($manifest['routes'] ?? [] as $relative) {
            $file = realpath($dir . '/' . $relative);
            if ($file === false || !str_starts_with($file, $dir . DIRECTORY_SEPARATOR)) {
                throw new PackageException("Route-bestand niet gevonden of buiten de plugin: {$relative}");
            }
            $hooks->addAction('router.routes', static function ($router) use ($file, $context): void {
                (static function ($router, $plugin) use ($file): void {
                    require $file;
                })($router, $context);
            });
        }

        if (isset($manifest['class'])) {
            $class = (string)$manifest['class'];
            if (!class_exists($class) || !is_subclass_of($class, PluginInterface::class)) {
                throw new PackageException("Klasse {$class} ontbreekt of implementeert PluginInterface niet.");
            }
            (new $class())->boot($context);
        }

        if (!empty($manifest['blocks'])) {
            $ns = (string)array_key_first($manifest['autoload']);
            foreach ($manifest['blocks'] as $short) {
                $fqcn = $ns . $short;
                if (!class_exists($fqcn) || !is_subclass_of($fqcn, \CommunityFusion\Blocks\BlockInterface::class)) {
                    throw new PackageException("Block-klasse {$fqcn} ontbreekt of implementeert BlockInterface niet.");
                }
                $blocks->register(new $fqcn());
            }
            $blocks->syncTypesToDatabase($this->ensureModuleRow($slug, $manifest));
        }
    }

    /** cf_block_types.module_id is verplicht; plugins krijgen daarom een eigen (verborgen) module-rij. */
    private function ensureModuleRow(string $slug, array $manifest): int
    {
        $this->db->execute(
            'INSERT INTO cf_modules (slug, name, version, author, description, is_enabled)
             VALUES (?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), is_enabled = 1',
            ['plugin-' . $slug, $manifest['name'], $manifest['version'], (string)($manifest['author'] ?? ''), (string)($manifest['description'] ?? '')]
        );
        $row = $this->db->fetchOne('SELECT id FROM cf_modules WHERE slug = ?', ['plugin-' . $slug]);
        return (int)($row['id'] ?? 0);
    }

    // ─── INSTELLINGEN ────────────────────────────────────────────────────────

    /** @return array<string,mixed> instellingen (versleutelde velden ontsleuteld) */
    public function settings(string $slug): array
    {
        $row = $this->db->fetchOne('SELECT settings_json FROM cf_plugins WHERE slug = ?', [$slug]);
        return $this->decodeSettings($row['settings_json'] ?? null, $slug);
    }

    /**
     * Sla instellingen op. Alleen sleutels uit het manifest worden geaccepteerd; waarden worden
     * naar het gedeclareerde type gebracht. Type 'encrypted' wordt met Crypto versleuteld;
     * een leeg veld laat de bestaande waarde staan.
     *
     * @param array<string,mixed> $input
     * @param array{id?:?int,username?:?string} $actor
     */
    public function saveSettings(string $slug, array $input, array $actor = []): void
    {
        $manifest = $this->readManifest($slug);
        $stored = $this->rawSettings($slug);
        foreach ($manifest['settings'] ?? [] as $def) {
            $key = $def['key'];
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $v = $input[$key];
            switch ($def['type']) {
                case 'bool':
                    $stored[$key] = filter_var($v, FILTER_VALIDATE_BOOLEAN) ? true : false;
                    break;
                case 'int':
                    if (!is_numeric($v)) {
                        throw new PackageException("Instelling '{$key}' moet een getal zijn.");
                    }
                    $stored[$key] = (int)$v;
                    break;
                case 'json':
                    $decoded = is_string($v) ? json_decode($v, true) : $v;
                    if ($decoded === null && $v !== 'null') {
                        throw new PackageException("Instelling '{$key}' is geen geldige JSON.");
                    }
                    $stored[$key] = $decoded;
                    break;
                case 'encrypted':
                    if (is_string($v) && $v !== '') {
                        $stored[$key] = Crypto::encrypt($v);
                    }
                    break;
                default:
                    if (!is_scalar($v) || mb_strlen((string)$v) > 5000) {
                        throw new PackageException("Instelling '{$key}' is ongeldig.");
                    }
                    $stored[$key] = (string)$v;
            }
        }
        $this->db->execute('UPDATE cf_plugins SET settings_json = ? WHERE slug = ?', [json_encode($stored, JSON_UNESCAPED_UNICODE), $slug]);
        $this->audit('plugin.settings', $actor, ['slug' => $slug, 'keys' => array_keys($input)]);
    }

    /** @return array<string,mixed> */
    private function rawSettings(string $slug): array
    {
        $row = $this->db->fetchOne('SELECT settings_json FROM cf_plugins WHERE slug = ?', [$slug]);
        $decoded = json_decode((string)($row['settings_json'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed> */
    private function decodeSettings(?string $json, string $slug): array
    {
        $settings = json_decode((string)$json, true);
        $settings = is_array($settings) ? $settings : [];
        try {
            foreach ($this->readManifest($slug)['settings'] ?? [] as $def) {
                if ($def['type'] === 'encrypted' && isset($settings[$def['key']]) && is_string($settings[$def['key']])) {
                    $settings[$def['key']] = Crypto::decrypt($settings[$def['key']]);
                }
            }
        } catch (\Throwable) {
            return []; // manifest onleesbaar: geef nooit ruwe (versleutelde) waarden vrij
        }
        return $settings;
    }

    // ─── UPLOAD ──────────────────────────────────────────────────────────────

    /**
     * Installeer een plugin uit een geüploade ZIP (inspectie → valideren → atomisch deployen).
     * De plugin wordt NIET automatisch geactiveerd.
     *
     * @param array{id?:?int,username?:?string} $actor
     * @return string slug
     */
    public function installFromUpload(string $tmpPath, string $originalName, array $actor = []): string
    {
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
            throw new PackageException('Alleen .zip-bestanden zijn toegestaan.');
        }
        if (!is_dir($this->pluginsDir) && !mkdir($this->pluginsDir, 0755, true) && !is_dir($this->pluginsDir)) {
            throw new PackageException('Kan plugins/ niet aanmaken.');
        }
        $work = sys_get_temp_dir() . '/bp-plugin-' . SafeFs::randomSuffix();
        if (!mkdir($work, 0700, true)) {
            throw new PackageException('Kan werkmap niet aanmaken.');
        }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmpPath) !== true) {
                throw new PackageException('Kan ZIP niet openen.');
            }
            try {
                (new ZipInspector())->inspect($zip);
                if (!$zip->extractTo($work)) {
                    throw new PackageException('Uitpakken mislukt.');
                }
            } finally {
                $zip->close();
            }

            $manifestFile = glob($work . '/' . self::MANIFEST)[0] ?? (glob($work . '/*/' . self::MANIFEST)[0] ?? null);
            if ($manifestFile === null) {
                throw new PackageException('Geen plugin.json gevonden in de ZIP.');
            }
            $raw = json_decode((string)file_get_contents($manifestFile), true);
            if (!is_array($raw)) {
                throw new PackageException('plugin.json is geen geldige JSON.');
            }
            $manifest = PluginManifest::validate($raw, null, $this->cmsVersion);
            $slug = $manifest['slug'];
            $root = dirname($manifestFile);
            $root = $root === $work ? $work : SafeFs::assertInside($work, $root);

            // Migratiebestanden alvast controleren: liever nu weigeren dan bij het activeren.
            $guard = new PluginSqlGuard();
            foreach (glob($root . '/migrations/*') ?: [] as $m) {
                if (!preg_match('/^\d{3,}_[a-z0-9_]+\.sql$/', basename($m)) || is_link($m)) {
                    throw new PackageException('Ongeldig bestand in migrations/: ' . basename($m));
                }
                $guard->check((string)file_get_contents($m), $slug);
            }

            $deploy = AtomicDeploy::deploy($this->pluginsDir, $slug, $root);
            AtomicDeploy::commit($deploy);
        } finally {
            if (is_dir($work)) {
                SafeFs::deleteTree(sys_get_temp_dir(), $work);
            }
        }
        $this->audit('plugin.install', $actor, ['slug' => $slug, 'version' => $manifest['version']]);
        return $slug;
    }

    // ─── HULP ────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function listDirs(): array
    {
        if (!is_dir($this->pluginsDir)) {
            return [];
        }
        $out = [];
        foreach (scandir($this->pluginsDir) ?: [] as $f) {
            if ($f[0] === '.' || is_link($this->pluginsDir . '/' . $f) || !is_dir($this->pluginsDir . '/' . $f)) {
                continue;
            }
            if (preg_match(\CommunityFusion\Core\Marketplace\ManifestValidator::SLUG_PATTERN, $f)) {
                $out[] = $f;
            }
        }
        sort($out);
        return $out;
    }

    private function dirOf(string $slug): string
    {
        ManifestSlug::assert($slug);
        return $this->pluginsDir . '/' . $slug;
    }

    /** @return list<string> verwijderde tabellen */
    private function dropTables(string $slug): array
    {
        $prefix = PluginSqlGuard::tablePrefix($slug);
        $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix) . '%';
        $tables = $this->db->fetchAll(
            'SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?',
            [$like]
        );
        $dropped = [];
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $row) {
                $name = (string)$row['t'];
                if (str_starts_with($name, $prefix) && preg_match('/^[a-z0-9_]+$/', $name)) {
                    $this->db->execute("DROP TABLE IF EXISTS `{$name}`");
                    $dropped[] = $name;
                }
            }
        } finally {
            $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');
        }
        return $dropped;
    }

    /** @param array{id?:?int,username?:?string} $actor @param array<string,mixed> $context */
    private function audit(string $action, array $actor, array $context): void
    {
        try {
            $this->audit?->log($action, $actor['id'] ?? null, $actor['username'] ?? null, $context);
        } catch (\Throwable $e) {
            error_log("Audit-log voor {$action} mislukt: " . $e->getMessage());
        }
    }
}
