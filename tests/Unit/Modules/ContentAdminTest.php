<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Modules;

use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Modules\Blog\BlogRepository;
use CommunityFusion\Modules\Downloads\DownloadsRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Repository-laag van het blog/downloads-beheer, tegen SQLite in het geheugen. */
final class ContentAdminTest extends TestCase
{
    private Connection $db;
    private CacheManager $cache;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $pdo->sqliteCreateFunction('NOW', static fn() => gmdate('Y-m-d H:i:s'), 0);
        $pdo->exec("CREATE TABLE cf_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, avatar_url TEXT);
            INSERT INTO cf_users VALUES (1,'ouwe','Ouwe',NULL),(2,'gast','Gast',NULL);
            CREATE TABLE cf_blog_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INT, category_id INT, slug TEXT, title TEXT, summary TEXT, content TEXT, featured_image TEXT, status TEXT DEFAULT 'draft', views INT DEFAULT 0, published_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT);
            CREATE TABLE cf_downloads (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INT, category_id INT, slug TEXT, title TEXT, description TEXT, file_path TEXT, original_filename TEXT, file_size INT DEFAULT 0, download_count INT DEFAULT 0, is_published INT DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT);");
        $rc = new \ReflectionClass(Connection::class);
        $this->db = $rc->newInstanceWithoutConstructor();
        $rc->getProperty('pdo')->setValue($this->db, $pdo);
        $rc->getProperty('prefix')->setValue($this->db, 'cf_');
        $this->cache = new CacheManager(['driver' => 'file', 'path' => sys_get_temp_dir() . '/cf_cache_' . bin2hex(random_bytes(4))]);
    }

    #[Test]
    public function blogAdminSeesDraftsVisitorsDoNot(): void
    {
        $repo = new BlogRepository($this->db, $this->cache);
        $repo->create(1, 'Eerste', '', '<p>a</p>', 'published');
        $repo->create(2, 'Concept', '', '<p>b</p>', 'draft');

        $this->assertSame(2, $repo->countAll());
        $this->assertCount(2, $repo->getAllForAdmin(20, 0));
        $this->assertCount(1, $repo->getPublished(10, 0));
    }

    #[Test]
    public function blogSetStatusKeepsPublishDateAndRejectsGarbage(): void
    {
        $repo = new BlogRepository($this->db, $this->cache);
        $id = $repo->create(1, 'Post', '', '<p>a</p>', 'draft');

        $repo->setStatus($id, 'published');
        $date = $repo->findById($id)['published_at'];
        $this->assertNotNull($date);
        $this->assertCount(1, $repo->getPublished(10, 0));

        $repo->setStatus($id, 'published');
        $this->assertSame($date, $repo->findById($id)['published_at']);

        $repo->setStatus($id, 'bogus');
        $this->assertSame('published', $repo->findById($id)['status']);
    }

    #[Test]
    public function downloadsToggleAndSoftDelete(): void
    {
        $repo = new DownloadsRepository($this->db, $this->cache);
        $id = $repo->create(1, 'Tool', '', 'a/b.zip', 'tool.zip', 1024);

        $repo->setPublished($id, false);
        $this->assertSame(0, $repo->countPublished());
        $this->assertSame(1, $repo->countAll());

        $repo->setPublished($id, true);
        $this->assertSame(1, $repo->countPublished());

        $repo->delete($id);
        $this->assertNull($repo->findById($id));
        $this->assertSame(0, $repo->countAll());
    }
}
