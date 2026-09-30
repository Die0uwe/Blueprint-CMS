<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Integration;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Security\Crypto;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Modules\Discord\DiscordAdminController;
use CommunityFusion\Modules\Discord\DiscordApi;
use CommunityFusion\Modules\Discord\DiscordStore;
use CommunityFusion\Modules\Settings\SettingsRepository;
use CommunityFusion\Tests\Support\FakeDiscordTransport;
use CommunityFusion\Tests\Support\TestAuth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/TestAuth.php';
require_once __DIR__ . '/../Support/FakeDiscordTransport.php';

/** Draait alleen met BP_TEST_DB (+ BP_TEST_USER/BP_TEST_PASS). Geen netwerk: nep-transport. */
final class DiscordAdminControllerTest extends TestCase
{
    private const G = '123456789012345678';
    private const CH = '223456789012345672';
    private const WH = 'https://discord.com/api/webhooks/123456789012345678/SECRETtokenABCD';

    private Connection $db;
    private SettingsRepository $settings;
    private FakeDiscordTransport $t;

    protected function setUp(): void
    {
        $name = getenv('BP_TEST_DB');
        if ($name === false || $name === '') {
            $this->markTestSkipped('BP_TEST_DB niet gezet.');
        }
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('k', 32));
        $_ENV['APP_URL'] = 'https://site.example.test';
        unset($_SESSION['discord_flash'], $_SESSION['discord_test']);
        $this->db = new Connection([
            'host' => getenv('BP_TEST_HOST') ?: '127.0.0.1', 'name' => $name,
            'user' => (string) getenv('BP_TEST_USER'), 'password' => (string) getenv('BP_TEST_PASS'),
        ]);
        DiscordStore::ensureSchema($this->db);
        $this->wipe();
        $this->settings = new SettingsRepository($this->db, new CacheManager(['path' => sys_get_temp_dir() . '/bp-dc-' . bin2hex(random_bytes(4))]));
        $this->settings->set('discord', 'guild_id', self::G);
        $this->settings->set('discord', 'bot_token', 'BOTTOKEN-abcdefghij', 'encrypted');
        $this->t = new FakeDiscordTransport();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->wipe();
            TestAuth::cleanup($this->db);
        }
        unset($_ENV['APP_URL']);
    }

    private function wipe(): void
    {
        $this->db->execute('DELETE FROM cf_discord_role_mapping');
        $this->db->execute('DELETE FROM cf_discord_sync_log');
        $this->db->execute("DELETE FROM cf_settings WHERE `group` = 'discord'");
        $this->db->execute("DELETE FROM cf_audit_log WHERE action LIKE 'discord.%'");
    }

    /** @param list<string> $perms */
    private function ctl(array $perms = ['discord.admin']): DiscordAdminController
    {
        $auth = TestAuth::make($this->db, $perms);
        return (new DiscordAdminController($auth, $this->db, $this->settings, new AuditLogger($this->db)))->useTransport($this->t);
    }

    private function req(array $body = [], array $params = [], bool $csrf = true): Request
    {
        $_POST = $csrf ? ['_csrf_token' => CsrfProtection::getToken()] : [];
        $r = new Request('POST', '/x', [], $body + $_POST, [], [], [], []);
        $r->setParams($params);
        return $r;
    }

    private function flash(): ?array
    {
        return $_SESSION['discord_flash'] ?? null;
    }

    private function roleId(string $name): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM cf_roles WHERE name = ?', [$name])['id'];
    }

    private function makeRole(string $suffix = 'a'): int
    {
        $n = 't_dc_' . $suffix . bin2hex(random_bytes(2));
        $this->db->execute("INSERT INTO cf_roles (name, display_name, priority) VALUES (?, 'DC test', 1)", [$n]);
        return $this->roleId($n);
    }

    // ─── toegang ──────────────────────────────────────────────────────────

    #[Test]
    public function everyScreenNeedsDiscordAdmin(): void
    {
        $c = $this->ctl(['blocks.manage']);
        foreach (['status', 'testConnection', 'widget', 'widgetUpdate', 'notifications', 'saveNotifications', 'deleteWebhook', 'testWebhook', 'roles', 'addRole', 'updateRole', 'deleteRole'] as $m) {
            try {
                $c->$m($this->req([], ['id' => '1']));
                $this->fail("{$m} had 403 moeten geven");
            } catch (\Throwable $e) {
                $this->assertSame(403, $e->getCode(), $m);
            }
        }
        $this->assertCount(0, $this->t->requests);
        $this->assertCount(0, $this->db->fetchAll('SELECT 1 FROM cf_discord_role_mapping'));
    }

    #[Test]
    public function postsWithoutCsrfAreRejectedAndChangeNothing(): void
    {
        $c = $this->ctl();
        $role = $this->makeRole();
        foreach (['testConnection', 'widgetUpdate', 'saveNotifications', 'deleteWebhook', 'testWebhook', 'addRole', 'updateRole', 'deleteRole'] as $m) {
            try {
                $c->$m($this->req(['webhook_url' => self::WH, 'discord_role_manual' => '323456789012345678', 'cms_role_id' => (string) $role, 'channel_id' => self::CH], ['id' => '1'], false));
                $this->fail("{$m} zonder CSRF had 403 moeten geven");
            } catch (\Throwable $e) {
                $this->assertSame(403, $e->getCode(), $m);
            }
        }
        $this->assertCount(0, $this->t->requests);
        $this->assertSame('', (new DiscordStore($this->db))->webhookUrl());
        $this->assertCount(0, $this->db->fetchAll('SELECT 1 FROM cf_discord_role_mapping'));
    }

    // ─── schermen ─────────────────────────────────────────────────────────

    #[Test]
    public function allScreensRenderAndEscapeDiscordData(): void
    {
        $evil = '<img src=x onerror=alert(1)>';
        $this->t->route('GET /guilds/' . self::G . '/widget', 200, ['enabled' => false, 'channel_id' => null])
            ->route('GET /guilds/' . self::G . '/channels', 200, [['id' => self::CH, 'name' => $evil, 'type' => 0, 'position' => 0]])
            ->route('GET /guilds/' . self::G . '/roles', 200, [['id' => '323456789012345678', 'name' => $evil, 'position' => 1]]);
        $c = $this->ctl();
        foreach (['status', 'widget', 'notifications', 'roles'] as $m) {
            $res = $c->$m($this->req());
            $this->assertSame(200, $res->getStatus(), $m);
            $this->assertStringContainsString('Discord', $res->getBody());
            $this->assertStringNotContainsString($evil, $res->getBody(), "{$m}: ongeëscapete Discord-data");
        }
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $c->widget($this->req())->getBody());
    }

    #[Test]
    public function statusShowsCallbackUrlAndWarnsOnBadAppUrl(): void
    {
        $body = $this->ctl()->status($this->req())->getBody();
        $this->assertStringContainsString('https://site.example.test/auth/discord/callback', $body);

        $this->assertSame([], DiscordAdminController::callbackInfo('https://a.test')['warnings']);
        $this->assertCount(1, DiscordAdminController::callbackInfo('')['warnings']);
        $this->assertStringContainsString('APP_URL is leeg', DiscordAdminController::callbackInfo('')['warnings'][0]);
        $this->assertStringContainsString('http://', DiscordAdminController::callbackInfo('http://a.test/')['warnings'][0]);
        $this->assertSame('http://a.test/auth/discord/callback', DiscordAdminController::callbackInfo('http://a.test/')['default']);
        $this->assertCount(1, DiscordAdminController::callbackInfo('https://a.test', 'https://ander.test/cb')['warnings']);
        $_ENV['APP_URL'] = '';
        $this->assertStringContainsString('APP_URL is leeg', $this->ctl()->status($this->req())->getBody());
    }

    #[Test]
    public function inviteLinkOnlyAppearsWhenClientIdIsKnown(): void
    {
        $this->assertStringNotContainsString('permissions=1056', $this->ctl()->status($this->req())->getBody());
        $this->settings->set('discord', 'client_id', '923456789012345678');
        $body = $this->ctl()->status($this->req())->getBody();
        $this->assertStringContainsString('permissions=1056', $body);
        $this->assertStringContainsString('scope=bot', $body);
    }

    #[Test]
    public function connectionTestStoresAndShowsTheResult(): void
    {
        $this->t->route('GET /users/@me', 200, ['username' => 'SlayerBot'])
            ->route('GET /guilds/' . self::G, 200, ['name' => 'Slayer Alliance'])
            ->route('GET /guilds/' . self::G . '/widget', 200, ['enabled' => false, 'channel_id' => null])
            ->route('GET /users/@me/guilds', 200, []);
        $c = $this->ctl();
        $res = $c->testConnection($this->req());
        $this->assertSame(302, $res->getStatus());
        $body = $c->status($this->req())->getBody();
        $this->assertStringContainsString('SlayerBot', $body);
        $this->assertStringContainsString('Slayer Alliance', $body);
        $this->assertStringNotContainsString('BOTTOKEN-abcdefghij', $body);
        $this->assertStringNotContainsString('Resultaat van de test', $c->status($this->req())->getBody(), 'resultaat is eenmalig');
    }

    // ─── widget ───────────────────────────────────────────────────────────

    #[Test]
    public function widgetCanBeEnabledWithAChannelFromTheList(): void
    {
        $this->t->route('GET /guilds/' . self::G . '/channels', 200, [['id' => self::CH, 'name' => 'algemeen', 'type' => 0, 'position' => 0]])
            ->route('PATCH /guilds/' . self::G . '/widget', 200, ['enabled' => true, 'channel_id' => self::CH]);
        $this->ctl()->widgetUpdate($this->req(['action' => 'enable', 'channel_id' => self::CH]));
        $this->assertSame('ok', $this->flash()['type']);
        $patch = array_values(array_filter($this->t->requests, fn($r) => $r['method'] === 'PATCH'));
        $this->assertCount(1, $patch);
        $this->assertSame(['enabled' => true, 'channel_id' => self::CH], json_decode($patch[0]['body'], true));
        $this->assertTrue($this->db->fetchOne("SELECT 1 x FROM cf_audit_log WHERE action = 'discord.widget.enable'") !== null);
    }

    #[Test]
    public function widgetRefusesAChannelThatIsNotInTheGuild(): void
    {
        $this->t->route('GET /guilds/' . self::G . '/channels', 200, [['id' => self::CH, 'name' => 'algemeen', 'type' => 0, 'position' => 0]]);
        foreach (['999999999999999999', 'abc', '', '../x'] as $bad) {
            $this->ctl()->widgetUpdate($this->req(['action' => 'enable', 'channel_id' => $bad]));
            $this->assertSame('error', $this->flash()['type'], $bad);
        }
        $this->assertCount(0, array_filter($this->t->requests, fn($r) => $r['method'] === 'PATCH'));
    }

    #[Test]
    public function widgetShowsDiscordsOwnErrorWhenThePatchFails(): void
    {
        $this->t->route('GET /guilds/' . self::G . '/channels', 200, [['id' => self::CH, 'name' => 'algemeen', 'type' => 0, 'position' => 0]])
            ->route('PATCH /guilds/' . self::G . '/widget', 403, ['message' => 'Missing Permissions', 'code' => 50013]);
        $this->ctl()->widgetUpdate($this->req(['action' => 'enable', 'channel_id' => self::CH]));
        $f = $this->flash();
        $this->assertSame('error', $f['type']);
        $this->assertStringContainsString('Missing Permissions', $f['msg']);
        $this->assertStringContainsString('Serverbeheer', $f['msg']);
    }

    #[Test]
    public function widgetCanBeDisabled(): void
    {
        $this->t->route('PATCH /guilds/' . self::G . '/widget', 200, ['enabled' => false, 'channel_id' => null]);
        $this->ctl()->widgetUpdate($this->req(['action' => 'disable']));
        $this->assertSame('ok', $this->flash()['type']);
        $this->assertSame(['enabled' => false, 'channel_id' => null], $this->t->lastJson());
    }

    #[Test]
    public function widgetScreenExplainsWhenTokenIsMissing(): void
    {
        $this->db->execute("DELETE FROM cf_settings WHERE `group` = 'discord' AND `key` = 'bot_token'");
        $this->assertStringContainsString('Bot Token', $this->ctl()->widget($this->req())->getBody());
        $this->assertCount(0, $this->t->requests);
    }

    // ─── meldingen / webhook ──────────────────────────────────────────────

    #[Test]
    public function invalidWebhookUrlsAreRefusedAndNothingIsStored(): void
    {
        foreach (['http://discord.com/api/webhooks/123456789012345678/tok', 'https://evil.com/api/webhooks/123456789012345678/tok', 'https://discord.com.evil.com/api/webhooks/123456789012345678/tok', 'https://u@discord.com/api/webhooks/123456789012345678/tok', 'https://discord.com:444/api/webhooks/123456789012345678/tok', 'https://discord.com/api/webhooks/abc/tok'] as $bad) {
            $this->ctl()->saveNotifications($this->req(['webhook_url' => $bad]));
            $this->assertSame('error', $this->flash()['type'], $bad);
        }
        $this->assertSame('', (new DiscordStore($this->db))->webhookUrl());
    }

    #[Test]
    public function webhookIsStoredEncryptedAndNeverShownBack(): void
    {
        $c = $this->ctl();
        $c->saveNotifications($this->req(['webhook_url' => '  ' . self::WH . ' ', 'announce_news' => '1']));
        $this->assertSame('ok', $this->flash()['type']);

        $row = $this->db->fetchOne("SELECT `value`, `type` FROM cf_settings WHERE `group` = 'discord' AND `key` = 'webhook_url'");
        $this->assertSame('encrypted', $row['type']);
        $this->assertStringNotContainsString('SECRETtoken', $row['value']);
        $this->assertSame(self::WH, Crypto::decrypt($row['value']));
        $this->assertSame(self::WH, (new DiscordStore($this->db))->webhookUrl());
        $this->assertTrue((new DiscordStore($this->db))->announceNews());

        $body = $c->notifications($this->req())->getBody();
        $this->assertStringContainsString('ingesteld ✔', $body);
        $this->assertStringContainsString('ABCD', $body, 'laatste 4 tekens');
        $this->assertStringNotContainsString('SECRETtoken', $body);
        $this->assertStringNotContainsString('123456789012345678/', $body);
        $audit = $this->db->fetchOne("SELECT context FROM cf_audit_log WHERE action = 'discord.webhook.set'");
        $this->assertTrue($audit !== null);
        $this->assertStringNotContainsString('SECRET', (string) $audit['context']);
    }

    #[Test]
    public function savingWithAnEmptyUrlKeepsTheWebhookButUpdatesTheSwitch(): void
    {
        $c = $this->ctl();
        $c->saveNotifications($this->req(['webhook_url' => self::WH, 'announce_news' => '1']));
        $c->saveNotifications($this->req(['webhook_url' => '']));
        $store = new DiscordStore($this->db);
        $this->assertSame(self::WH, $store->webhookUrl());
        $this->assertFalse($store->announceNews());
    }

    #[Test]
    public function webhookCanBeDeleted(): void
    {
        $c = $this->ctl();
        $c->saveNotifications($this->req(['webhook_url' => self::WH, 'announce_news' => '1']));
        $c->deleteWebhook($this->req());
        $store = new DiscordStore($this->db);
        $this->assertSame('', $store->webhookUrl());
        $this->assertFalse($store->announceNews());
        $this->assertStringNotContainsString('ingesteld ✔', $c->notifications($this->req())->getBody());
    }

    #[Test]
    public function testMessageGoesToTheStoredWebhookOnly(): void
    {
        $c = $this->ctl();
        $c->testWebhook($this->req());
        $this->assertSame('error', $this->flash()['type'], 'zonder webhook');
        $this->assertCount(0, $this->t->requests);

        $c->saveNotifications($this->req(['webhook_url' => self::WH]));
        $this->t->queue(204, '');
        $c->testWebhook($this->req());
        $this->assertSame('ok', $this->flash()['type']);
        $this->assertSame(self::WH, $this->t->last()['url']);
        $this->assertSame(['parse' => []], $this->t->lastJson()['allowed_mentions']);

        $this->t->queue(404, ['message' => 'Unknown Webhook', 'code' => 10015]);
        $c->testWebhook($this->req());
        $this->assertSame('error', $this->flash()['type']);
        $this->assertStringContainsString('bestaat niet meer', $this->flash()['msg']);
        $this->assertStringNotContainsString('SECRETtoken', $this->flash()['msg']);
    }

    // ─── rolkoppeling ─────────────────────────────────────────────────────

    #[Test]
    public function roleMappingCrud(): void
    {
        $c = $this->ctl();
        $r1 = $this->makeRole('a');
        $r2 = $this->makeRole('b');

        $c->addRole($this->req(['discord_role_id' => '323456789012345678', 'cms_role_id' => (string) $r1, 'auto_remove' => '1']));
        $this->assertSame('ok', $this->flash()['type']);
        $m = $this->db->fetchOne('SELECT * FROM cf_discord_role_mapping');
        $this->assertSame('323456789012345678', $m['discord_role_id']);
        $this->assertSame($r1, (int) $m['cms_role_id']);
        $this->assertSame(1, (int) $m['auto_remove']);

        $c->updateRole($this->req(['cms_role_id' => (string) $r2], ['id' => (string) $m['id']]));
        $m = $this->db->fetchOne('SELECT * FROM cf_discord_role_mapping');
        $this->assertSame($r2, (int) $m['cms_role_id']);
        $this->assertSame(0, (int) $m['auto_remove'], 'vinkje uit = niet verwijderen');

        $c->deleteRole($this->req([], ['id' => (string) $m['id']]));
        $this->assertCount(0, $this->db->fetchAll('SELECT 1 FROM cf_discord_role_mapping'));
        $c->deleteRole($this->req([], ['id' => '999999']));
        $this->assertSame('error', $this->flash()['type']);
        $this->assertSame(3, (int) $this->db->fetchOne("SELECT COUNT(*) c FROM cf_audit_log WHERE action LIKE 'discord.role.%'")['c']);
    }

    #[Test]
    public function manualRoleIdTakesPrecedenceOverTheDropdown(): void
    {
        $r = $this->makeRole();
        $this->ctl()->addRole($this->req(['discord_role_id' => '323456789012345678', 'discord_role_manual' => ' 423456789012345678 ', 'cms_role_id' => (string) $r]));
        $this->assertSame('423456789012345678', $this->db->fetchOne('SELECT discord_role_id d FROM cf_discord_role_mapping')['d']);
    }

    #[Test]
    public function mappingInputIsValidated(): void
    {
        $c = $this->ctl();
        $r = $this->makeRole();
        $bad = [
            ['discord_role_id' => 'abc', 'cms_role_id' => (string) $r],
            ['discord_role_id' => '12345', 'cms_role_id' => (string) $r],
            ['discord_role_id' => "323456789012345678'; DROP TABLE cf_roles;--", 'cms_role_id' => (string) $r],
            ['discord_role_id' => '323456789012345678', 'cms_role_id' => ''],
            ['discord_role_id' => '323456789012345678', 'cms_role_id' => 'x'],
            ['discord_role_id' => '323456789012345678', 'cms_role_id' => '999999'],
            ['discord_role_id' => '323456789012345678', 'cms_role_id' => '-1'],
        ];
        foreach ($bad as $i => $input) {
            $c->addRole($this->req($input));
            $this->assertSame('error', $this->flash()['type'], "geval {$i}");
        }
        $this->assertCount(0, $this->db->fetchAll('SELECT 1 FROM cf_discord_role_mapping'));

        $c->addRole($this->req(['discord_role_id' => '323456789012345678', 'cms_role_id' => (string) $r]));
        $c->addRole($this->req(['discord_role_id' => '323456789012345678', 'cms_role_id' => (string) $r]));
        $this->assertSame('error', $this->flash()['type'], 'dubbele Discord-rol');
        $this->assertStringContainsString('al gekoppeld', $this->flash()['msg']);
        $this->assertCount(1, $this->db->fetchAll('SELECT 1 FROM cf_discord_role_mapping'));
    }

    #[Test]
    public function protectedRolesCannotBeMapped(): void
    {
        $c = $this->ctl();
        foreach (['admin', 'super_admin'] as $name) {
            $c->addRole($this->req(['discord_role_id' => '323456789012345678', 'cms_role_id' => (string) $this->roleId($name)]));
            $this->assertSame('error', $this->flash()['type'], $name);
            $this->assertStringContainsString('beschermd', $this->flash()['msg']);
        }
        // ook niet via bewerken van een bestaande koppeling
        $r = $this->makeRole();
        $c->addRole($this->req(['discord_role_id' => '323456789012345678', 'cms_role_id' => (string) $r]));
        $id = (string) $this->db->fetchOne('SELECT id FROM cf_discord_role_mapping')['id'];
        $c->updateRole($this->req(['cms_role_id' => (string) $this->roleId('admin')], ['id' => $id]));
        $this->assertSame('error', $this->flash()['type']);
        $this->assertSame($r, (int) $this->db->fetchOne('SELECT cms_role_id c FROM cf_discord_role_mapping')['c']);
        $this->assertCount(1, $this->db->fetchAll('SELECT 1 FROM cf_discord_role_mapping'));
    }

    #[Test]
    public function roleScreenListsMappingsAndOffersNoProtectedRole(): void
    {
        $r = $this->makeRole();
        $this->t->route('GET /guilds/' . self::G . '/roles', 200, [['id' => '323456789012345678', 'name' => 'Officer', 'position' => 1]]);
        $c = $this->ctl();
        $c->addRole($this->req(['discord_role_id' => '323456789012345678', 'cms_role_id' => (string) $r]));
        $body = $c->roles($this->req())->getBody();
        $this->assertStringContainsString('@Officer', $body);
        $this->assertStringNotContainsString('(super_admin)', $body);
        $this->assertStringNotContainsString('(admin)', $body);
        $this->assertStringContainsString('queue:work --queue=discord-sync', $body);
    }

    #[Test]
    public function roleScreenStillWorksWhenDiscordIsUnreachable(): void
    {
        $this->t->route('GET /guilds/' . self::G . '/roles', 500, []);
        $body = $this->ctl()->roles($this->req())->getBody();
        $this->assertStringContainsString('zelf invullen', $body);
    }

    #[Test]
    public function apiClassIsWiredToTheStoredDecryptedToken(): void
    {
        $this->t->queue(200, ['username' => 'x']);
        $this->t->route('GET /guilds/' . self::G . '/widget', 200, ['enabled' => true, 'channel_id' => null])
            ->route('GET /guilds/' . self::G . '/channels', 200, []);
        $this->ctl()->widget($this->req());
        $this->assertTrue($this->t->hasHeader(0, 'Authorization: Bot BOTTOKEN-abcdefghij'), 'token moet ontsleuteld worden meegestuurd');
        $this->assertSame(DiscordApi::INVITE_PERMISSIONS, 1056);
    }
}
