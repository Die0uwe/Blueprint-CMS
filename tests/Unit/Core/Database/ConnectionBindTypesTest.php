<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Database;

use CommunityFusion\Core\Database\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Waarden worden met het juiste PDO-type gebonden (MariaDB weigert een string in LIMIT/OFFSET). */
final class ConnectionBindTypesTest extends TestCase
{
    #[Test]
    public function intsNullsAndBoolsAreBoundWithTheirNativeType(): void
    {
        $spy = SpyStatement::class;
        $spy::$types = [];
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [$spy]);
        $pdo->exec('CREATE TABLE cf_t (id INTEGER)');

        $rc = new \ReflectionClass(Connection::class);
        $db = $rc->newInstanceWithoutConstructor();
        $rc->getProperty('pdo')->setValue($db, $pdo);
        $rc->getProperty('prefix')->setValue($db, 'cf_');

        $db->fetchAll('SELECT id FROM cf_t WHERE id = ? OR id IS ? LIMIT ? OFFSET ?', [5, null, 10, 20]);

        $this->assertSame(\PDO::PARAM_INT, $spy::$types[1]);
        $this->assertSame(\PDO::PARAM_NULL, $spy::$types[2]);
        $this->assertSame(\PDO::PARAM_INT, $spy::$types[3]);   // LIMIT
        $this->assertSame(\PDO::PARAM_INT, $spy::$types[4]);   // OFFSET
    }
}

/** PDO maakt zelf instanties van deze klasse (ATTR_STATEMENT_CLASS) en legt de bind-types vast. */
class SpyStatement extends \PDOStatement
{
    /** @var array<int,int> */
    public static array $types = [];

    protected function __construct() {}

    public function bindValue(string|int $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        self::$types[(int) $param] = $type;
        return parent::bindValue($param, $value, $type);
    }
}
