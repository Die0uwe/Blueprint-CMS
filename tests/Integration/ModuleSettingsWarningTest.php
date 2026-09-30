<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Integration;

use CommunityFusion\Core\Application;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Request;
use CommunityFusion\Modules\Marketplace\ModuleSettingsController;
use CommunityFusion\Modules\Settings\SettingsRepository;
use CommunityFusion\Tests\Support\TestAuth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/TestAuth.php';

/** Instellingenpagina van een module waarschuwt als de module niet aan staat in cf_modules. Draait alleen met BP_TEST_DB. */
final class ModuleSettingsWarningTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $name = getenv('BP_TEST_DB');
        if ($name === false || $name === '') {
            $this->markTestSkipped('BP_TEST_DB niet gezet.');
        }
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('k', 32));
        $this->db = new Connection([
            'host' => getenv('BP_TEST_HOST') ?: '127.0.0.1', 'name' => $name,
            'user' => (string) getenv('BP_TEST_USER'), 'password' => (string) getenv('BP_TEST_PASS'),
        ]);
        Application::getInstance()->getContainer()->instance(Connection::class, $this->db);
        @mkdir(CF_ROOT . '/modules/discord', 0755, true);
        file_put_contents(CF_ROOT . '/modules/discord/module.json', json_encode([
            'name' => 'Discord Integration',
            'settings' => [['key' => 'guild_id', 'label' => 'Guild', 'type' => 'string']],
        ]));
        $this->db->execute("DELETE FROM cf_modules WHERE slug = 'discord'");
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute("DELETE FROM cf_modules WHERE slug = 'discord'");
            TestAuth::cleanup($this->db);
        }
        @unlink(CF_ROOT . '/modules/discord/module.json');
    }

    private function page(): string
    {
        $auth = TestAuth::make($this->db, ['marketplace.install']);
        $settings = new SettingsRepository($this->db, new CacheManager(['path' => sys_get_temp_dir() . '/bp-msw-' . bin2hex(random_bytes(4))]));
        $r = new Request('GET', '/x', [], [], [], [], [], []);
        $r->setParams(['slug' => 'discord']);
        return (new ModuleSettingsController($auth, $settings))->edit($r)->getBody();
    }

    private function disabled(): bool
    {
        $auth = TestAuth::make($this->db, ['marketplace.install']);
        $settings = new SettingsRepository($this->db, new CacheManager(['path' => sys_get_temp_dir() . '/bp-msw-' . bin2hex(random_bytes(4))]));
        $m = new \ReflectionMethod(ModuleSettingsController::class, 'isModuleDisabled');
        return $m->invoke(new ModuleSettingsController($auth, $settings), 'discord');
    }

    #[Test]
    public function moduleCountsAsDisabledWithoutRowOrWithIsEnabledZero(): void
    {
        $this->assertTrue($this->disabled(), 'geen rij');
        $this->db->execute("INSERT INTO cf_modules (slug, name, version, is_enabled) VALUES ('discord', 'Discord', '1.0.0', 0)");
        $this->assertTrue($this->disabled(), 'is_enabled = 0');
        $this->db->execute("UPDATE cf_modules SET is_enabled = 1 WHERE slug = 'discord'");
        $this->assertFalse($this->disabled(), 'ingeschakeld');
    }

    /** Let op: de view declareert een functie en kan maar één keer per proces worden geladen — dus één render in deze klasse. */
    #[Test]
    public function settingsPageShowsTheWarningForADisabledModuleAndStillTheForm(): void
    {
        $html = $this->page();
        $this->assertStringContainsString('staat <strong>uit</strong>', $html);
        $this->assertStringContainsString('Guild', $html, 'formulier wordt nog steeds getoond');
    }
}
