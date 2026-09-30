<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Integration;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Block\BlockRegistry;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Hook\HookManager;
use CommunityFusion\Core\Marketplace\PackageException;
use CommunityFusion\Core\Plugin\PluginAutoloader;
use CommunityFusion\Core\Plugin\PluginManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Draait alleen met een wegwerpdatabase waarin schema.sql is geïmporteerd:
 *   BP_TEST_DB="bp_test" BP_TEST_USER=bp BP_TEST_PASS=... [BP_TEST_HOST=127.0.0.1] composer test
 * LET OP: de test maakt en verwijdert tabellen `cf_plg_*` en rijen in cf_plugins — gebruik nooit een echte site-database.
 */
final class PluginManagerTest extends TestCase
{
    private Connection $db;
    private string $dir;
    private PluginManager $pm;

    protected function setUp(): void
    {
        $name = getenv('BP_TEST_DB');
        if ($name === false || $name === '') {
            $this->markTestSkipped('BP_TEST_DB niet gezet.');
        }
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('k', 32));
        $this->db = new Connection([
            'host' => getenv('BP_TEST_HOST') ?: '127.0.0.1', 'name' => $name,
            'user' => (string)getenv('BP_TEST_USER'), 'password' => (string)getenv('BP_TEST_PASS'),
        ]);
        foreach (['cf_plugins', 'cf_plugin_migrations'] as $t) {
            $this->db->execute("DELETE FROM {$t}");
        }
        $this->db->execute("DELETE FROM cf_modules WHERE slug LIKE 'plugin-%'");
        $this->db->execute("DELETE FROM cf_audit_log WHERE action LIKE 'plugin.%'");
        $this->dropPluginTables();

