<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Modules;

use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Modules\Downloads\DownloadsController;
use CommunityFusion\Modules\Downloads\DownloadsRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Downloads: slug-botsing met soft-deleted rij, veilige Content-Disposition, streaming, ?page-clamp. */
final class DownloadsHardeningTest extends TestCase
{
    private Connection $db;
    private DownloadsRepository $repo;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $pdo->sqliteCreateFunction('NOW', static fn() => gmdate('Y-m-d H:i:s'), 0);
        $pdo->exec("CREATE TABLE cf_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT);
            INSERT INTO cf_users VALUES (1,'ouwe','Ouwe');
            CREATE TABLE cf_downloads (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INT, slug TEXT UNIQUE, title TEXT, description TEXT, version TEXT, file_path TEXT, original_filename TEXT, file_size INT DEFAULT 0, download_count INT DEFAULT 0, is_published INT DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT);");
        $rc = new \ReflectionClass(Connection::class);
        $this->db = $rc->newInstanceWithoutConstructor();
        $rc->getProperty('pdo')->setValue($this->db, $pdo);
        $rc->getProperty('prefix')->setValue($this->db, 'cf_');
        $cache = new CacheManager(['driver' => 'file', 'path' => sys_get_temp_dir() . '/cf_dl_' . bin2hex(random_bytes(4))]);
        $this->repo = new DownloadsRepository($this->db, $cache);
    }

    #[Test]
    public function newDownloadDoesNotCollideWithSoftDeletedSlug(): void
    {
        $a = $this->repo->create(1, 'Addon', '', 'a.zip', 'a.zip', 10);
        $this->repo->delete($a);
        $b = $this->repo->create(1, 'Addon', '', 'b.zip', 'b.zip', 10);   // zou 1062/UNIQUE-fout geven
        $slugs = array_column($this->db->fetchAll('SELECT slug FROM cf_downloads'), 'slug');
        $this->assertCount(2, array_unique($slugs));
        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function reservedRouteSlugIsNeverUsed(): void
    {
        $id = $this->repo->create(1, 'Nieuw', '', 'n.zip', 'n.zip', 1);
        $this->assertNotSame('nieuw', $this->db->fetchOne('SELECT slug FROM cf_downloads WHERE id = ?', [$id])['slug']);
    }

    #[Test]
    public function createInvalidatesPublishedListCache(): void
    {
        $this->assertCount(0, $this->repo->getPublished(10, 0));
        $this->repo->create(1, 'Nieuw', '', 'n.zip', 'n.zip', 1);
        $this->assertCount(1, $this->repo->getPublished(10, 0));
    }

    #[Test]
    public function updateDetailsClearsVersionWhenEmpty(): void
    {
        $id = $this->repo->create(1, 'V', '', 'v.zip', 'v.zip', 1, '1.0');
        $this->repo->updateDetails($id, 'V', '', true, '');
        $this->assertNull($this->db->fetchOne('SELECT version FROM cf_downloads WHERE id = ?', [$id])['version']);
    }

    #[Test]
    public function contentDispositionIsHeaderSafeAndUnicodeAware(): void
    {
        $h = DownloadsController::contentDisposition("évil\"\r\nSet-Cookie: x=1/../naam.zip");
        $this->assertStringNotContainsString("\r", $h);
        $this->assertStringNotContainsString("\n", $h);
        $this->assertStringContainsString("filename*=UTF-8''", $h);
        $this->assertStringContainsString('filename="', $h);
        $this->assertSame(1, substr_count($h, '"') / 2);
        $this->assertStringContainsString('%C3%A9vil', $h);
        $this->assertSame('attachment; filename="bestand"; filename*=UTF-8\'\'bestand', DownloadsController::contentDisposition(''));
    }

    #[Test]
    public function streamResponseCarriesLengthAndIsAStream(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'dl');
        file_put_contents($f, str_repeat('x', 1000));
        $r = Response::stream($f, 100, 50, 206, ['Content-Range' => 'bytes 100-149/1000']);
        $this->assertTrue($r->isStream());
        $this->assertSame('50', $r->getHeader('Content-Length'));
        $this->assertSame(206, $r->getStatus());
        ob_start();
        $r->send();
        $out = (string) ob_get_clean();
        $this->assertSame(50, strlen($out));
        unlink($f);
    }

    #[Test]
    public function pageParameterIsClampedToSaneInteger(): void
    {
        $mk = static fn(string $v): Request => new Request('GET', '/x', ['page' => $v], [], [], [], [], []);
        $this->assertSame(1, $mk('abc')->page());
        $this->assertSame(1, $mk('-5')->page());
        $this->assertSame(1, $mk('1e30')->page());
        $this->assertSame(1, $mk('99999999999999999999')->page());
        $this->assertSame(7, $mk('7')->page());
        $this->assertSame(10000, $mk('999999999')->page());
    }
}
