<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Integration;

use CommunityFusion\Core\Database\ColumnMigrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Draait alleen als er een wegwerpdatabase is opgegeven:
 *   BP_TEST_DSN="mysql:host=127.0.0.1;dbname=bp_test;charset=utf8mb4" BP_TEST_USER=bp BP_TEST_PASS=... composer test
 */
final class ColumnMigratorTest extends TestCase
{
    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        $dsn = getenv('BP_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('BP_TEST_DSN niet gezet.');
        }
        $this->pdo = new \PDO($dsn, (string)getenv('BP_TEST_USER'), (string)getenv('BP_TEST_PASS'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('DROP TABLE IF EXISTS `cf_zz_migrator_test`');
        $this->pdo->exec('CREATE TABLE `cf_zz_migrator_test` (`id` INT NOT NULL) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        $this->pdo?->exec('DROP TABLE IF EXISTS `cf_zz_migrator_test`');
    }

    #[Test]
    public function addsAMissingColumnOnceAndIsIdempotent(): void
    {
        $this->assertFalse(ColumnMigrator::columnExists($this->pdo, 'cf_zz_migrator_test', 'note'));
        $this->assertTrue(ColumnMigrator::ensureColumn($this->pdo, 'cf_zz_migrator_test', 'note', 'TEXT NULL'));
        $this->assertTrue(ColumnMigrator::columnExists($this->pdo, 'cf_zz_migrator_test', 'note'));
        $this->assertFalse(ColumnMigrator::ensureColumn($this->pdo, 'cf_zz_migrator_test', 'note', 'TEXT NULL'));
    }

    #[Test]
    public function runSkipsTablesThatDoNotExistAndReportsWhatItAdded(): void
    {
        $cols = ['cf_zz_migrator_test' => ['a' => 'INT NULL', 'b' => 'TEXT NULL'], 'cf_zz_does_not_exist' => ['x' => 'INT NULL']];
        $this->assertSame(['cf_zz_migrator_test.a', 'cf_zz_migrator_test.b'], ColumnMigrator::run($this->pdo, $cols));
        $this->assertSame([], ColumnMigrator::run($this->pdo, $cols));
    }

    #[Test]
    public function rejectsUnsafeIdentifiersAndDefinitions(): void
    {
        foreach ([['cf_x`; DROP TABLE cf_users; --', 'c', 'INT'], ['cf_zz_migrator_test', 'c`x', 'INT'], ['cf_zz_migrator_test', 'c', 'INT; DROP TABLE cf_users'], ['Cf_Upper', 'c', 'INT']] as [$t, $c, $d]) {
            try {
                ColumnMigrator::ensureColumn($this->pdo, $t, $c, $d);
                $this->fail('Had geweigerd moeten worden.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertTrue(ColumnMigrator::tableExists($this->pdo, 'cf_zz_migrator_test'));
    }

    #[Test]
    public function theExpectedContentMarkupColumnsAreDeclared(): void
    {
        foreach (['cf_pages', 'cf_news', 'cf_blog_posts'] as $table) {
            $this->assertArrayHasKey('content_markup', ColumnMigrator::COLUMNS[$table]);
        }
    }
}
