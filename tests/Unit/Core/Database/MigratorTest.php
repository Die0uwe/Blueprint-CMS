<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Database;

use CommunityFusion\Core\Database\Migrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private \PDO $pdo;
    private string $dir;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->dir = sys_get_temp_dir() . '/cf_mig_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*.php') ?: []);
        @rmdir($this->dir);
    }

    private function add(string $name, string $body): void
    {
        file_put_contents("{$this->dir}/{$name}.php", "<?php return function (\\PDO \$pdo, string \$prefix): void { {$body} };");
    }

    #[Test]
    public function runsPendingInOrderAndOnlyOnce(): void
    {
        $this->add('20260102_01_b', '$pdo->exec("INSERT INTO {$prefix}log VALUES (\'b\')");');
        $this->add('20260101_01_a', '$pdo->exec("CREATE TABLE {$prefix}log (v TEXT)"); $pdo->exec("INSERT INTO {$prefix}log VALUES (\'a\')");');
        $m = new Migrator($this->pdo, $this->dir);

        $this->assertSame(['20260101_01_a', '20260102_01_b'], $m->run());
        $this->assertSame(['a', 'b'], $this->pdo->query('SELECT v FROM cf_log')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame([], $m->run());
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM cf_log')->fetchColumn());
    }

    #[Test]
    public function failureStopsRunAndKeepsEarlierOnesRegistered(): void
    {
        $this->add('20260101_01_ok', '$pdo->exec("CREATE TABLE {$prefix}t (v TEXT)");');
        $this->add('20260101_02_bad', '$pdo->exec("INSERT INTO nope VALUES (1)");');
        $this->add('20260101_03_never', '$pdo->exec("CREATE TABLE {$prefix}never (v TEXT)");');
        $m = new Migrator($this->pdo, $this->dir);

        try {
            $m->run();
            $this->fail('verwachtte een exception');
        } catch (\PDOException) {
        }
        $this->assertSame(['20260101_01_ok'], array_keys($m->applied()));
        $this->assertSame(['20260101_02_bad', '20260101_03_never'], $m->pending());
    }

    #[Test]
    public function newBatchNumberAndStatus(): void
    {
        $this->add('20260101_01_a', '$pdo->exec("CREATE TABLE {$prefix}t (v TEXT)");');
        $m = new Migrator($this->pdo, $this->dir);
        $m->run();
        $this->add('20260102_01_b', '');
        $m->run();

        $this->assertSame(
            [['migration' => '20260101_01_a', 'status' => 'applied', 'batch' => 1], ['migration' => '20260102_01_b', 'status' => 'applied', 'batch' => 2]],
            $m->status()
        );
    }

    #[Test]
    public function rejectsFileWithoutClosure(): void
    {
        file_put_contents("{$this->dir}/20260101_01_x.php", '<?php return 5;');
        $this->expectException(\RuntimeException::class);
        (new Migrator($this->pdo, $this->dir))->run();
    }

    #[Test]
    public function shippedThrottleIndexMigrationIsIdempotent(): void
    {
        $this->pdo->exec('CREATE TABLE cf_audit_log (id INTEGER PRIMARY KEY, action TEXT, ip_address TEXT, created_at TEXT)');
        $fn = require dirname(__DIR__, 4) . '/database/migrations/20261004_01_audit_log_throttle_index.php';
        $fn($this->pdo, 'cf_');
        $fn($this->pdo, 'cf_');
        $idx = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_throttle'")->fetchColumn();
        $this->assertSame('idx_throttle', $idx);
    }
}
