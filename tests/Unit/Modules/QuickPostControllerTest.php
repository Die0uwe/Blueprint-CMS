<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Modules;

use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Auth\RBAC\RBACManager;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Modules\Blog\BlogRepository;
use CommunityFusion\Modules\Forum\ForumRepository;
use CommunityFusion\Modules\News\NewsRepository;
use CommunityFusion\Modules\Pages\QuickPostController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Quick-post op de homepage: rechten per type en aanmaken, tegen SQLite in het geheugen. */
final class QuickPostControllerTest extends TestCase
{
    private \PDO $pdo;
    private Connection $db;
    private CacheManager $cache;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->sqliteCreateFunction('NOW', static fn() => gmdate('Y-m-d H:i:s'), 0);
        $this->pdo->exec("CREATE TABLE cf_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, avatar_url TEXT);
            INSERT INTO cf_users VALUES (1,'ouwe','Ouwe',NULL);
            CREATE TABLE cf_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INT, type TEXT, slug TEXT, name TEXT, description TEXT, position INT DEFAULT 0);
            INSERT INTO cf_categories (type,slug,name) VALUES ('forum','algemeen','Algemeen');
            CREATE TABLE cf_forum_topics (id INTEGER PRIMARY KEY AUTOINCREMENT, board_id INT, author_id INT, slug TEXT, title TEXT, is_pinned INT DEFAULT 0, is_locked INT DEFAULT 0, views INT DEFAULT 0, reply_count INT DEFAULT 0, last_post_id INT, last_post_at TEXT, last_post_user_id INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT);
            CREATE TABLE cf_forum_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, topic_id INT, author_id INT, content TEXT, is_first_post INT DEFAULT 0, edited_at TEXT, edited_by INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT);
            CREATE TABLE cf_news (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INT, category_id INT, slug TEXT, title TEXT, summary TEXT, content TEXT, featured_image TEXT, status TEXT, is_sticky INT DEFAULT 0, views INT DEFAULT 0, comment_count INT DEFAULT 0, published_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT);
            CREATE TABLE cf_permissions (id INTEGER PRIMARY KEY, name TEXT);
            CREATE TABLE cf_role_permissions (role_id INT, permission_id INT);
            CREATE TABLE cf_user_roles (user_id INT, role_id INT);
            CREATE TABLE cf_blog_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INT, slug TEXT, title TEXT, summary TEXT, content TEXT, status TEXT, published_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT);");
        $rc = new \ReflectionClass(Connection::class);
        $this->db = $rc->newInstanceWithoutConstructor();
        $rc->getProperty('pdo')->setValue($this->db, $this->pdo);
        $rc->getProperty('prefix')->setValue($this->db, 'cf_');
        $this->cache = new CacheManager(['driver' => 'file', 'path' => sys_get_temp_dir() . '/cf_cache_' . bin2hex(random_bytes(4))]);
        $_SESSION['_csrf_token'] = 'tok';
    }

    /** @param list<string>|null $perms null = niet ingelogd */
    private function ctl(?array $perms): QuickPostController
    {
        $this->cache->clear(); // RBAC cachet per gebruiker; elke ctl() start schoon
        $rbac = (new \ReflectionClass(RBACManager::class))->newInstanceWithoutConstructor();
        $rrc  = new \ReflectionClass(RBACManager::class);
        foreach ($rrc->getProperties() as $p) {
            $t = (string) $p->getType();
            if (str_contains($t, 'CacheManager')) { $p->setValue($rbac, $this->cache); }
            if (str_contains($t, 'Connection'))   { $p->setValue($rbac, $this->db); }
        }
        $arc  = new \ReflectionClass(AuthManager::class);
        $auth = $arc->newInstanceWithoutConstructor();
        $arc->getProperty('rbac')->setValue($auth, $rbac);
        if ($perms !== null) {
            $arc->getProperty('currentUser')->setValue($auth, ['id' => 1, 'username' => 'ouwe']);
            $this->pdo->exec('DELETE FROM cf_permissions; DELETE FROM cf_role_permissions; DELETE FROM cf_user_roles;');
            $this->pdo->exec('INSERT INTO cf_user_roles VALUES (1, 1)');
            foreach ($perms as $i => $name) {
                $this->pdo->exec("INSERT INTO cf_permissions (id, name) VALUES (" . ($i + 1) . ", '{$name}')");
                $this->pdo->exec('INSERT INTO cf_role_permissions VALUES (1, ' . ($i + 1) . ')');
            }
        }
        return new QuickPostController(
            new NewsRepository($this->db, $this->cache),
            new BlogRepository($this->db, $this->cache),
            new ForumRepository($this->db, $this->cache),
            $auth,
        );
    }

    private function post(array $body): Request
    {
        $body['_csrf_token'] = 'tok';
        $_POST = $body;
        return new Request('POST', '/quick-post', [], $body, [], [], [], []);
    }

    private function location(Response $r): string
    {
        return (string) ((new \ReflectionProperty($r, 'headers'))->getValue($r)['Location'] ?? '');
    }

    private function code(Response $r): int
    {
        return (int) (new \ReflectionProperty($r, 'statusCode'))->getValue($r);
    }

    private function count(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    #[Test]
    public function allowedTypesFollowPermissions(): void
    {
        $this->assertSame([], $this->ctl(null)->allowedTypes());
        $this->assertSame(['blog'], $this->ctl([])->allowedTypes());
        $this->assertSame(['post', 'blog', 'forum'], $this->ctl(['news.create', 'forum.post'])->allowedTypes());
        $this->assertSame([], $this->ctl([])->boards());
        $this->assertSame('algemeen', $this->ctl(['forum.post'])->boards()[0]['slug']);
    }

    #[Test]
    public function guestIsSentToLogin(): void
    {
        $r = $this->ctl(null)->store($this->post(['type' => 'blog', 'title' => 'x', 'content' => 'y']));
        $this->assertStringStartsWith('/login', $this->location($r));
        $this->assertSame(0, $this->count('cf_blog_posts'));
    }

    #[Test]
    public function memberCanBlogButNotPostNewsOrTopics(): void
    {
        $c = $this->ctl([]);
        $this->assertSame(403, $this->code($c->store($this->post(['type' => 'post', 'title' => 'x', 'content' => 'y']))));
        $this->assertSame(403, $this->code($c->store($this->post(['type' => 'forum', 'title' => 'x', 'content' => 'y', 'board' => 'algemeen']))));
        $this->assertSame(403, $this->code($c->store($this->post(['type' => 'bogus', 'title' => 'x', 'content' => 'y']))));
        $this->assertSame(0, $this->count('cf_news') + $this->count('cf_forum_topics'));

        $r = $c->store($this->post(['type' => 'blog', 'title' => 'Mijn Dag', 'content' => '<p>Hallo <script>alert(1)</script>wereld</p>']));
        $this->assertSame('/blog/ouwe/mijn-dag', $this->location($r));
        $row = $this->pdo->query('SELECT * FROM cf_blog_posts')->fetch();
        $this->assertSame('published', $row['status']);
        $this->assertStringNotContainsString('<script', $row['content']);
    }

    #[Test]
    public function editorCanPostNewsAndTopics(): void
    {
        $c = $this->ctl(['news.create', 'forum.post']);

        $r = $c->store($this->post(['type' => 'post', 'title' => 'Groot Nieuws', 'content' => 'Tekst']));
        $this->assertSame('/news/groot-nieuws', $this->location($r));
        $n = $this->pdo->query('SELECT * FROM cf_news')->fetch();
        $this->assertSame('published', $n['status']);
        $this->assertSame(1, (int) $n['author_id']);
        $this->assertNotEmpty($n['published_at']);

        $r = $c->store($this->post(['type' => 'forum', 'title' => 'Vraagje', 'content' => 'Hoe?', 'board' => 'algemeen']));
        $this->assertSame('/forum/algemeen/vraagje', $this->location($r));
        $this->assertSame(1, $this->count('cf_forum_posts'));
    }

    #[Test]
    public function emptyFieldsAndUnknownBoardRedirectBackWithoutSaving(): void
    {
        $c = $this->ctl(['news.create', 'forum.post']);
        $this->assertSame('/?quick=leeg', $this->location($c->store($this->post(['type' => 'post', 'title' => '  ', 'content' => 'x']))));
        $this->assertSame('/?quick=leeg', $this->location($c->store($this->post(['type' => 'post', 'title' => 'x', 'content' => '']))));
        $this->assertSame('/?quick=bord', $this->location($c->store($this->post(['type' => 'forum', 'title' => 'x', 'content' => 'y', 'board' => 'nope']))));
        $this->assertSame(0, $this->count('cf_news') + $this->count('cf_forum_topics'));
    }

    #[Test]
    public function badCsrfIsRejected(): void
    {
        $c = $this->ctl([]);
        $_POST = ['_csrf_token' => 'wrong'];
        $this->expectException(\CommunityFusion\Core\HttpException::class);
        $c->store(new Request('POST', '/quick-post', [], ['type' => 'blog', 'title' => 'x', 'content' => 'y'], [], [], [], []));
    }
}
