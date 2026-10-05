<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Modules;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Modules\Users\AccountService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Extra e-mailadressen (max 3, hoofdadres) + accounts samenvoegen, tegen SQLite in het geheugen. */
final class AccountServiceTest extends TestCase
{
    private Connection $db;
    private AccountService $svc;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $pdo->exec("CREATE TABLE cf_users (id INTEGER PRIMARY KEY, username TEXT, email TEXT UNIQUE, email_verified_at TEXT, is_verified INT DEFAULT 0, is_active INT DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT);
            CREATE TABLE cf_user_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, email TEXT UNIQUE, is_primary INT DEFAULT 0, verified_at TEXT, token_hash TEXT, token_expires_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
            CREATE TABLE cf_user_oauth (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, provider TEXT, provider_user_id TEXT);
            CREATE TABLE cf_roles (id INTEGER PRIMARY KEY, name TEXT);
            CREATE TABLE cf_user_roles (user_id INT, role_id INT, assigned_by INT);
            CREATE TABLE cf_news (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INT, title TEXT);
            CREATE TABLE cf_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, username TEXT, action TEXT, context TEXT, ip_address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
            INSERT INTO cf_roles VALUES (1,'member'),(2,'admin');
            INSERT INTO cf_users (id,username,email,email_verified_at) VALUES (1,'keep','keep@example.org','2026-01-01 00:00:00'),(2,'drop','drop@example.org','2026-01-01 00:00:00');");
        $rc = new \ReflectionClass(Connection::class);
        $this->db = $rc->newInstanceWithoutConstructor();
        $rc->getProperty('pdo')->setValue($this->db, $pdo);
        $rc->getProperty('prefix')->setValue($this->db, 'cf_');
        $this->svc = new AccountService($this->db, new AuditLogger($this->db));
    }

    #[Test]
    public function existingEmailBecomesPrimaryRow(): void
    {
        $e = $this->svc->emails(1);
        $this->assertCount(1, $e);
        $this->assertSame('keep@example.org', $e[0]['email']);
        $this->assertSame(1, (int) $e[0]['is_primary']);
    }

    #[Test]
    public function maxThreeEmailsPerAccount(): void
    {
        $this->assertArrayHasKey('token', $this->svc->addEmail(1, 'a@example.org'));
        $this->assertArrayHasKey('token', $this->svc->addEmail(1, 'b@example.org'));
        $this->assertSame('limit', $this->svc->addEmail(1, 'c@example.org')['error']);
    }

    #[Test]
    public function rejectsInvalidAndTakenAddresses(): void
    {
        $this->assertSame('invalid', $this->svc->addEmail(1, 'geen-email')['error']);
        $this->assertSame('taken', $this->svc->addEmail(1, 'drop@example.org')['error']);
        $this->assertSame('taken', $this->svc->addEmail(1, 'KEEP@example.org')['error']);
    }

    #[Test]
    public function primaryNeedsVerificationAndSyncsUsersEmail(): void
    {
        $tok = $this->svc->addEmail(1, 'new@example.org')['token'];
        $id  = (int) $this->db->fetchOne("SELECT id FROM cf_user_emails WHERE email = 'new@example.org'")['id'];
        $this->assertFalse($this->svc->setPrimary(1, $id), 'onbevestigd adres mag geen hoofdadres worden');
        $this->assertSame(1, $this->svc->verifyEmail($tok));
        $this->assertNull($this->svc->verifyEmail($tok), 'token is eenmalig');
        $this->assertTrue($this->svc->setPrimary(1, $id));
        $this->assertSame('new@example.org', $this->db->fetchOne('SELECT email FROM cf_users WHERE id = 1')['email']);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) AS n FROM cf_user_emails WHERE user_id = 1 AND is_primary = 1')['n']);
    }

    #[Test]
    public function expiredOrForeignTokenIsRejected(): void
    {
        $tok = $this->svc->addEmail(1, 'late@example.org')['token'];
        $this->db->execute("UPDATE cf_user_emails SET token_expires_at = '2000-01-01 00:00:00'");
        $this->assertNull($this->svc->verifyEmail($tok));
        $this->assertNull($this->svc->verifyEmail('zz'));
    }

    #[Test]
    public function cannotSetOthersEmailAsPrimaryOrRemovePrimary(): void
    {
        $this->svc->emails(2);
        $id2 = (int) $this->db->fetchOne("SELECT id FROM cf_user_emails WHERE user_id = 2")['id'];
        $this->assertFalse($this->svc->setPrimary(1, $id2));
        $this->assertFalse($this->svc->removeEmail(2, $id2), 'hoofdadres kan niet verwijderd worden');
    }

    #[Test]
    public function mergeMovesProvidersRolesEmailsAndContent(): void
    {
        $this->db->execute("INSERT INTO cf_user_oauth (user_id, provider, provider_user_id) VALUES (1,'github','g1'),(2,'github','g2'),(2,'discord','d2')");
        $this->db->execute("INSERT INTO cf_user_roles VALUES (1,1,1),(2,1,1),(2,2,1)");
        $this->db->execute("INSERT INTO cf_news (author_id, title) VALUES (2,'x'),(2,'y')");

        $out = $this->svc->merge(1, 2);

        $this->assertSame(1, $out['providers'], 'dubbele dienst: behouden account wint');
        $this->assertSame(1, $out['roles']);
        $this->assertSame(2, $out['content']);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) AS n FROM cf_users WHERE id = 2')['n']);
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) AS n FROM cf_news WHERE author_id = 1')['n']);
        $emails = $this->db->fetchAll('SELECT email, is_primary FROM cf_user_emails WHERE user_id = 1 ORDER BY id');
        $this->assertCount(2, $emails);
        $this->assertSame('keep@example.org', $emails[0]['email']);
        $this->assertSame(1, (int) $emails[0]['is_primary']);
        $this->assertSame(0, (int) $emails[1]['is_primary']);
        $this->assertSame('drop', $out['dropped']);
    }

    #[Test]
    public function mergeKeepsAtMostThreeEmails(): void
    {
        $this->svc->addEmail(1, 'k2@example.org');
        $this->svc->addEmail(2, 'd2@example.org');
        $this->svc->addEmail(2, 'd3@example.org');
        $this->svc->merge(1, 2);
        $this->assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) AS n FROM cf_user_emails WHERE user_id = 1')['n']);
    }

    #[Test]
    public function mergeRefusesSameBlockedOrMissing(): void
    {
        $this->assertSame('same', $this->svc->mergeCheck(1, 1));
        $this->assertSame('missing', $this->svc->mergeCheck(1, 99));
        $this->db->execute('UPDATE cf_users SET is_active = 0 WHERE id = 2');
        $this->assertSame('blocked', $this->svc->mergeCheck(1, 2));
        $this->expectException(\RuntimeException::class);
        $this->svc->merge(1, 2);
    }
}
