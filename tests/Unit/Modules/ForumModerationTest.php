<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Modules;

use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Modules\Forum\ForumRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Reactie verwijderen + topic verplaatsen, tegen SQLite in het geheugen. */
final class ForumModerationTest extends TestCase
{
    private \PDO $pdo;
    private ForumRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->sqliteCreateFunction('NOW', static fn() => gmdate('Y-m-d H:i:s'), 0);
        $this->pdo->exec("CREATE TABLE cf_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, avatar_url TEXT);
            INSERT INTO cf_users VALUES (1,'ouwe','Ouwe',NULL),(2,'gast','Gast',NULL);
            CREATE TABLE cf_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INT, type TEXT, slug TEXT, name TEXT, description TEXT, position INT DEFAULT 0);
            INSERT INTO cf_categories (type,slug,name) VALUES ('forum','algemeen','Algemeen'),('forum','offtopic','Off-topic'),('news','nieuws','Nieuws');
            CREATE TABLE cf_forum_topics (id INTEGER PRIMARY KEY AUTOINCREMENT, board_id INT, author_id INT, slug TEXT, title TEXT, is_pinned INT DEFAULT 0, is_locked INT DEFAULT 0, views INT DEFAULT 0, reply_count INT DEFAULT 0, last_post_id INT, last_post_at TEXT, last_post_user_id INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT);
            CREATE TABLE cf_forum_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, topic_id INT, author_id INT, content TEXT, is_first_post INT DEFAULT 0, edited_at TEXT, edited_by INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT);");
        $rc = new \ReflectionClass(Connection::class);
        $db = $rc->newInstanceWithoutConstructor();
        $rc->getProperty('pdo')->setValue($db, $this->pdo);
        $rc->getProperty('prefix')->setValue($db, 'cf_');
        $this->repo = new ForumRepository($db, new CacheManager(['driver' => 'file', 'path' => sys_get_temp_dir() . '/cf_cache_' . bin2hex(random_bytes(4))]));
    }

    private function at(int $postId, string $time): void
    {
        $this->pdo->prepare('UPDATE cf_forum_posts SET created_at = ? WHERE id = ?')->execute([$time, $postId]);
    }

    #[Test]
    public function deletingAReplyRecomputesCountersAndLastPost(): void
    {
        $t  = $this->repo->createTopic(1, 1, 'Hallo', '<p>start</p>');
        $p1 = $this->repo->createPost($t, 2, '<p>een</p>');
        $p2 = $this->repo->createPost($t, 1, '<p>twee</p>');
        $this->at((int) $this->pdo->query('SELECT id FROM cf_forum_posts WHERE is_first_post = 1')->fetchColumn(), '2026-01-01 09:00:00');
        $this->at($p1, '2026-01-01 10:00:00');
        $this->at($p2, '2026-01-01 11:00:00');

        $this->assertTrue($this->repo->deletePost($p2));
        $topic = $this->repo->findTopicById($t);
        $this->assertSame(1, (int) $topic['reply_count']);
        $this->assertSame($p1, (int) $topic['last_post_id']);
        $this->assertSame(2, (int) $topic['last_post_user_id']);
        $this->assertSame(null, $this->repo->findPostById($p2));

        $this->assertTrue($this->repo->deletePost($p1));
        $topic = $this->repo->findTopicById($t);
        $this->assertSame(0, (int) $topic['reply_count']);
        $this->assertSame(1, $this->repo->countPosts($t)); // alleen het openingsbericht
    }

    #[Test]
    public function firstPostAndMissingPostCannotBeDeletedSeparately(): void
    {
        $t = $this->repo->createTopic(1, 1, 'Hallo', '<p>start</p>');
        $first = (int) $this->pdo->query('SELECT id FROM cf_forum_posts WHERE is_first_post = 1')->fetchColumn();

        $this->assertFalse($this->repo->deletePost($first));
        $this->assertFalse($this->repo->deletePost(9999));
        $this->assertSame(1, $this->repo->countPosts($t));
    }

    #[Test]
    public function moveTopicChangesBoardAndAvoidsSlugClash(): void
    {
        $a = $this->repo->createTopic(1, 1, 'Zelfde titel', '<p>a</p>');
        $b = $this->repo->createTopic(2, 1, 'Zelfde titel', '<p>b</p>');
        $slugB = $this->repo->findTopicById($b)['slug'];

        $this->assertTrue($this->repo->moveTopic($a, 2));
        $moved = $this->repo->findTopicById($a);
        $this->assertSame(2, (int) $moved['board_id']);
        $this->assertTrue($moved['slug'] !== $slugB, 'slug botst niet met bestaand topic');
        $this->assertSame($slugB, $this->repo->findTopicById($b)['slug']);
    }

    #[Test]
    public function moveTopicRejectsNonForumBoardAndUnknownTopic(): void
    {
        $t = $this->repo->createTopic(1, 1, 'Hallo', '<p>x</p>');
        $this->assertFalse($this->repo->moveTopic($t, 3));    // categorie van type 'news'
        $this->assertFalse($this->repo->moveTopic($t, 999));
        $this->assertFalse($this->repo->moveTopic(999, 2));
        $this->assertSame(1, (int) $this->repo->findTopicById($t)['board_id']);
    }

    #[Test]
    public function adminOverviewListsTopicsAndRecentRepliesOnly(): void
    {
        $t = $this->repo->createTopic(1, 1, 'Hallo', '<p>start</p>');
        $this->repo->createPost($t, 2, '<p>reactie</p>');

        $this->assertSame(1, $this->repo->countAllTopics());
        $rows = $this->repo->getTopicsForAdmin(20, 0);
        $this->assertSame('Algemeen', $rows[0]['board_name']);
        $recent = $this->repo->getRecentPostsForAdmin(10);
        $this->assertSame(1, count($recent));
        $this->assertSame('gast', $recent[0]['username']);
    }
}
