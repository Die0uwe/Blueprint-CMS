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
use CommunityFusion\Core\Plugin\PluginAutoloader;
use CommunityFusion\Core\Plugin\PluginManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** De meegeleverde voorbeeldplugin moet werken zoals docs/plugins.md belooft. Draait alleen met BP_TEST_DB. */
final class ExamplePluginTest extends TestCase
{
    private const SLUG = 'example-hello-world';
    private const TABLE = 'cf_plg_example_hello_world_greetings';

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
        $this->db->execute('DELETE FROM cf_plugins');
        $this->db->execute('DELETE FROM cf_plugin_migrations');
        $this->db->execute('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        $this->dir = sys_get_temp_dir() . '/bp-ex-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
        exec('cp -r ' . escapeshellarg(__DIR__ . '/../../plugins/' . self::SLUG) . ' ' . escapeshellarg($this->dir . '/' . self::SLUG));
        $this->pm = new PluginManager($this->db, $this->dir, '1.29.0', new AuditLogger($this->db));
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute('DELETE FROM cf_plugins');
            $this->db->execute('DELETE FROM cf_plugin_migrations');
            $this->db->execute("DELETE FROM cf_modules WHERE slug = 'plugin-example-hello-world'");
            $this->db->execute('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        }
        PluginAutoloader::reset();
        if (isset($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function load(): array
    {
        $hooks = new HookManager();
        $registry = new BlockRegistry($this->db, new CacheManager(['path' => $this->dir . '/cache']));
        $this->pm->loadActive($hooks, $registry);
        return [$hooks, $registry];
    }

    #[Test]
    public function theManifestIsValidAndItCreatesOnlyItsOwnTable(): void
    {
        $this->assertSame('example-hello-world', $this->pm->readManifest(self::SLUG)['slug']);
        $this->pm->activate(self::SLUG);
        $this->assertTrue((bool)$this->db->fetchOne('SELECT 1 x FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [self::TABLE]));
    }

    #[Test]
    public function toolbarButtonShortcodeAndBlockWork(): void
    {
        $this->pm->activate(self::SLUG);
        $this->pm->saveSettings(self::SLUG, ['greeting' => '<b>Hoi</b>', 'shout' => '0']);
        [$hooks, $registry] = $this->load();

        $buttons = $hooks->applyFilters('editor.toolbar.register', [], ['type' => 'page']);
        $this->assertSame('hallo', $buttons[0]['id']);

        $out = $hooks->applyFilters('content.after_render', '<p>[hallo]</p>', ['type' => 'page', 'id' => 1]);
        $this->assertSame('<p>&lt;b&gt;Hoi&lt;/b&gt;</p>', $out);   // begroeting wordt geëscaped

        $block = $registry->find('example-hello');
        $this->assertNotNull($block);
        $this->assertSame('<p>Hallo, &lt;i&gt;!</p>', $block->render(['name' => '<i>']));
    }

    #[Test]
    public function uninstallWithDataRemovesTheTable(): void
    {
        $this->pm->activate(self::SLUG);
        $this->pm->uninstall(self::SLUG, true);
        $this->assertFalse((bool)$this->db->fetchOne('SELECT 1 x FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [self::TABLE]));
        $this->assertDirectoryDoesNotExist($this->dir . '/' . self::SLUG);
    }
}
