<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Database;

use CommunityFusion\Core\Database\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Connection::insert(): kolommen worden gequote (gereserveerde woorden) en gevalideerd. */
final class ConnectionInsertTest extends TestCase
{
    private Connection $db;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->exec('CREATE TABLE cf_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `group` TEXT, `key` TEXT, `value` TEXT)');
        $rc = new \ReflectionClass(Connection::class);
        $this->db = $rc->newInstanceWithoutConstructor();
        foreach (['pdo' => $this->pdo, 'prefix' => 'cf_'] as $prop => $val) {
            $p = $rc->getProperty($prop);
            $p->setValue($this->db, $val);
        }
    }

    #[Test]
    public function insertsRowsWithReservedWordColumns(): void
    {
        $this->db->insert('settings', ['group' => 'core', 'key' => 'site_name', 'value' => 'X']);
        $row = $this->pdo->query('SELECT `group`, `key`, `value` FROM cf_settings')->fetch();
        $this->assertSame(['group' => 'core', 'key' => 'site_name', 'value' => 'X'], $row);
    }

    #[Test]
    public function rejectsSuspiciousColumnNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->insert('settings', ['key) VALUES (1); --' => 'x']);
    }
}
