<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Plugin;

use CommunityFusion\Core\Marketplace\PackageException;
use CommunityFusion\Core\Plugin\PluginSqlGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PluginSqlGuardTest extends TestCase
{
    private const SLUG = 'mijn-plugin';
    private const T = 'cf_plg_mijn_plugin_items';

    private function ok(string $sql): array
    {
        return (new PluginSqlGuard())->check($sql, self::SLUG);
    }

    private function blocked(string $sql, string $why = ''): void
    {
        try {
            $this->ok($sql);
            $this->fail('Had geweigerd moeten worden: ' . ($why ?: $sql));
        } catch (PackageException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function prefixFollowsTheSlug(): void
    {
        $this->assertSame('cf_plg_mijn_plugin_', PluginSqlGuard::tablePrefix('mijn-plugin'));
    }

    #[Test]
    public function allowsDdlAndDmlOnOwnTables(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `" . self::T . "` (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NULL,
                   naam VARCHAR(50) NOT NULL DEFAULT 'a;b', CONSTRAINT fk_u FOREIGN KEY (user_id) REFERENCES `cf_users`(id) ON DELETE CASCADE) ENGINE=InnoDB;
                ALTER TABLE " . self::T . " ADD COLUMN extra INT NULL;
                CREATE INDEX idx_naam ON " . self::T . " (naam);
                INSERT IGNORE INTO " . self::T . " (naam) VALUES ('x''y'), ('drop table cf_users');
                UPDATE " . self::T . " SET naam = 'z' WHERE id = 1;
                DELETE FROM " . self::T . " WHERE id = 2;
                DROP TABLE IF EXISTS " . self::T . ";";
        $this->assertCount(7, $this->ok($sql));
    }

    #[Test]
    public function aSemicolonInsideAStringDoesNotSplitTheStatement(): void
    {
        $this->assertCount(1, $this->ok("INSERT INTO " . self::T . " (naam) VALUES ('a; DROP TABLE cf_users; --')"));
    }

    #[Test]
    public function blocksCoreTablesAndOtherPlugins(): void
    {
        foreach ([
            'DROP TABLE cf_users', 'DROP TABLE IF EXISTS `cf_users`', 'ALTER TABLE cf_users ADD COLUMN x INT',
            'DELETE FROM cf_users', 'UPDATE cf_users SET x = 1', 'INSERT INTO cf_users (id) VALUES (1)',
            'TRUNCATE TABLE cf_audit_log', 'DROP TABLE cf_plg_andere_plugin_items', 'CREATE TABLE cf_plg_mijn_plugins_x (id INT)',
            'CREATE TABLE cf_zelfgekozen (id INT)', 'CREATE TABLE ' . self::T . ' LIKE cf_users',
            'INSERT INTO ' . self::T . ' SELECT * FROM cf_users', 'ALTER TABLE ' . self::T . ' RENAME TO cf_users',
            'CREATE TABLE ' . self::T . ' (id INT) AS SELECT id FROM cf_users',
        ] as $sql) {
            $this->blocked($sql);
        }
    }

    #[Test]
    public function blocksAllOtherStatementTypesAndDangerousConstructs(): void
    {
        foreach ([
            'SELECT * FROM cf_users', 'GRANT ALL ON *.* TO x', 'SET GLOBAL sql_mode = 1', 'CALL something()', 'DROP DATABASE x',
            'CREATE USER x', 'LOAD DATA INFILE \'/etc/passwd\' INTO TABLE ' . self::T, 'SELECT 1 INTO OUTFILE \'/tmp/x\'',
            'INSERT INTO ' . self::T . " (a) VALUES (LOAD_FILE('/etc/passwd'))", 'INSERT INTO ' . self::T . ' (a) VALUES (SLEEP(99))',
            'INSERT INTO ' . self::T . ' (a) SELECT table_name FROM information_schema.tables',
            'INSERT INTO mysql.user (a) VALUES (1)', 'DROP TABLE somedb.' . self::T, 'INSERT INTO otherdb.' . self::T . ' (a) VALUES (1)',
            '', 'nonsense',
        ] as $sql) {
            if ($sql === '') {
                $this->assertSame([], $this->ok($sql));
                continue;
            }
            $this->blocked($sql);
        }
    }

    #[Test]
    public function blocksBlockCommentsHiddenCommentsAndUnterminatedStrings(): void
    {
        $this->blocked('/*!50000 DROP TABLE cf_users */', 'executable comment');
        $this->blocked('INSERT INTO ' . self::T . " (a) VALUES ('open", 'niet-afgesloten string');
        $this->blocked("INSERT INTO " . self::T . " (a) VALUES (1) /* x */", 'blokcommentaar');
        // regelcommentaar mag wel, maar verbergt niets: het statement erachter wordt nog gecontroleerd
        $this->assertCount(1, $this->ok("-- uitleg\nINSERT INTO " . self::T . " (a) VALUES (1) -- klaar"));
        $this->blocked("-- ok\nDROP TABLE cf_users", 'commentaar ervoor');
    }

    #[Test]
    public function rejectsOversizedFiles(): void
    {
        $this->blocked(str_repeat('-- x' . "\n", PluginSqlGuard::MAX_FILE_BYTES / 4 + 10));
    }
}
