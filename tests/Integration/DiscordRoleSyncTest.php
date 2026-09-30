<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Integration;

use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Modules\Discord\DiscordApi;
use CommunityFusion\Modules\Discord\DiscordRoleSync;
use CommunityFusion\Modules\Discord\DiscordStore;
use CommunityFusion\Modules\Settings\SettingsRepository;
use CommunityFusion\Tests\Support\FakeDiscordTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/FakeDiscordTransport.php';

/** Draait alleen met BP_TEST_DB. Geen netwerk: nep-transport. */
final class DiscordRoleSyncTest extends TestCase
{
    private const G = '123456789012345678';
    private const DU = '423456789012345678';   // Discord-gebruiker
    private const DR = '323456789012345678';   // Discord-rol

    private Connection $db;
    private FakeDiscordTransport $t;
    private int $uid;
    private int $role;

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
        DiscordStore::ensureSchema($this->db);
        $this->wipe();
        $s = new SettingsRepository($this->db, new CacheManager(['path' => sys_get_temp_dir() . '/bp-dcs-' . bin2hex(random_bytes(4))]));
        $s->set('discord', 'guild_id', self::G);
        $s->set('discord', 'bot_token', 'BOTTOKEN-abcdefghij', 'encrypted');

        $tag = bin2hex(random_bytes(4));
        $this->db->execute("INSERT INTO cf_users (username, email, password_hash) VALUES (?, ?, 'x')", ["t_{$tag}", "t_{$tag}@example.test"]);
        $this->uid = (int) $this->db->fetchOne('SELECT id FROM cf_users WHERE username = ?', ["t_{$tag}"])['id'];
        $this->db->execute("INSERT INTO cf_roles (name, display_name, priority) VALUES (?, 'Sync test', 1)", ["t_{$tag}"]);
        $this->role = (int) $this->db->fetchOne('SELECT id FROM cf_roles WHERE name = ?', ["t_{$tag}"])['id'];
        $this->db->execute("INSERT INTO cf_user_oauth (user_id, provider, provider_user_id, access_token) VALUES (?, 'discord', ?, 'x')", [$this->uid, self::DU]);
        $this->t = new FakeDiscordTransport();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->wipe();
            $this->db->execute("DELETE FROM cf_users WHERE username LIKE 't\\_%'");
            $this->db->execute("DELETE FROM cf_roles WHERE name LIKE 't\\_%'");
        }
    }

    private function wipe(): void
    {
        $this->db->execute('DELETE FROM cf_discord_role_mapping');
        $this->db->execute('DELETE FROM cf_discord_sync_log');
        $this->db->execute("DELETE FROM cf_settings WHERE `group` = 'discord'");
    }

    private function sync(): DiscordRoleSync
    {
        return new DiscordRoleSync($this->db, null, new DiscordApi('BOTTOKEN-abcdefghij', $this->t));
    }

    private function map(string $discordRole, int $cmsRole, int $autoRemove = 1): void
    {
        $this->db->execute('INSERT INTO cf_discord_role_mapping (discord_role_id, cms_role_id, auto_remove) VALUES (?, ?, ?)', [$discordRole, $cmsRole, $autoRemove]);
    }

    private function has(int $role): bool
    {
        return $this->db->fetchOne('SELECT 1 x FROM cf_user_roles WHERE user_id = ? AND role_id = ?', [$this->uid, $role]) !== null;
    }

    private function roleId(string $name): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM cf_roles WHERE name = ?', [$name])['id'];
    }

    private function logActions(): array
    {
        return array_column($this->db->fetchAll('SELECT action FROM cf_discord_sync_log WHERE user_id = ? ORDER BY id', [$this->uid]), 'action');
    }

    #[Test]
    public function addsTheCmsRoleWhenDiscordHasTheRole(): void
    {
        $this->map(self::DR, $this->role);
        $this->t->route('GET /guilds/' . self::G . '/members/' . self::DU, 200, ['roles' => [self::DR, '555555555555555555']]);
        $r = $this->sync()->syncUser($this->uid);
        $this->assertSame('ok', $r['status']);
        $this->assertSame([$this->role], $r['added']);
        $this->assertTrue($this->has($this->role));
        $this->assertSame(['role_added'], $this->logActions());
        $this->assertTrue($this->t->hasHeader(0, 'Authorization: Bot BOTTOKEN-abcdefghij'));
    }

    #[Test]
    public function removesTheCmsRoleOnlyWithAutoRemove(): void
    {
        $other = $this->role;
        $this->db->execute("INSERT INTO cf_roles (name, display_name, priority) VALUES (?, 'Sync test 2', 1)", ['t_second' . bin2hex(random_bytes(3))]);
        $second = (int) $this->db->fetchOne("SELECT id FROM cf_roles WHERE name LIKE 't\\_second%'")['id'];
        $this->map(self::DR, $other, 1);
        $this->map('333456789012345678', $second, 0);
        $this->db->execute('INSERT INTO cf_user_roles (user_id, role_id) VALUES (?, ?), (?, ?)', [$this->uid, $other, $this->uid, $second]);
        $this->t->route('GET /guilds/' . self::G . '/members/' . self::DU, 200, ['roles' => []]);
        $r = $this->sync()->syncUser($this->uid);
        $this->assertSame([$other], $r['removed']);
        $this->assertFalse($this->has($other));
        $this->assertTrue($this->has($second), 'auto_remove=0 laat de rol staan');
        $this->db->execute('DELETE FROM cf_roles WHERE id = ?', [$second]);
    }

    #[Test]
    public function twoDiscordRolesToTheSameCmsRoleDoNotFightEachOther(): void
    {
        $this->map(self::DR, $this->role);
        $this->map('333456789012345678', $this->role);
        $this->t->route('GET /guilds/' . self::G . '/members/' . self::DU, 200, ['roles' => [self::DR]]);
        $r = $this->sync()->syncUser($this->uid);
        $this->assertSame([$this->role], $r['added']);
        $this->assertSame([], $r['removed']);
        $this->assertTrue($this->has($this->role), 'rol blijft staan als minstens één gekoppelde Discord-rol aanwezig is');
    }

    #[Test]
    public function autoRemoveLeavesManuallyAssignedRolesAlone(): void
    {
        $this->map(self::DR, $this->role, 1);
        $this->db->execute('INSERT INTO cf_user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)', [$this->uid, $this->role, $this->uid]);
        $this->t->route('GET /guilds/' . self::G . '/members/' . self::DU, 200, ['roles' => []]);
        $r = $this->sync()->syncUser($this->uid);
        $this->assertSame([], $r['removed']);
        $this->assertTrue($this->has($this->role), 'handmatig toegekende rol blijft');
    }

    #[Test]
    public function protectedRolesAreNeverGrantedNorRevoked(): void
    {
        $admin = $this->roleId('admin');
        $super = $this->roleId('super_admin');
        // iemand heeft (via SQL, buiten de admin-UI om) een koppeling naar admin gezet
        $this->map(self::DR, $admin);
        $this->map('333456789012345678', $super);
        $this->t->route('GET /guilds/' . self::G . '/members/' . self::DU, 200, ['roles' => [self::DR, '333456789012345678']]);
        $r = $this->sync()->syncUser($this->uid);
        $this->assertFalse($this->has($admin), 'admin mag nooit via Discord worden toegekend');
        $this->assertFalse($this->has($super));
        $this->assertSame([], $r['added']);
        $this->assertSame([$admin, $super], $r['blocked']);
        $this->assertSame(['role_blocked', 'role_blocked'], $this->logActions());

        // en ook niet intrekken
        $this->db->execute('INSERT INTO cf_user_roles (user_id, role_id) VALUES (?, ?)', [$this->uid, $admin]);
        $this->t->route('GET /guilds/' . self::G . '/members/' . self::DU, 200, ['roles' => []]);
        $this->sync()->syncUser($this->uid);
        $this->assertTrue($this->has($admin), 'admin mag nooit via Discord worden ingetrokken');
    }

    #[Test]
    public function applyRolesIsIdempotent(): void
    {
        $this->map(self::DR, $this->role);
        $sync = $this->sync();
        $sync->applyRoles($this->uid, [self::DR]);
        $r = $sync->applyRoles($this->uid, [self::DR]);
        $this->assertSame([], $r['added']);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM cf_user_roles WHERE user_id = ?', [$this->uid])['c']);
    }

    #[Test]
    public function userWithoutDiscordLinkOrWithoutConfigMakesNoRequest(): void
    {
        $this->map(self::DR, $this->role);
        $this->db->execute("DELETE FROM cf_user_oauth WHERE user_id = ?", [$this->uid]);
        $this->assertSame('no_link', $this->sync()->syncUser($this->uid)['status']);
        $this->assertSame('invalid_user', $this->sync()->syncUser(0)['status']);
        $this->db->execute("INSERT INTO cf_user_oauth (user_id, provider, provider_user_id, access_token) VALUES (?, 'discord', ?, 'x')", [$this->uid, self::DU]);
        $this->db->execute("DELETE FROM cf_settings WHERE `group` = 'discord' AND `key` = 'bot_token'");
        $this->assertSame('not_configured', $this->sync()->syncUser($this->uid)['status']);
        $this->assertCount(0, $this->t->requests);
        $this->assertFalse($this->has($this->role));
    }

    #[Test]
    public function notInGuildLeavesRolesUntouchedAndIsLogged(): void
    {
        $this->map(self::DR, $this->role);
        $this->db->execute('INSERT INTO cf_user_roles (user_id, role_id) VALUES (?, ?)', [$this->uid, $this->role]);
        $this->t->route('GET /guilds/' . self::G . '/members/' . self::DU, 404, ['message' => 'Unknown Member', 'code' => 10007]);
        $r = $this->sync()->syncUser($this->uid);
        $this->assertSame('not_in_guild', $r['status']);
        $this->assertTrue($this->has($this->role));
        $this->assertSame(['not_in_guild'], $this->logActions());
    }

    #[Test]
    public function discordErrorsLeaveRolesUntouchedAndTransientOnesAreRetryable(): void
    {
        $this->map(self::DR, $this->role);
        $this->db->execute('INSERT INTO cf_user_roles (user_id, role_id) VALUES (?, ?)', [$this->uid, $this->role]);
        foreach ([[401, false], [403, false], [429, true], [500, true]] as [$status, $retry]) {
            $t = (new FakeDiscordTransport())->route('GET /guilds/' . self::G . '/members/' . self::DU, $status, ['message' => 'x']);
            $r = (new DiscordRoleSync($this->db, null, new DiscordApi('BOTTOKEN-abcdefghij', $t)))->syncUser($this->uid);
            $this->assertSame('error', $r['status'], (string) $status);
            $this->assertSame($retry, $r['retryable'], (string) $status);
            $this->assertTrue($this->has($this->role), 'bij een fout blijft alles zoals het was');
        }
    }
}
