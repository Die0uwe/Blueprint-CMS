<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Integration;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Auth\JWTManager;
use CommunityFusion\Core\Auth\OAuth\OAuthAccountDisabledException;
use CommunityFusion\Core\Auth\OAuth\OAuthLoginFlow;
use CommunityFusion\Core\Auth\OAuth\OAuthProviders;
use CommunityFusion\Core\Auth\RBAC\RBACManager;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Draait tegen een echte MariaDB/MySQL met schema.sql (zie PasswordResetServiceTest).
 * Zonder CF_TEST_DB_NAME worden de tests overgeslagen.
 */
final class OAuthAccountTest extends TestCase
{
    private Connection $db;
    private AuthManager $auth;

    protected function setUp(): void
    {
        $name = getenv('CF_TEST_DB_NAME');
        if ($name === false || $name === '') {
            self::markTestSkipped('Geen testdatabase (zet CF_TEST_DB_NAME).');
        }
        $this->db = new Connection([
            'host'     => getenv('CF_TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => (int) (getenv('CF_TEST_DB_PORT') ?: 3306),
            'name'     => $name,
            'user'     => getenv('CF_TEST_DB_USER') ?: 'root',
            'password' => getenv('CF_TEST_DB_PASS') ?: '',
            'prefix'   => 'cf_',
        ]);
        $this->clean();

        $cache      = new CacheManager(['driver' => 'file', 'path' => sys_get_temp_dir() . '/cf_oauth_test_cache']);
        $this->auth = new AuthManager(
            $this->db,
            new RBACManager($this->db, $cache),
            new JWTManager(str_repeat('s', 32)),
            new AuditLogger($this->db),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->clean();
        }
    }

    private function clean(): void
    {
        $this->db->execute("DELETE FROM cf_users WHERE username LIKE 'oauthtest%'");
        $this->db->execute("DELETE FROM cf_users WHERE email LIKE '%@oauthtest.example.org'");
        $this->db->execute("DELETE FROM cf_user_oauth WHERE provider_user_id LIKE 'oauthtest-%'");
        $this->db->execute("DELETE FROM cf_settings WHERE `group` IN ('github','google','discord','twitch','battlenet')");
        $this->db->execute("DELETE FROM cf_modules WHERE slug IN ('github','google','discord','twitch','battlenet')");
    }

    private function link(int $userId, string $provider, string $pid): void
    {
        $this->db->insert('user_oauth', [
            'user_id' => $userId, 'provider' => $provider, 'provider_user_id' => $pid, 'access_token' => 'x',
        ]);
    }

    private function user(string $username, string $email, array $extra = []): int
    {
        return (int) $this->db->insert('users', [
            'username' => $username, 'email' => $email, 'password_hash' => 'x',
        ] + $extra);
    }

    // ── findOrCreateFromOAuth ──────────────────────────────────────────

    #[Test]
    public function newIdentityCreatesAVerifiedAccountWithTheDefaultRole(): void
    {
        $user = $this->auth->findOrCreateFromOAuth('github', 'oauthtest-1', [
            'username' => 'oauthtest_octo', 'email' => 'octo@oauthtest.example.org', 'email_verified' => true,
        ]);

        self::assertSame('oauthtest_octo', $user['username']);
        self::assertSame('octo@oauthtest.example.org', $user['email']);
        self::assertNotNull($user['email_verified_at']);
        $role = $this->db->fetchOne(
            'SELECT r.name FROM cf_user_roles ur JOIN cf_roles r ON r.id = ur.role_id WHERE ur.user_id = ?',
            [$user['id']]
        );
        self::assertSame('member', $role['name']);
    }

    #[Test]
    public function aUsedEmailAddressIsNeverTakenOver(): void
    {
        $existing = $this->user('oauthtest_local', 'local@oauthtest.example.org');

        $user = $this->auth->findOrCreateFromOAuth('google', 'oauthtest-2', [
            'username' => 'oauthtest_g', 'email' => 'local@oauthtest.example.org', 'email_verified' => true,
        ]);

        self::assertNotSame($existing, (int) $user['id']);
        self::assertSame('google-oauthtest-2@users.noreply.invalid', $user['email']);
        $stillLocal = $this->db->fetchOne('SELECT email FROM cf_users WHERE id = ?', [$existing]);
        self::assertSame('local@oauthtest.example.org', $stillLocal['email']);
    }

    #[Test]
    public function aKnownIdentityReturnsTheSameAccount(): void
    {
        $profile = ['username' => 'oauthtest_same', 'email' => 'same@oauthtest.example.org', 'email_verified' => true];
        $first   = $this->auth->findOrCreateFromOAuth('discord', 'oauthtest-3', $profile);
        $this->link((int) $first['id'], 'discord', 'oauthtest-3');
        $again   = $this->auth->findOrCreateFromOAuth('discord', 'oauthtest-3', $profile);

        self::assertSame((int) $first['id'], (int) $again['id']);
    }

    #[Test]
    public function aDeactivatedAccountCannotLogInAndGetsNoNewAccount(): void
    {
        $id = $this->user('oauthtest_banned', 'banned@oauthtest.example.org', ['is_active' => 0]);
        $this->link($id, 'discord', 'oauthtest-4');
        $before = $this->db->fetchOne('SELECT COUNT(*) AS n FROM cf_users')['n'];

        try {
            $this->auth->findOrCreateFromOAuth('discord', 'oauthtest-4', ['username' => 'oauthtest_banned2']);
            self::fail('Een gedeactiveerd account mag niet inloggen.');
        } catch (OAuthAccountDisabledException) {
            // verwacht
        }
        self::assertSame($before, $this->db->fetchOne('SELECT COUNT(*) AS n FROM cf_users')['n']);
    }

    #[Test]
    public function aDeletedAccountCannotLogIn(): void
    {
        $id = $this->user('oauthtest_gone', 'gone@oauthtest.example.org', ['deleted_at' => date('Y-m-d H:i:s')]);
        $this->link($id, 'github', 'oauthtest-5');

        $this->expectException(OAuthAccountDisabledException::class);
        $this->auth->findOrCreateFromOAuth('github', 'oauthtest-5', ['username' => 'oauthtest_gone2']);
    }

    // ── OAuthProviders::usable ─────────────────────────────────────────

    private function enable(string $slug, bool $enabled = true): void
    {
        $this->db->execute(
            'INSERT INTO cf_modules (slug, name, version, is_core, is_enabled) VALUES (?, ?, ?, 0, ?)',
            [$slug, $slug, '1.0.0', $enabled ? 1 : 0]
        );
    }

    private function setting(string $group, string $key, string $value): void
    {
        $this->db->execute(
            'INSERT INTO cf_settings (`group`, `key`, `value`) VALUES (?, ?, ?)',
            [$group, $key, $value]
        );
    }

    #[Test]
    public function aProviderIsOnlyUsableWithAnEnabledModuleAndBothKeys(): void
    {
        self::assertSame([], (new OAuthProviders($this->db))->usable());

        $this->enable('github');
        self::assertSame([], (new OAuthProviders($this->db))->usable(), 'module aan, nog geen sleutels');

        $this->setting('github', 'client_id', 'abc');
        self::assertSame([], (new OAuthProviders($this->db))->usable(), 'alleen client_id');

        $this->setting('github', 'client_secret', 'versleuteld');
        $usable = (new OAuthProviders($this->db))->usable();
        self::assertSame(['github'], array_keys($usable));
        self::assertSame('/auth/github/login', $usable['github']['login_url']);
        self::assertSame('/auth/github', $usable['github']['link_url']);
    }

    #[Test]
    public function aDisabledModuleIsNotUsableEvenWithKeys(): void
    {
        $this->enable('google', false);
        $this->setting('google', 'client_id', 'abc');
        $this->setting('google', 'client_secret', 'def');

        self::assertSame([], (new OAuthProviders($this->db))->usable());
    }

    #[Test]
    public function emptyKeyValuesDoNotCount(): void
    {
        $this->enable('discord');
        $this->setting('discord', 'client_id', 'abc');
        $this->setting('discord', 'client_secret', '');

        self::assertFalse((new OAuthProviders($this->db))->isUsable('discord'));
    }

    #[Test]
    public function usableProvidersKeepTheCatalogOrder(): void
    {
        foreach (['discord', 'github', 'google'] as $slug) {
            $this->enable($slug);
            $this->setting($slug, 'client_id', 'i');
            $this->setting($slug, 'client_secret', 's');
        }

        self::assertSame(['github', 'google', 'discord'], array_keys((new OAuthProviders($this->db))->usable()));
    }

    // ── OAuthLoginFlow::canDisconnect ──────────────────────────────────

    private function flow(): OAuthLoginFlow
    {
        return new OAuthLoginFlow($this->auth, $this->db, new AuditLogger($this->db));
    }

    #[Test]
    public function aRealEmailAddressAlwaysAllowsDisconnecting(): void
    {
        $id = $this->user('oauthtest_real', 'real@oauthtest.example.org');
        $this->link($id, 'github', 'oauthtest-6');

        self::assertTrue($this->flow()->canDisconnect($id, 'github'));
    }

    #[Test]
    public function thePlaceholderAccountsOnlyMethodCannotBeDisconnected(): void
    {
        $id = $this->user('oauthtest_ph', 'github-oauthtest-7@users.noreply.invalid');
        $this->link($id, 'github', 'oauthtest-7');
        self::assertFalse($this->flow()->canDisconnect($id, 'github'));

        $this->link($id, 'discord', 'oauthtest-8');
        self::assertTrue($this->flow()->canDisconnect($id, 'github'), 'met een tweede methode mag het wel');
    }
}
