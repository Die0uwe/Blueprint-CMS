<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Modules;

use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Modules\Downloads\DownloadsRepository;
use CommunityFusion\Modules\Downloads\DownloadStats;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Downloadlog + statistieken, tegen SQLite in het geheugen. */
final class DownloadStatsTest extends TestCase
{
    private Connection $db;
    private DownloadStats $stats;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $pdo->sqliteCreateFunction('NOW', static fn() => gmdate('Y-m-d H:i:s'), 0);
        $pdo->exec("CREATE TABLE cf_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, avatar_url TEXT);
            INSERT INTO cf_users VALUES (1,'ouwe','Ouwe',NULL);
            CREATE TABLE cf_downloads (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INT, category_id INT, slug TEXT, title TEXT, description TEXT, version TEXT, file_path TEXT, original_filename TEXT, file_size INT DEFAULT 0, download_count INT DEFAULT 0, is_published INT DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT);
            CREATE TABLE cf_download_log (id INTEGER PRIMARY KEY AUTOINCREMENT, download_id INT, title TEXT, version TEXT, user_id INT, ip_address TEXT DEFAULT '', bytes_sent INT DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP);");
        $rc = new \ReflectionClass(Connection::class);
        $this->db = $rc->newInstanceWithoutConstructor();
        $rc->getProperty('pdo')->setValue($this->db, $pdo);
        $rc->getProperty('prefix')->setValue($this->db, 'cf_');
        $this->stats = new DownloadStats($this->db);
    }

    private function dl(int $id, string $title, ?string $version = '1.0'): array
    {
        return ['id' => $id, 'title' => $title, 'version' => $version];
    }

    #[Test]
    public function recordsRowWithSnapshotOfTitleAndVersion(): void
    {
        $this->stats->record($this->dl(5, 'Addon', '2.1.0'), 1, '203.0.113.9', 1000);
        $row = $this->db->fetchOne('SELECT * FROM cf_download_log');
        $this->assertSame('Addon', $row['title']);
        $this->assertSame('2.1.0', $row['version']);
        $this->assertSame(1, (int) $row['user_id']);
        $this->assertSame(1000, (int) $row['bytes_sent']);
    }

    #[Test]
    public function emptyVersionIsStoredAsNull(): void
    {
        $this->stats->record($this->dl(5, 'Addon', ''), null, '198.51.100.1', 10);
        $this->assertNull($this->db->fetchOne('SELECT version FROM cf_download_log')['version']);
    }

    #[Test]
    public function uniqueCountsOncePerUserOrIpPerFile(): void
    {
        $a = $this->dl(1, 'A');
        $this->stats->record($a, null, '1.1.1.1', 100);
        $this->stats->record($a, null, '1.1.1.1', 100);   // zelfde bezoeker
        $this->stats->record($a, null, '2.2.2.2', 100);   // andere bezoeker
        $this->stats->record($a, 1, '1.1.1.1', 100);      // ingelogd: telt apart
        $this->stats->record($this->dl(2, 'B'), null, '1.1.1.1', 50); // ander bestand

        $t = $this->stats->totals();
        $this->assertSame(5, $t['total']);
        $this->assertSame(4, $t['unique']);
        $this->assertSame(450, $t['bytes']);
    }

    #[Test]
    public function perDownloadIsSortedByPopularityWithUniqueAndBandwidth(): void
    {
        $this->stats->record($this->dl(1, 'Klein'), null, '1.1.1.1', 10);
        for ($i = 0; $i < 3; $i++) {
            $this->stats->record($this->dl(2, 'Groot'), null, '9.9.9.' . ($i % 2), 100);
        }
        $rows = $this->stats->perDownload();
        $this->assertSame('Groot', $rows[0]['title']);
        $this->assertSame(3, (int) $rows[0]['total']);
        $this->assertSame(2, (int) $rows[0]['uniq']);
        $this->assertSame(300, (int) $rows[0]['bytes']);
    }

    #[Test]
    public function perDayFillsMissingDaysWithZero(): void
    {
        $this->stats->record($this->dl(1, 'A'), null, '1.1.1.1', 1);
        $days = $this->stats->perDay(7);
        $this->assertCount(7, $days);
        $this->assertSame(1, $days[array_key_last($days)]);
        $this->assertSame(0, $days[array_key_first($days)]);
    }

    #[Test]
    public function last30ExcludesOldRows(): void
    {
        $this->stats->record($this->dl(1, 'A'), null, '1.1.1.1', 100);
        $this->db->execute("UPDATE cf_download_log SET created_at = ?", [date('Y-m-d H:i:s', time() - 40 * 86400)]);
        $this->stats->record($this->dl(1, 'A'), null, '2.2.2.2', 100);
        $t = $this->stats->totals();
        $this->assertSame(2, $t['total']);
        $this->assertSame(1, $t['last30']);
        $this->assertSame(100, $t['last30_bytes']);
    }

    #[Test]
    public function recentListsNewestFirstWithUsername(): void
    {
        $this->stats->record($this->dl(1, 'Eerst'), null, '1.1.1.1', 1);
        $this->stats->record($this->dl(1, 'Laatst'), 1, '1.1.1.1', 1);
        $rows = $this->stats->recent(10);
        $this->assertSame('Laatst', $rows[0]['title']);
        $this->assertSame('ouwe', $rows[0]['username']);
        $this->assertNull($rows[1]['username']);
    }

    #[Test]
    public function recordNeverThrowsWhenTheTableIsMissing(): void
    {
        $this->db->execute('DROP TABLE cf_download_log');
        $this->stats->record($this->dl(1, 'A'), null, '1.1.1.1', 1);
        $this->assertTrue(true);
    }

    #[Test]
    public function repositoryStoresAndClearsVersion(): void
    {
        $repo = new DownloadsRepository($this->db, new CacheManager(['driver' => 'file', 'path' => sys_get_temp_dir() . '/cf_cache_' . bin2hex(random_bytes(4))]));
        $id = $repo->create(1, 'Tool', '', 'x.zip', 'x.zip', 5, '3.4.5');
        $this->assertSame('3.4.5', $repo->findById($id)['version']);
        $repo->updateDetails($id, 'Tool', '', true, '');
        $this->assertNull($repo->findById($id)['version']);
    }

    #[Test]
    public function formatBytesIsHumanReadable(): void
    {
        $this->assertSame('0 B', DownloadStats::formatBytes(0));
        $this->assertSame('1,00 KB', DownloadStats::formatBytes(1024));
        $this->assertSame('1,50 MB', DownloadStats::formatBytes((int) (1.5 * 1048576)));
    }
}