        $this->dir = sys_get_temp_dir() . '/bp-plugins-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
        $this->pm = new PluginManager($this->db, $this->dir, '1.29.0', new AuditLogger($this->db));
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->dropPluginTables();
            $this->db->execute("DELETE FROM cf_plugins");
            $this->db->execute("DELETE FROM cf_plugin_migrations");
            $this->db->execute("DELETE FROM cf_permissions WHERE name LIKE 'tp-%'");
        }
        PluginAutoloader::reset();
        if (isset($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function dropPluginTables(): void
    {
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->fetchAll("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'cf\\_plg\\_%'") as $r) {
            $this->db->execute('DROP TABLE IF EXISTS `' . $r['t'] . '`');
        }
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @param array<string,string> $files */
    private function plugin(string $slug, array $manifest = [], array $files = []): void
    {
        $base = $this->dir . '/' . $slug;
        mkdir($base, 0755, true);
        file_put_contents($base . '/plugin.json', json_encode(array_merge(['slug' => $slug, 'name' => 'Test ' . $slug, 'version' => '1.0.0'], $manifest)));
        foreach ($files as $path => $content) {
            @mkdir(dirname($base . '/' . $path), 0755, true);
            file_put_contents($base . '/' . $path, $content);
        }
    }

    private function tableExists(string $t): bool
    {
        return (bool)$this->db->fetchOne('SELECT 1 AS x FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
    }

    private function statusOf(string $slug): ?string
    {
        foreach ($this->pm->discover() as $p) {
            if ($p['slug'] === $slug) {
                return $p['status'];
            }
        }
        return null;
    }

    #[Test]
    public function discoverReportsDiscoveredInvalidAndActive(): void
    {
        $this->plugin('tp-good');
        mkdir($this->dir . '/tp-broken');
        file_put_contents($this->dir . '/tp-broken/plugin.json', '{nope');
        $this->plugin('tp-badslug', ['slug' => 'other']);

        $this->assertSame('discovered', $this->statusOf('tp-good'));
        $this->assertSame('invalid', $this->statusOf('tp-broken'));
        $this->assertSame('invalid', $this->statusOf('tp-badslug'));

        $this->pm->activate('tp-good');
        $this->assertSame('active', $this->statusOf('tp-good'));
        $this->pm->deactivate('tp-good');
        $this->assertSame('inactive', $this->statusOf('tp-good'));
    }

    #[Test]
    public function activateRunsMigrationsOnceSeedsPermissionsAndWritesAuditLog(): void
    {
        $this->plugin('tp-mig', ['permissions' => ['tp-mig.use']], [
            'migrations/001_items.sql' => "CREATE TABLE IF NOT EXISTS cf_plg_tp_mig_items (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, naam VARCHAR(50) NOT NULL) ENGINE=InnoDB;\nINSERT INTO cf_plg_tp_mig_items (naam) VALUES ('een');",
        ]);
        $this->assertTrue($this->pm->discover()[0]['needs_migration']);

        $this->pm->activate('tp-mig', ['id' => null, 'username' => 'tester']);
        $this->assertTrue($this->tableExists('cf_plg_tp_mig_items'));
        $this->assertSame(['001_items.sql' => true], array_fill_keys(array_column($this->db->fetchAll('SELECT migration FROM cf_plugin_migrations'), 'migration'), true));
        $this->assertNotNull($this->db->fetchOne("SELECT 1 AS x FROM cf_permissions WHERE name = 'tp-mig.use'"));

        // tweede activatie voert de migratie NIET opnieuw uit (geen dubbele rij)
        $this->pm->activate('tp-mig');
        $this->assertSame('1', (string)$this->db->fetchOne('SELECT COUNT(*) AS c FROM cf_plg_tp_mig_items')['c']);
        $this->assertFalse($this->pm->discover()[0]['needs_migration']);

        $log = $this->db->fetchAll("SELECT action, username FROM cf_audit_log WHERE action = 'plugin.activate'");
        $this->assertCount(2, $log);
        $this->assertSame('tester', $log[0]['username']);
    }

    #[Test]
    public function aBadMigrationBlocksActivationAndRunsNothingOfTheSet(): void
    {
        $this->plugin('tp-evil', [], [
            'migrations/001_ok.sql'   => 'CREATE TABLE IF NOT EXISTS cf_plg_tp_evil_a (id INT) ENGINE=InnoDB;',
            'migrations/002_evil.sql' => 'DROP TABLE cf_users;',
        ]);
        try {
            $this->pm->activate('tp-evil');
            $this->fail('Activatie had moeten mislukken.');
        } catch (PackageException) {
            $this->addToAssertionCount(1);
        }
        $this->assertFalse($this->tableExists('cf_plg_tp_evil_a'), 'Ook de goede migratie mag niet zijn uitgevoerd.');
        $this->assertTrue($this->tableExists('cf_users'));
        $this->assertNull($this->db->fetchOne("SELECT 1 AS x FROM cf_plugins WHERE slug = 'tp-evil'"));
    }

    #[Test]
    public function aMigrationThatFailsAtRuntimeDoesNotActivateThePlugin(): void
    {
        $this->plugin('tp-rt', [], ['migrations/001_bad.sql' => 'ALTER TABLE cf_plg_tp_rt_niet_bestaand ADD COLUMN x INT;']);
        try {
            $this->pm->activate('tp-rt');
            $this->fail('Activatie had moeten mislukken.');
        } catch (PackageException $e) {
            $this->assertStringContainsString('001_bad.sql', $e->getMessage());
        }
        $this->assertNull($this->db->fetchOne("SELECT 1 AS x FROM cf_plugins WHERE slug = 'tp-rt'"));
    }

    #[Test]
    public function migrationsFolderRejectsUnexpectedFiles(): void
    {
        $this->plugin('tp-files', [], ['migrations/evil.php' => '<?php']);
        $this->expectException(PackageException::class);
        $this->pm->activate('tp-files');
    }

    #[Test]
    public function uninstallKeepsDataByDefaultAndDropsItOnRequest(): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS cf_plg_tp_un_items (id INT) ENGINE=InnoDB;';
        $this->plugin('tp-un', ['permissions' => ['tp-un.use']], ['migrations/001_a.sql' => $sql]);
        $this->pm->activate('tp-un');
        $this->pm->uninstall('tp-un');
        $this->assertDirectoryDoesNotExist($this->dir . '/tp-un');
        $this->assertTrue($this->tableExists('cf_plg_tp_un_items'), 'data blijft standaard staan');
        $this->assertNull($this->db->fetchOne("SELECT 1 AS x FROM cf_permissions WHERE name = 'tp-un.use'"));

        $this->plugin('tp-un', [], ['migrations/001_a.sql' => $sql]);
        $this->pm->uninstall('tp-un', true);
        $this->assertFalse($this->tableExists('cf_plg_tp_un_items'));
        $this->assertSame('0', (string)$this->db->fetchOne("SELECT COUNT(*) AS c FROM cf_plugin_migrations WHERE slug = 'tp-un'")['c']);
        $this->assertTrue($this->tableExists('cf_users'));
    }

    #[Test]
    public function uninstallRefusesDangerousSlugsAndDropDataOnlyTouchesOwnPrefix(): void
    {
        $this->db->execute('CREATE TABLE IF NOT EXISTS cf_plg_tp_un2_extra (id INT) ENGINE=InnoDB');
        $this->db->execute('CREATE TABLE IF NOT EXISTS cf_plg_tp_un2x_other (id INT) ENGINE=InnoDB');
        foreach (['..', '', '../x', 'a/b'] as $slug) {
            try {
                $this->pm->uninstall($slug);
                $this->fail('Had geweigerd moeten worden: ' . json_encode($slug));
            } catch (PackageException) {
                $this->addToAssertionCount(1);
            }
        }
        mkdir($this->dir . '/tp-un2');
        $this->pm->uninstall('tp-un2', true);
        $this->assertFalse($this->tableExists('cf_plg_tp_un2_extra'));
        $this->assertTrue($this->tableExists('cf_plg_tp_un2x_other'), 'tabel van een andere plugin blijft staan');
    }

    #[Test]
    public function loadActiveBootsPluginsRegistersRoutesHooksAndIsolatesFailures(): void
    {
        $ns = 'CommunityFusion\\Plugins\\TpLoad\\';
        $this->plugin('tp-load', [
            'class' => $ns . 'Main', 'autoload' => [$ns => 'src/'], 'routes' => ['routes.php'],
            'settings' => [['key' => 'greeting', 'type' => 'string'], ['key' => 'secret', 'type' => 'encrypted']],
        ], [
            'src/Main.php' => "<?php\nnamespace CommunityFusion\\Plugins\\TpLoad;\nuse CommunityFusion\\Core\\Plugin\\{PluginInterface, PluginContext};\nfinal class Main implements PluginInterface {\n  public function boot(PluginContext \$c): void { \$c->hooks->addAction('tp.booted', function (\$arg) use (\$c) { \$GLOBALS['tp_booted'] = [\$c->slug, \$c->setting('greeting'), \$c->tablePrefix(), \$arg]; }); }\n}\n",
            'routes.php' => "<?php\n\$GLOBALS['tp_route_router'] = \$router; \$GLOBALS['tp_route_plugin'] = \$plugin->slug;\n",
        ]);
        $this->plugin('tp-broken', ['class' => 'CommunityFusion\\Plugins\\TpBroken\\Nope', 'autoload' => ['CommunityFusion\\Plugins\\TpBroken\\' => 'src/']]);
        $this->plugin('tp-off');
        $this->pm->activate('tp-load');
        $this->pm->activate('tp-broken');
        $this->pm->saveSettings('tp-load', ['greeting' => 'hoi', 'secret' => 'geheim', 'onbekend' => 'x']);

        $hooks = new HookManager();
        $registry = new BlockRegistry($this->db, new CacheManager(['path' => $this->dir . '/cache']));
        $this->pm->loadActive($hooks, $registry);

        $hooks->doAction('tp.booted', 42);
        $this->assertSame(['tp-load', 'hoi', 'cf_plg_tp_load_', 42], $GLOBALS['tp_booted']);
        $hooks->doAction('router.routes', 'ROUTER');
        $this->assertSame('ROUTER', $GLOBALS['tp_route_router']);
        $this->assertSame('tp-load', $GLOBALS['tp_route_plugin']);

        $this->assertSame('error', $this->statusOf('tp-broken'), 'kapotte plugin is geïsoleerd gemeld');
        $this->assertSame('active', $this->statusOf('tp-load'));
        $this->assertSame('discovered', $this->statusOf('tp-off'));
        unset($GLOBALS['tp_booted'], $GLOBALS['tp_route_router'], $GLOBALS['tp_route_plugin']);
    }

    #[Test]
    public function settingsAreValidatedTypedAndEncryptedAtRest(): void
    {
        $this->plugin('tp-set', ['settings' => [
            ['key' => 'name', 'type' => 'string'], ['key' => 'count', 'type' => 'int'],
            ['key' => 'on', 'type' => 'bool'], ['key' => 'token', 'type' => 'encrypted'],
        ]]);
        $this->pm->activate('tp-set');
        $this->pm->saveSettings('tp-set', ['name' => 'x', 'count' => '7', 'on' => 'true', 'token' => 'sk-123', 'niet-in-manifest' => 'y']);

        $s = $this->pm->settings('tp-set');
        $this->assertSame('x', $s['name']);
        $this->assertSame(7, $s['count']);
        $this->assertTrue($s['on']);
        $this->assertSame('sk-123', $s['token']);
        $this->assertArrayNotHasKey('niet-in-manifest', $s);

        $raw = (string)$this->db->fetchOne("SELECT settings_json AS j FROM cf_plugins WHERE slug = 'tp-set'")['j'];
        $this->assertFalse(str_contains($raw, 'sk-123'), 'encrypted waarde mag niet leesbaar in de database staan');

        $this->pm->saveSettings('tp-set', ['token' => '']);   // leeg laat bestaande waarde staan
        $this->assertSame('sk-123', $this->pm->settings('tp-set')['token']);

        $this->expectException(PackageException::class);
        $this->pm->saveSettings('tp-set', ['count' => 'abc']);
    }

    /** @param array<string,string> $files */
    private function zip(array $files): string
    {
        $path = $this->dir . '/up-' . bin2hex(random_bytes(4)) . '.zip';
        $z = new \ZipArchive();
        $z->open($path, \ZipArchive::CREATE);
        foreach ($files as $n => $c) {
            $z->addFromString($n, $c);
        }
        $z->close();
        return $path;
    }

    #[Test]
    public function uploadInstallsAValidPluginWithoutActivatingItAndReplacesAtomically(): void
    {
        $m = json_encode(['slug' => 'tp-up', 'name' => 'Up', 'version' => '1.0.0']);
        $slug = $this->pm->installFromUpload($this->zip(['tp-up/plugin.json' => $m, 'tp-up/README.md' => 'v1']), 'tp-up.zip', ['id' => null, 'username' => 'u']);
        $this->assertSame('tp-up', $slug);
        $this->assertFileExists($this->dir . '/tp-up/README.md');
        $this->assertSame('discovered', $this->statusOf('tp-up'));

        $this->pm->installFromUpload($this->zip(['plugin.json' => $m, 'README.md' => 'v2']), 'x.zip');
        $this->assertSame('v2', file_get_contents($this->dir . '/tp-up/README.md'));
        $this->assertSame([], glob($this->dir . '/.tp-up.*') ?: [], 'geen back-up of staging-map achtergebleven');
        $this->assertNotNull($this->db->fetchOne("SELECT 1 AS x FROM cf_audit_log WHERE action = 'plugin.install'"));
    }

    #[Test]
    public function uploadRejectsEvilPackagesAndDeploysNothing(): void
    {
        $ok = json_encode(['slug' => 'tp-ev', 'name' => 'Ev', 'version' => '1.0.0']);
        $cases = [
            'zip-slip'       => ['plugin.json' => $ok, '../evil.php' => 'x'],
            'slug-traversal' => ['plugin.json' => json_encode(['slug' => '..', 'name' => 'x', 'version' => '1.0.0'])],
            'core-class'     => ['plugin.json' => json_encode(['slug' => 'tp-ev', 'name' => 'x', 'version' => '1.0.0', 'class' => 'CommunityFusion\\Core\\Application', 'autoload' => ['CommunityFusion\\Plugins\\X\\' => 'src']])],
            'evil-migration' => ['plugin.json' => $ok, 'migrations/001_x.sql' => 'DROP TABLE cf_users;'],
            'bad-migration-name' => ['plugin.json' => $ok, 'migrations/evil.sql' => 'SELECT 1;'],
            'phar'           => ['plugin.json' => $ok, 'x.phar' => 'x'],
            'htaccess'       => ['plugin.json' => $ok, '.htaccess' => 'x'],
            'no-manifest'    => ['README.md' => 'x'],
        ];
        foreach ($cases as $label => $files) {
            try {
                $this->pm->installFromUpload($this->zip($files), 'p.zip');
                $this->fail("Had geweigerd moeten worden: {$label}");
            } catch (PackageException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], array_values(array_filter(scandir($this->dir) ?: [], fn ($f) => $f[0] !== '.' && is_dir($this->dir . '/' . $f))));
        $this->assertFileDoesNotExist(dirname($this->dir) . '/evil.php');
        $this->expectException(PackageException::class);
        $this->pm->installFromUpload($this->zip(['plugin.json' => $ok]), 'plugin.php');
    }

    #[Test]
    public function requirementsAreEnforcedOnActivation(): void
    {
        $this->plugin('tp-req', ['requires' => ['blueprint' => '>=99.0.0']]);
        $this->assertSame('invalid', $this->statusOf('tp-req'));
        $this->expectException(PackageException::class);
        $this->pm->activate('tp-req');
    }
}
