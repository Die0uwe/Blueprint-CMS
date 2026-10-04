<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Blocks;

use CommunityFusion\Blocks\Support\BlockHtml;
use CommunityFusion\Blocks\Types\BlogLatestBlock;
use CommunityFusion\Blocks\Types\DownloadsBlock;
use CommunityFusion\Blocks\Types\ForumActivityBlock;
use CommunityFusion\Blocks\Types\GalleryLatestBlock;
use CommunityFusion\Blocks\Types\NewsBlock;
use CommunityFusion\Core\Block\BlockConfigNormalizer;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Modules\Gallery\GalleryRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Render-gedrag van de content-blokken (nieuws, blog, forum, downloads, galerij). */
final class ContentBlocksTest extends TestCase
{
    private Connection $db;
    private CacheManager $cache;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $pdo->exec("CREATE TABLE cf_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT);
            INSERT INTO cf_users VALUES (1,'ouwe','Ouwe'),(2,'jan.de-boer',NULL);
            CREATE TABLE cf_news (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, title TEXT, summary TEXT, featured_image TEXT, status TEXT, is_sticky INT DEFAULT 0, published_at TEXT, deleted_at TEXT);
            CREATE TABLE cf_blog_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INT, slug TEXT, title TEXT, summary TEXT, status TEXT, published_at TEXT, deleted_at TEXT);
            CREATE TABLE cf_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, slug TEXT, name TEXT);
            CREATE TABLE cf_forum_topics (id INTEGER PRIMARY KEY AUTOINCREMENT, board_id INT, slug TEXT, title TEXT, is_pinned INT DEFAULT 0, reply_count INT DEFAULT 0, created_at TEXT, last_post_at TEXT, deleted_at TEXT);
            CREATE TABLE cf_downloads (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, title TEXT, version TEXT, file_size INT, download_count INT DEFAULT 0, is_published INT DEFAULT 1, created_at TEXT, deleted_at TEXT);
            CREATE TABLE cf_gallery_items (id INTEGER PRIMARY KEY AUTOINCREMENT, album_id INT, media_type TEXT, file_path TEXT, thumbnail_path TEXT, title TEXT, is_published INT DEFAULT 1, created_at TEXT, deleted_at TEXT);");
        $rc = new \ReflectionClass(Connection::class);
        $this->db = $rc->newInstanceWithoutConstructor();
        $rc->getProperty('pdo')->setValue($this->db, $pdo);
        $rc->getProperty('prefix')->setValue($this->db, 'cf_');
        $this->cache = new CacheManager(['driver' => 'file', 'path' => sys_get_temp_dir() . '/cf_cache_' . bin2hex(random_bytes(4))]);
    }

    private function news(string $slug, string $title, string $when, string $summary = '', string $status = 'published', ?string $img = null): void
    {
        $this->db->execute('INSERT INTO cf_news (slug,title,summary,featured_image,status,published_at) VALUES (?,?,?,?,?,?)', [$slug, $title, $summary, $img, $status, $when]);
    }

    // ── nieuws ──────────────────────────────────────────────────────────────

    #[Test]
    public function newsDefaultsToTheOriginalList(): void
    {
        $this->news('a', 'Eerste', '2026-10-01 10:00:00');
        $html = (new NewsBlock($this->db))->render([]);
        $this->assertStringContainsString('cf-block-news-list', $html);
        $this->assertStringContainsString('/news/a', $html);
        $this->assertStringNotContainsString('cf-ticker', $html);
    }

    #[Test]
    public function newsTickerRepeatsItemsOnceForASeamlessLoop(): void
    {
        $this->news('a', 'Alpha', '2026-10-01 10:00:00');
        $this->news('b', 'Beta', '2026-10-02 10:00:00');
        $html = (new NewsBlock($this->db))->render(['style' => 'ticker']);
        $this->assertStringContainsString('cf-ticker-track', $html);
        $this->assertSame(4, substr_count($html, 'class="cf-ticker-item"'));
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    #[Test]
    public function newsTicketShowsDateStubSummaryAndOnlyLocalImages(): void
    {
        $this->news('a', 'Alpha', '2026-10-05 10:00:00', '<p>Korte <b>samenvatting</b></p>', 'published', '/media/news/x.png');
        $this->news('b', 'Beta', '2026-10-04 10:00:00', '', 'published', 'https://evil.example/x.png');
        $html = (new NewsBlock($this->db))->render(['style' => 'ticket', 'show_image' => true]);
        $this->assertStringContainsString('cf-ticket-stub', $html);
        $this->assertStringContainsString('<b>05</b>', $html);
        $this->assertStringContainsString('Korte samenvatting', $html);
        $this->assertStringContainsString('/media/news/x.png', $html);
        $this->assertStringNotContainsString('evil.example', $html);
    }

    #[Test]
    public function newsSkipsDraftsAndEscapesTitles(): void
    {
        $this->news('a', '<script>alert(1)</script>', '2026-10-01 10:00:00');
        $this->news('b', 'Concept', '2026-10-02 10:00:00', '', 'draft');
        $html = (new NewsBlock($this->db))->render(['style' => 'ticket']);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('Concept', $html);
    }

    #[Test]
    public function unknownStyleFallsBackAndCountIsClamped(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->news("s$i", "T$i", '2026-10-01 10:00:00');
        }
        $html = (new NewsBlock($this->db))->render(['style' => '"><x>', 'count' => 9999]);
        $this->assertStringContainsString('cf-block-news-list', $html);
        $this->assertSame(20, substr_count($html, 'cf-block-news-item'));
    }

    #[Test]
    public function emptyNewsGivesAFriendlyMessage(): void
    {
        $this->assertStringContainsString('cf-block-empty', (new NewsBlock($this->db))->render(['style' => 'ticker']));
    }

    // ── blog ────────────────────────────────────────────────────────────────

    #[Test]
    public function blogLinksUseTheUsernameAndSlugAndHideDrafts(): void
    {
        $this->db->execute("INSERT INTO cf_blog_posts (author_id,slug,title,summary,status,published_at) VALUES (2,'mijn-post','Mijn post','Samenvatting','published','2026-10-01 10:00:00'),(1,'concept','Verborgen','', 'draft','2026-10-02 10:00:00')");
        $html = (new BlogLatestBlock($this->db))->render([]);
        $this->assertStringContainsString('/blog/jan.de-boer/mijn-post', $html);
        $this->assertStringContainsString('jan.de-boer', $html);
        $this->assertStringNotContainsString('Verborgen', $html);

        $ticket = (new BlogLatestBlock($this->db))->render(['style' => 'ticket']);
        $this->assertStringContainsString('cf-ticket', $ticket);
        $this->assertStringContainsString('Samenvatting', $ticket);
    }

    // ── forum ───────────────────────────────────────────────────────────────

    #[Test]
    public function forumActiveSortsByLastReplyAndNewestByCreation(): void
    {
        $this->db->execute("INSERT INTO cf_categories (type,slug,name) VALUES ('forum','algemeen','Algemeen'),('news','nieuws','Nieuws')");
        $this->db->execute("INSERT INTO cf_forum_topics (board_id,slug,title,is_pinned,reply_count,created_at,last_post_at) VALUES
            (1,'oud-maar-actief','Oud maar actief',0,5,'2026-01-01 10:00:00','2026-10-05 12:00:00'),
            (1,'nieuw','Nieuw topic',1,0,'2026-10-04 10:00:00',NULL)");

        $active = (new ForumActivityBlock($this->db))->render(['mode' => 'active']);
        $this->assertTrue(strpos($active, 'Oud maar actief') < strpos($active, 'Nieuw topic'));
        $this->assertStringContainsString('/forum/algemeen/oud-maar-actief', $active);
        $this->assertStringContainsString('📌', $active);
        $this->assertStringContainsString('💬 5', $active);

        $newest = (new ForumActivityBlock($this->db))->render(['mode' => 'newest']);
        $this->assertTrue(strpos($newest, 'Nieuw topic') < strpos($newest, 'Oud maar actief'));
    }

    #[Test]
    public function forumIgnoresDeletedTopicsAndNonForumCategories(): void
    {
        $this->db->execute("INSERT INTO cf_categories (type,slug,name) VALUES ('forum','algemeen','Algemeen'),('news','nieuws','Nieuws')");
        $this->db->execute("INSERT INTO cf_forum_topics (board_id,slug,title,created_at,deleted_at) VALUES (1,'weg','Verwijderd','2026-10-01 10:00:00','2026-10-02 00:00:00'),(2,'fout','Verkeerd bord','2026-10-01 10:00:00',NULL)");
        $this->assertStringContainsString('cf-block-empty', (new ForumActivityBlock($this->db))->render([]));
    }

    // ── downloads ───────────────────────────────────────────────────────────

    private function seedDownloads(): void
    {
        $this->db->execute("INSERT INTO cf_downloads (slug,title,version,file_size,download_count,is_published,created_at) VALUES
            ('klein','Klein tool','1.2.0',2048,1,1,'2026-10-05 10:00:00'),
            ('groot','Populaire tool',NULL,5242880,99,1,'2026-09-01 10:00:00'),
            ('geheim','Verborgen',NULL,1,500,0,'2026-10-06 10:00:00')");
    }

    #[Test]
    public function downloadsSidebarListsPublishedOnlyAndSortsByPopularity(): void
    {
        $this->seedDownloads();
        $latest = (new DownloadsBlock($this->db))->render([]);
        $this->assertTrue(strpos($latest, 'Klein tool') < strpos($latest, 'Populaire tool'));
        $this->assertStringContainsString('v1.2.0', $latest);
        $this->assertStringNotContainsString('Verborgen', $latest);

        $popular = (new DownloadsBlock($this->db))->render(['sort' => 'popular']);
        $this->assertTrue(strpos($popular, 'Populaire tool') < strpos($popular, 'Klein tool'));
    }

    #[Test]
    public function downloadsCenteredAndSliderLayouts(): void
    {
        $this->seedDownloads();
        $centered = (new DownloadsBlock($this->db))->render(['layout' => 'centered']);
        $this->assertStringContainsString('cf-dl-centered', $centered);
        $this->assertStringContainsString('/downloads/klein/bestand', $centered);
        $this->assertStringContainsString('5,0 MB', $centered);
        $this->assertStringNotContainsString('data-cf-slider', $centered);

        $slider = (new DownloadsBlock($this->db))->render(['layout' => 'slider']);
        $this->assertStringContainsString('data-cf-slider', $slider);
        $this->assertSame(2, substr_count($slider, 'cf-slider-item'));
        $this->assertStringContainsString('cf-slider-prev', $slider);
    }

    // ── galerij ─────────────────────────────────────────────────────────────

    #[Test]
    public function galleryShowsImagesWithoutThumbnailAndVideosWithPosterOnly(): void
    {
        $this->db->execute("INSERT INTO cf_categories (type,slug,name) VALUES ('gallery','mix','Mix')");
        $this->db->execute("INSERT INTO cf_gallery_items (album_id,media_type,file_path,thumbnail_path,title,created_at) VALUES
            (1,'image','gallery/orig.png',NULL,'Zonder miniatuur','2026-10-01 10:00:00'),
            (1,'video','gallery/v.webm','gallery/thumbs/v.jpg','Met poster','2026-10-02 10:00:00'),
            (1,'video','gallery/w.webm',NULL,'Zonder poster','2026-10-03 10:00:00')");
        $html = (new GalleryLatestBlock(new GalleryRepository($this->db, $this->cache)))->render([]);
        $this->assertStringContainsString('/media/gallery/orig.png', $html);   // terugval op het origineel
        $this->assertStringContainsString('/media/gallery/thumbs/v.jpg', $html);
        $this->assertSame(1, substr_count($html, 'cf-gallery-play-badge'));    // alleen de video met poster
        $this->assertStringNotContainsString('w.webm', $html);
    }

    #[Test]
    public function galleryLayoutsGridCenteredSlider(): void
    {
        $this->db->execute("INSERT INTO cf_categories (type,slug,name) VALUES ('gallery','vakantie','Vakantie')");
        $this->db->execute("INSERT INTO cf_gallery_items (album_id,media_type,thumbnail_path,title,created_at) VALUES (1,'image','gallery/t1.jpg','Foto <1>','2026-10-01 10:00:00'),(1,'video',NULL,'Film','2026-10-02 10:00:00')");
        $block = new GalleryLatestBlock(new GalleryRepository($this->db, $this->cache));

        $grid = $block->render([]);
        $this->assertStringContainsString('cf-block-gallery-grid', $grid);
        $this->assertStringContainsString('/media/gallery/t1.jpg', $grid);
        $this->assertStringContainsString('Foto &lt;1&gt;', $grid);
        $this->assertStringNotContainsString('data-cf-slider', $grid);

        $this->assertStringContainsString('cf-block-gallery-centered', $block->render(['layout' => 'centered']));

        $slider = $block->render(['layout' => 'slider']);
        $this->assertStringContainsString('data-cf-slider', $slider);
        $this->assertStringContainsString('cf-slider-photo', $slider);
    }

    // ── helpers & schema's ──────────────────────────────────────────────────

    #[Test]
    public function sliderAutoplayIsBoundedAndLabelEscaped(): void
    {
        $this->assertStringNotContainsString('data-autoplay', BlockHtml::slider('', 'x', 500));
        $this->assertStringContainsString('data-autoplay="20000"', BlockHtml::slider('', 'x', 999999));
        $this->assertStringContainsString('aria-label="&lt;b&gt;"', BlockHtml::slider('', '<b>', 3000));
    }

    #[Test]
    public function helperFunctionsBehave(): void
    {
        $this->assertSame('b', BlockHtml::pick('x', ['a'], 'b'));
        $this->assertSame(3, BlockHtml::clampInt('abc', 1, 5, 3));
        $this->assertSame(5, BlockHtml::clampInt('99', 1, 5, 3));
        $this->assertSame('Kort', BlockHtml::excerpt('<p>Kort</p>'));
        $this->assertTrue(mb_strlen(BlockHtml::excerpt(str_repeat('woord ', 50), 30)) <= 30);
        $this->assertSame(['', ''], BlockHtml::dayMonth('geen datum'));
        $this->assertSame('1,5 MB', BlockHtml::formatSize((int) (1.5 * 1048576)));
    }

    #[Test]
    public function everyBlockSchemaIsNormalisableAndSelectDefaultsAreValid(): void
    {
        $gallery = new GalleryLatestBlock(new GalleryRepository($this->db, $this->cache));
        foreach ([new NewsBlock($this->db), new BlogLatestBlock($this->db), new ForumActivityBlock($this->db), new DownloadsBlock($this->db), $gallery] as $block) {
            $schema = $block->getConfigSchema();
            $this->assertNotEmpty($schema, $block->getSlug());
            $defaults = BlockConfigNormalizer::normalize($schema, []);
            foreach ($schema as $key => $field) {
                if (($field['type'] ?? '') === 'select') {
                    $this->assertContains($field['default'], $field['options'], $block->getSlug() . '.' . $key);
                    $this->assertSame($field['default'], $defaults[$key], $block->getSlug() . '.' . $key);
                }
            }
            $block->render($defaults);
        }
    }

    #[Test]
    public function blockSlugsAreUnique(): void
    {
        $gallery = new GalleryLatestBlock(new GalleryRepository($this->db, $this->cache));
        $slugs = array_map(static fn($b) => $b->getSlug(), [new NewsBlock($this->db), new BlogLatestBlock($this->db), new ForumActivityBlock($this->db), new DownloadsBlock($this->db), $gallery]);
        $this->assertCount(5, array_unique($slugs));
    }
}
