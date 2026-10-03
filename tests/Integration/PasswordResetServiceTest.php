<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Integration;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\PasswordResetService;
use CommunityFusion\Core\Database\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Draait tegen een echte MariaDB/MySQL met het schema uit schema.sql geïmporteerd.
 * Zonder CF_TEST_DB_NAME worden de tests overgeslagen (lokaal zonder database).
 *
 *   CF_TEST_DB_HOST (standaard 127.0.0.1)   CF_TEST_DB_PORT (3306)
 *   CF_TEST_DB_NAME  CF_TEST_DB_USER  CF_TEST_DB_PASS
 */
final class PasswordResetServiceTest extends TestCase
{
    private Connection $db;
    private PasswordResetService $service;
    private int $userId;

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
        $this->db->execute('DELETE FROM cf_password_resets');
        $this->db->execute("DELETE FROM cf_audit_log WHERE action LIKE 'auth.password_reset%'");
        $this->db->execute("DELETE FROM cf_users WHERE username LIKE 'pwtest_%'");
        $this->service = new PasswordResetService($this->db, new AuditLogger($this->db));
        $this->userId  = $this->makeUser('pwtest_anna', 'anna@pwtest.example.org', 'oud-wachtwoord-1');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute("DELETE FROM cf_users WHERE username LIKE 'pwtest_%'");
            $this->db->execute('DELETE FROM cf_password_resets');
            $this->db->execute("DELETE FROM cf_audit_log WHERE action LIKE 'auth.password_reset%'");
        }
    }

    private function makeUser(string $username, string $email, string $password, array $extra = []): int
    {
        return (int) $this->db->insert('users', [
            'username'      => $username,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ] + $extra);
    }

    #[Test]
    public function tokenIsCreatedForEmailAndUsernameAndOnlyTheHashIsStored(): void
    {
        $byMail = $this->service->createToken('anna@pwtest.example.org', '203.0.113.1');
        self::assertNotNull($byMail);
        self::assertTrue(PasswordResetService::isWellFormedToken($byMail['token']));
        self::assertSame($this->userId, $byMail['user']['id']);
        self::assertSame(60, $byMail['ttl_minutes']);

        $stored = $this->db->fetchAll('SELECT token_hash FROM cf_password_resets');
        self::assertCount(1, $stored);
        self::assertSame(hash('sha256', $byMail['token']), $stored[0]['token_hash']);
        self::assertStringNotContainsString($byMail['token'], $stored[0]['token_hash']);

        $byName = $this->service->createToken('pwtest_anna', '203.0.113.2');
        self::assertNotNull($byName);
        self::assertNotSame($byMail['token'], $byName['token']);
    }

    #[Test]
    public function unknownInactiveDeletedAndPlaceholderAccountsGetNoTokenButTheRequestIsLogged(): void
    {
        $this->makeUser('pwtest_off', 'off@pwtest.example.org', 'x', ['is_active' => 0]);
        $this->makeUser('pwtest_del', 'del@pwtest.example.org', 'x', ['deleted_at' => date('Y-m-d H:i:s')]);
        $this->makeUser('pwtest_oauth', 'discord-1@users.noreply.invalid', 'x');

        foreach (['nobody@pwtest.example.org', 'pwtest_off', 'pwtest_del', 'pwtest_oauth', '', str_repeat('a', 300)] as $i => $identifier) {
            self::assertNull($this->service->createToken($identifier, '198.51.100.' . ($i + 1)), $identifier);
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM cf_password_resets')['c']);

        $logged = (int) $this->db->fetchOne(
            "SELECT COUNT(*) c FROM cf_audit_log WHERE action = 'auth.password_reset_requested'"
        )['c'];
        self::assertSame(4, $logged, 'Lege en te lange invoer worden niet gelogd, de andere vier wel.');
    }

    #[Test]
    public function findValidAcceptsTheTokenAndRejectsGarbage(): void
    {
        $t = $this->service->createToken('anna@pwtest.example.org', '203.0.113.1');
        $found = $this->service->findValid($t['token']);
        self::assertNotNull($found);
        self::assertSame($this->userId, $found['user_id']);

        self::assertNull($this->service->findValid(str_repeat('a', 64)));
        self::assertNull($this->service->findValid('kort'));
        self::assertNull($this->service->findValid(strtoupper($t['token'])));
        self::assertNull($this->service->findValid("{$t['token']}' OR '1'='1"));
    }

    #[Test]
    public function resetChangesThePasswordAndTheTokenWorksOnlyOnce(): void
    {
        $t = $this->service->createToken('anna@pwtest.example.org', '203.0.113.1');

        self::assertTrue($this->service->reset($t['token'], 'nieuw-wachtwoord-2'));

        $hash = (string) $this->db->fetchOne('SELECT password_hash FROM cf_users WHERE id = ?', [$this->userId])['password_hash'];
        self::assertTrue(password_verify('nieuw-wachtwoord-2', $hash));
        self::assertFalse(password_verify('oud-wachtwoord-1', $hash));
        self::assertStringStartsWith('$argon2id$', $hash);

        self::assertFalse($this->service->reset($t['token'], 'weer-iets-anders-3'), 'Tweede gebruik moet falen.');
        self::assertNull($this->service->findValid($t['token']));
        self::assertTrue(password_verify('nieuw-wachtwoord-2', (string) $this->db->fetchOne('SELECT password_hash FROM cf_users WHERE id = ?', [$this->userId])['password_hash']));

        $done = (int) $this->db->fetchOne("SELECT COUNT(*) c FROM cf_audit_log WHERE action = 'auth.password_reset_completed'")['c'];
        self::assertSame(1, $done);
    }

    #[Test]
    public function anExpiredTokenIsRejected(): void
    {
        $t = $this->service->createToken('anna@pwtest.example.org', '203.0.113.1');
        $this->db->execute('UPDATE cf_password_resets SET expires_at = NOW() - INTERVAL 1 SECOND');

        self::assertNull($this->service->findValid($t['token']));
        self::assertFalse($this->service->reset($t['token'], 'nieuw-wachtwoord-2'));
        self::assertTrue(password_verify('oud-wachtwoord-1', (string) $this->db->fetchOne('SELECT password_hash FROM cf_users WHERE id = ?', [$this->userId])['password_hash']));
    }

    #[Test]
    public function aNewRequestInvalidatesThePreviousToken(): void
    {
        $first  = $this->service->createToken('anna@pwtest.example.org', '203.0.113.1');
        $second = $this->service->createToken('anna@pwtest.example.org', '203.0.113.1');

        self::assertNull($this->service->findValid($first['token']));
        self::assertNotNull($this->service->findValid($second['token']));
    }

    #[Test]
    public function aShortPasswordDoesNotConsumeTheToken(): void
    {
        $t = $this->service->createToken('anna@pwtest.example.org', '203.0.113.1');

        self::assertFalse($this->service->reset($t['token'], 'kort'));
        self::assertNotNull($this->service->findValid($t['token']), 'Een geweigerd wachtwoord mag het token niet verbruiken.');
    }

    #[Test]
    public function disablingTheAccountKillsOutstandingTokens(): void
    {
        $t = $this->service->createToken('anna@pwtest.example.org', '203.0.113.1');
        $this->db->execute('UPDATE cf_users SET is_active = 0 WHERE id = ?', [$this->userId]);

        self::assertNull($this->service->findValid($t['token']));
        self::assertFalse($this->service->reset($t['token'], 'nieuw-wachtwoord-2'));
    }

    #[Test]
    public function perUserLimitIsThreePerHour(): void
    {
        for ($i = 0; $i < PasswordResetService::MAX_PER_USER_PER_HOUR; $i++) {
            self::assertNotNull($this->service->createToken('anna@pwtest.example.org', '203.0.113.' . (10 + $i)));
        }
        self::assertNull($this->service->createToken('anna@pwtest.example.org', '203.0.113.99'));
    }

    #[Test]
    public function perIpLimitCountsRequestsForUnknownAccountsToo(): void
    {
        for ($i = 0; $i < PasswordResetService::MAX_PER_IP_PER_HOUR; $i++) {
            self::assertNull($this->service->createToken("nobody{$i}@pwtest.example.org", '192.0.2.50'));
        }
        // Zelfde IP, nu een bestaand account: geblokkeerd.
        self::assertNull($this->service->createToken('anna@pwtest.example.org', '192.0.2.50'));
        // Ander IP: gewoon toegestaan.
        self::assertNotNull($this->service->createToken('anna@pwtest.example.org', '192.0.2.51'));
    }

    #[Test]
    public function sessionsOlderThanTheResetAreRevoked(): void
    {
        $t = $this->service->createToken('anna@pwtest.example.org', '203.0.113.1');
        $before = time() - 600;

        self::assertFalse(PasswordResetService::sessionRevoked($this->db, $this->userId, $before), 'Nog geen reset voltooid.');

        self::assertTrue($this->service->reset($t['token'], 'nieuw-wachtwoord-2'));

        self::assertTrue(PasswordResetService::sessionRevoked($this->db, $this->userId, $before), 'Oude sessie moet ongeldig zijn.');
        self::assertFalse(PasswordResetService::sessionRevoked($this->db, $this->userId, time() + 5), 'Sessie van na de reset blijft geldig.');
        self::assertFalse(PasswordResetService::sessionRevoked($this->db, $this->userId, 0), 'Zonder login_time niets intrekken.');
        self::assertFalse(PasswordResetService::sessionRevoked($this->db, $this->userId + 9999, $before), 'Andere gebruiker blijft ongemoeid.');
    }

    #[Test]
    public function sessionRevokedFailsSafeWhenTheTableIsMissing(): void
    {
        $this->db->execute('DROP TABLE cf_password_resets');
        try {
            self::assertFalse(PasswordResetService::sessionRevoked($this->db, $this->userId, time() - 10));
        } finally {
            // Tabel terugzetten voor de volgende tests.
            $this->db->execute(
                'CREATE TABLE cf_password_resets (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT, user_id INT UNSIGNED NOT NULL,
                    token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL,
                    requested_ip VARCHAR(45) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id), UNIQUE KEY uq_token_hash (token_hash),
                    KEY idx_user_created (user_id, created_at), KEY idx_user_used (user_id, used_at),
                    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES cf_users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
    }
}
