<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Auth;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Auth\LoginThrottle;
use CommunityFusion\Core\Database\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Draait tegen SQLite in het geheugen; NOW()/UNIX_TIMESTAMP() worden nagebootst
 * zodat dezelfde SQL als op MariaDB getest wordt (zonder echte database).
 */
final class LoginThrottleTest extends TestCase
{
    private \PDO $pdo;
    private Connection $db;

    private function setUpDb(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->sqliteCreateFunction('NOW', static fn() => gmdate('Y-m-d H:i:s'), 0);
        $this->pdo->sqliteCreateFunction('UNIX_TIMESTAMP', static fn($v = null) => $v === null ? time() : strtotime($v . ' UTC'), -1);
        $this->pdo->exec("CREATE TABLE cf_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, username TEXT, action TEXT, context TEXT, ip_address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
            CREATE TABLE cf_users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT, password_hash TEXT, is_active INT DEFAULT 1, deleted_at TEXT, last_login_at TEXT, last_login_ip TEXT);");
        $rc = new \ReflectionClass(Connection::class);
        $this->db = $rc->newInstanceWithoutConstructor();
        $rc->getProperty('pdo')->setValue($this->db, $this->pdo);
        $rc->getProperty('prefix')->setValue($this->db, 'cf_');
    }

    private function addFailures(string $ip, int $n, int $minutesAgo = 0): void
    {
        $at = gmdate('Y-m-d H:i:s', time() - $minutesAgo * 60);
        for ($i = 0; $i < $n; $i++) {
            $this->pdo->prepare("INSERT INTO cf_audit_log (action, ip_address, created_at) VALUES ('auth.login_failed', ?, ?)")->execute([$ip, $at]);
        }
    }

    private function auth(): AuthManager
    {
        $rc  = new \ReflectionClass(AuthManager::class);
        $am  = $rc->newInstanceWithoutConstructor();
        $rc->getProperty('db')->setValue($am, $this->db);
        $rc->getProperty('audit')->setValue($am, new AuditLogger($this->db));
        return $am;
    }

    private function logCount(string $action): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) c FROM cf_audit_log WHERE action = '{$action}'")->fetch()['c'];
    }

    #[Test]
    public function blocks_after_five_failures_from_the_same_ip(): void
    {
        $this->setUpDb();
        $t = new LoginThrottle($this->db);
        $this->addFailures('1.2.3.4', 4);
        $this->assertFalse($t->isBlocked('1.2.3.4'), '4 pogingen: nog niet');
        $this->addFailures('1.2.3.4', 1);
        $this->assertTrue($t->isBlocked('1.2.3.4'), '5 pogingen: geblokkeerd');
        $this->assertFalse($t->isBlocked('9.9.9.9'), 'ander IP onaangetast');
    }

    #[Test]
    public function old_failures_fall_out_of_the_window(): void
    {
        $this->setUpDb();
        $this->addFailures('1.2.3.4', 10, 16);
        $this->assertFalse((new LoginThrottle($this->db))->isBlocked('1.2.3.4'), '16 minuten geleden telt niet meer');
        $this->addFailures('1.2.3.4', 5, 14);
        $this->assertTrue((new LoginThrottle($this->db))->isBlocked('1.2.3.4'), '14 minuten geleden telt nog wel');
    }

    #[Test]
    public function attempt_refuses_even_a_correct_password_while_blocked(): void
    {
        $this->setUpDb();
        $this->pdo->prepare("INSERT INTO cf_users (username, email, password_hash) VALUES ('ouwe','o@x.nl',?)")->execute([password_hash('geheim', PASSWORD_BCRYPT)]);
        $_SERVER['REMOTE_ADDR'] = '5.5.5.5';
        $this->addFailures('5.5.5.5', 5);
        $before = $this->logCount('auth.login_failed');

        $this->assertFalse($this->auth()->attempt('ouwe', 'geheim', startSession: false));
        $this->assertSame($before, $this->logCount('auth.login_failed'), 'geblokkeerde poging telt niet als mislukking');
        $this->assertSame(1, $this->logCount('auth.login_blocked'));
        $this->assertSame(0, $this->logCount('auth.login'), 'geen geslaagde login');
    }

    #[Test]
    public function unknown_user_failures_are_counted_and_valid_login_still_works(): void
    {
        $this->setUpDb();
        $this->pdo->prepare("INSERT INTO cf_users (username, email, password_hash) VALUES ('ouwe','o@x.nl',?)")->execute([password_hash('geheim', PASSWORD_BCRYPT)]);
        $_SERVER['REMOTE_ADDR'] = '6.6.6.6';
        $am = $this->auth();

        for ($i = 0; $i < 4; $i++) {
            $this->assertFalse($am->attempt('bestaat-niet', 'x', startSession: false));
        }
        $this->assertSame(4, $this->logCount('auth.login_failed'), 'onbekende gebruikers tellen mee');
        $this->assertFalse($am->isLoginBlocked());

        $this->assertTrue($am->attempt('ouwe', 'geheim', startSession: false), 'correct wachtwoord onder de limiet werkt');
        $this->assertFalse($am->attempt('ouwe', 'fout', startSession: false));
        $this->assertTrue($am->isLoginBlocked(), 'vijfde mislukking blokkeert');
    }

    #[Test]
    public function fails_open_when_the_audit_table_is_missing(): void
    {
        $this->setUpDb();
        $this->pdo->exec('DROP TABLE cf_audit_log');
        $this->assertFalse((new LoginThrottle($this->db))->isBlocked('1.1.1.1'));
    }
}
