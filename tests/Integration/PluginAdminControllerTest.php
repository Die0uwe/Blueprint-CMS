<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Integration;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Plugin\PluginAutoloader;
use CommunityFusion\Core\Plugin\PluginManager;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Modules\Plugins\PluginAdminController;
use CommunityFusion\Tests\Support\TestAuth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/TestAuth.php';

/** Draait alleen met BP_TEST_DB (+ BP_TEST_USER/BP_TEST_PASS). */
final class PluginAdminControllerTest extends TestCase
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
        unset($_ENV['ALLOW_PLUGIN_UPLOAD'], $_SESSION['plugin_flash']);
        $this->db = new Connection([
            'host' => getenv('BP_TEST_HOST') ?: '127.0.0.1', 'name' => $name,
            'user' => (string)getenv('BP_TEST_USER'), 'password' => (string)getenv('BP_TEST_PASS'),
        ]);
        $this->db->execute('DELETE FROM cf_plugins');
        $this->db->execute("DELETE FROM cf_modules WHERE slug LIKE 'plugin-%'");
        $this->dir = sys_get_temp_dir() . '/bp-pac-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/tp-adm', 0755, true);
        file_put_contents($this->dir . '/tp-adm/plugin.json', json_encode([
            'slug' => 'tp-adm', 'name' => 'Admin test', 'version' => '1.0.0',
            'settings' => [['key' => 'on', 'type' => 'bool'], ['key' => 'token', 'type' => 'encrypted'], ['key' => 'n', 'type' => 'int']],
        ]));
        $this->pm = new PluginManager($this->db, $this->dir, '1.29.0', new AuditLogger($this->db));
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute('DELETE FROM cf_plugins');
            TestAuth::cleanup($this->db);
        }
        unset($_ENV['ALLOW_PLUGIN_UPLOAD']);
        PluginAutoloader::reset();
        if (isset($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    /** @param list<string> $perms */
    private function ctl(array $perms): PluginAdminController
    {
        return new PluginAdminController($this->pm, TestAuth::make($this->db, $perms), $this->db);
    }

    private function req(array $body = [], string $slug = 'tp-adm', bool $csrf = true): Request
    {
        $_POST = $csrf ? ['_csrf_token' => CsrfProtection::getToken()] : [];
        $r = new Request('POST', '/x', [], $body + $_POST, [], [], [], []);
        $r->setParams(['slug' => $slug]);
        return $r;
    }

    private function flash(): ?array
    {
        return $_SESSION['plugin_flash'] ?? null;
    }

    #[Test]
    public function withoutThePermissionNothingWorks(): void
    {
        $c = $this->ctl(['blocks.manage']);
        foreach (['index', 'activate', 'deactivate', 'uninstall', 'upload', 'settings', 'saveSettings', 'migrate'] as $m) {
            try {
                $c->$m($this->req());
                $this->fail("$m had 403 moeten geven");
            } catch (\Throwable $e) {
                $this->assertSame(403, $e->getCode(), $m);
            }
        }
        $this->assertNull($this->db->fetchOne('SELECT 1 x FROM cf_plugins WHERE slug = ?', ['tp-adm']));
    }

    #[Test]
    public function postsWithoutCsrfAreRejected(): void
    {
        $this->expectException(\Throwable::class);
        $this->ctl(['plugins.manage'])->activate($this->req([], 'tp-adm', false));
    }

    #[Test]
    public function activateAndDeactivateWork(): void
    {
        $c = $this->ctl(['plugins.manage']);
        $res = $c->activate($this->req());
        $this->assertSame(302, $res->getStatus());
        $this->assertSame('ok', $this->flash()['type']);
        $this->assertSame(1, (int)$this->db->fetchOne('SELECT active a FROM cf_plugins WHERE slug = ?', ['tp-adm'])['a']);
        $c->deactivate($this->req());
        $this->assertSame(0, (int)$this->db->fetchOne('SELECT active a FROM cf_plugins WHERE slug = ?', ['tp-adm'])['a']);
        $this->assertStringContainsString('Admin test', $c->index($this->req())->getBody());
    }

    #[Test]
    public function invalidSlugsAreRefusedWithoutTouchingTheDisk(): void
    {
        $c = $this->ctl(['plugins.manage']);
        $c->uninstall($this->req(['confirm' => '../x'], '../x'));
        $this->assertSame('error', $this->flash()['type']);
        $this->assertDirectoryExists($this->dir . '/tp-adm');
    }

    #[Test]
    public function uninstallNeedsTheNameAsConfirmation(): void
    {
        $c = $this->ctl(['plugins.manage']);
        $c->uninstall($this->req(['confirm' => 'nee']));
        $this->assertSame('error', $this->flash()['type']);
        $this->assertDirectoryExists($this->dir . '/tp-adm');
        $c->uninstall($this->req(['confirm' => 'tp-adm']));
        $this->assertSame('ok', $this->flash()['type']);
        $this->assertDirectoryDoesNotExist($this->dir . '/tp-adm');
    }

    #[Test]
    public function uploadIsOffByDefaultAndForSuperAdminsOnly(): void
    {
        $c = $this->ctl(['plugins.manage']);
        $c->upload($this->req());
        $this->assertStringContainsString('Uploaden staat uit', $this->flash()['msg']);

        $_ENV['ALLOW_PLUGIN_UPLOAD'] = 'true';      // vlag aan, maar gebruiker is geen super_admin
        $c2 = $this->ctl(['plugins.manage']);
        $c2->upload($this->req());
        $this->assertStringContainsString('Uploaden staat uit', $this->flash()['msg']);
        $this->assertStringNotContainsString('name="zip"', $c2->index($this->req())->getBody());
    }

    #[Test]
    public function settingsAreSavedPerTypeAndSecretsStayHidden(): void
    {
        $c = $this->ctl(['plugins.manage']);
        $c->activate($this->req());
        $c->saveSettings($this->req(['on' => '1', 'token' => 'geheim-123', 'n' => '5']));
        $s = $this->pm->settings('tp-adm');
        $this->assertTrue($s['on']);
        $this->assertSame(5, $s['n']);
        $this->assertSame('geheim-123', $s['token']);

        $c->saveSettings($this->req(['token' => '', 'n' => '6']));   // bool niet aangevinkt, token leeg
        $s = $this->pm->settings('tp-adm');
        $this->assertFalse($s['on']);
        $this->assertSame('geheim-123', $s['token']);

        $html = $c->settings($this->req())->getBody();
        $this->assertStringNotContainsString('geheim-123', $html);
        $this->assertStringContainsString('laat leeg om te behouden', $html);
    }
}
