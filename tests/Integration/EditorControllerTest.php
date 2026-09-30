<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Integration;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Hook\HookManager;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Modules\Blog\BlogRepository;
use CommunityFusion\Modules\Editor\EditorController;
use CommunityFusion\Modules\News\NewsRepository;
use CommunityFusion\Modules\Pages\PageRepository;
use CommunityFusion\Tests\Support\TestAuth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/TestAuth.php';

/** Draait alleen met BP_TEST_DB (+ BP_TEST_USER/BP_TEST_PASS). */
final class EditorControllerTest extends TestCase
{
    private Connection $db;
    private CacheManager $cache;
    private HookManager $hooks;
    private int $pageId;

    protected function setUp(): void
    {
        $name = getenv('BP_TEST_DB');
        if ($name === false || $name === '') {
            $this->markTestSkipped('BP_TEST_DB niet gezet.');
        }
        $this->db = new Connection([
            'host' => getenv('BP_TEST_HOST') ?: '127.0.0.1', 'name' => $name,
            'user' => (string)getenv('BP_TEST_USER'), 'password' => (string)getenv('BP_TEST_PASS'),
        ]);
        $this->cache = new CacheManager(['path' => sys_get_temp_dir() . '/bp-ed-' . bin2hex(random_bytes(4))]);
        $this->hooks = new HookManager();
        $this->db->execute("DELETE FROM cf_pages WHERE slug LIKE 'tp-%'");
        $this->db->execute("DELETE FROM cf_blog_posts WHERE slug LIKE 'tp-%'");
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute("DELETE FROM cf_pages WHERE slug LIKE 'tp-%'");
            $this->db->execute("DELETE FROM cf_blog_posts WHERE slug LIKE 'tp-%'");
            TestAuth::cleanup($this->db);
        }
    }

    /** @param list<string> $perms */
    private function ctl(array $perms): EditorController
    {
        $auth = TestAuth::make($this->db, $perms);
        $uid = (int)$_SESSION['user_id'];
        $this->db->execute("DELETE FROM cf_pages WHERE slug = 'tp-page'");
        $this->db->execute("INSERT INTO cf_pages (author_id, slug, title, content) VALUES (?, 'tp-page', 'Testpagina', '<p>oud</p>')", [$uid]);
        $this->pageId = (int)$this->db->fetchOne("SELECT id FROM cf_pages WHERE slug = 'tp-page'")['id'];
        return new EditorController(
            new PageRepository($this->db, $this->cache), new NewsRepository($this->db, $this->cache), new BlogRepository($this->db, $this->cache),
            $auth, $this->db, $this->cache, $this->hooks, new AuditLogger($this->db)
        );
    }

    private function req(array $body, array $params = [], bool $csrf = true): Request
    {
        $_POST = $csrf ? ['_csrf_token' => CsrfProtection::getToken()] : [];
        $r = new Request('POST', '/x', [], $body + $_POST, [], [], [], []);
        $r->setParams($params);
        return $r;
    }

    private const FULL = ['editor.use', 'pages.manage'];

    #[Test]
    public function everyEndpointNeedsTheEditorPermission(): void
    {
        $c = $this->ctl(['pages.manage']);   // wel pagina's, geen editor.use
        foreach ([
            fn () => $c->edit($this->req([], ['type' => 'page', 'id' => (string)$this->pageId])),
            fn () => $c->preview($this->req(['type' => 'page', 'markup' => 'x'])),
            fn () => $c->draft($this->req(['type' => 'page', 'id' => $this->pageId, 'content' => 'x'])),
            fn () => $c->save($this->req(['markup' => 'x'], ['type' => 'page', 'id' => (string)$this->pageId])),
        ] as $i => $call) {
            try {
                $call();
                $this->fail("endpoint $i had 403 moeten geven");
            } catch (\Throwable $e) {
                $this->assertSame(403, $e->getCode(), "endpoint $i");
            }
        }
    }

    #[Test]
    public function postsWithoutCsrfAreRejected(): void
    {
        $c = $this->ctl(self::FULL);
        foreach ([
            fn () => $c->preview($this->req(['type' => 'page', 'markup' => 'x'], [], false)),
            fn () => $c->draft($this->req(['type' => 'page', 'id' => $this->pageId, 'content' => 'x'], [], false)),
            fn () => $c->save($this->req(['markup' => 'x'], ['type' => 'page', 'id' => (string)$this->pageId], false)),
        ] as $i => $call) {
            try {
                $call();
                $this->fail("endpoint $i had CSRF moeten weigeren");
            } catch (\Throwable $e) {
                $this->assertSame(403, $e->getCode(), "endpoint $i");
            }
        }
        $this->assertSame('<p>oud</p>', $this->db->fetchOne('SELECT content FROM cf_pages WHERE id = ?', [$this->pageId])['content']);
    }

    #[Test]
    public function previewIsASandboxedDocumentAndEscapesUserInput(): void
    {
        $c = $this->ctl(self::FULL);
        $res = $c->preview($this->req(['type' => 'page', 'title' => '<img src=x onerror=alert(1)>', 'markup' => '<p>{{ title }}</p>']));
        $this->assertSame(200, $res->getStatus());
        $html = json_decode($res->getBody(), true)['html'];
        $this->assertStringContainsString("default-src 'none'", $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
    }

    #[Test]
    public function phpTagsNeedTheirOwnPermissionAndAreNeverExecuted(): void
    {
        $c = $this->ctl(self::FULL);
        $this->assertSame(403, $c->preview($this->req(['type' => 'page', 'markup' => '<?php echo 1; ?>']))->getStatus());
        $this->assertSame(403, $c->save($this->req(['markup' => '<?php echo 1; ?>'], ['type' => 'page', 'id' => (string)$this->pageId]))->getStatus());

        $c2 = $this->ctl(['editor.use', 'pages.manage', 'editor.markup.php']);
        $res = $c2->preview($this->req(['type' => 'page', 'markup' => '<?php echo "PWNED"; ?>']));
        $this->assertSame(200, $res->getStatus());
        $html = json_decode($res->getBody(), true)['html'];
        $this->assertStringContainsString('&lt;?php', $html);
        $this->assertStringNotContainsString('PWNED</body>', $html);
    }

    #[Test]
    public function dangerousTwigIsRefused(): void
    {
        $c = $this->ctl(self::FULL);
        $this->assertSame(422, $c->preview($this->req(['type' => 'page', 'markup' => "{{ system('id') }}"]))->getStatus());
        $this->assertSame(422, $c->save($this->req(['markup' => "{% include 'x' %}"], ['type' => 'page', 'id' => (string)$this->pageId]))->getStatus());
    }

    #[Test]
    public function saveStoresSourceAndRenderedHtmlAndDeletesTheDraft(): void
    {
        $c = $this->ctl(self::FULL);
        $this->assertSame(200, $c->draft($this->req(['type' => 'page', 'id' => $this->pageId, 'content' => 'concept']))->getStatus());
        $this->assertSame(1, (int)$this->db->fetchOne('SELECT COUNT(*) c FROM cf_editor_drafts')['c']);

        $res = $c->save($this->req(['markup' => '<h1>{{ title|upper }}</h1>'], ['type' => 'page', 'id' => (string)$this->pageId]));
        $this->assertSame(200, $res->getStatus());
        $row = $this->db->fetchOne('SELECT content, content_markup FROM cf_pages WHERE id = ?', [$this->pageId]);
        $this->assertSame('<h1>TESTPAGINA</h1>', $row['content']);
        $this->assertSame('<h1>{{ title|upper }}</h1>', $row['content_markup']);
        $this->assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) c FROM cf_editor_drafts')['c']);
        $this->assertTrue((int)$this->db->fetchOne("SELECT COUNT(*) c FROM cf_audit_log WHERE action = 'editor.save'")['c'] >= 1);
    }

    #[Test]
    public function thePlainFormClearsTheStaleSource(): void
    {
        $c = $this->ctl(self::FULL);
        $c->save($this->req(['markup' => '<p>bron</p>'], ['type' => 'page', 'id' => (string)$this->pageId]));
        (new PageRepository($this->db, $this->cache))->update($this->pageId, ['content' => '<p>via formulier</p>']);
        $row = $this->db->fetchOne('SELECT content, content_markup FROM cf_pages WHERE id = ?', [$this->pageId]);
        $this->assertSame('<p>via formulier</p>', $row['content']);
        $this->assertNull($row['content_markup']);
    }

    #[Test]
    public function aBlogPostCanOnlyBeEditedByItsAuthor(): void
    {
        $c = $this->ctl(['editor.use']);   // geen blog.moderate
        $mine = (int)$_SESSION['user_id'];
        $this->db->execute("INSERT INTO cf_blog_posts (author_id, slug, title, content) VALUES (?, 'tp-mijn', 'Van mij', 'mijn')", [$mine]);
        $mineId = (int)$this->db->fetchOne("SELECT id FROM cf_blog_posts WHERE slug = 'tp-mijn'")['id'];
        $other = TestAuth::make($this->db, []);   // tweede gebruiker (wordt nu de sessiegebruiker)
        $otherId = (int)$_SESSION['user_id'];
        $this->db->execute("INSERT INTO cf_blog_posts (author_id, slug, title, content) VALUES (?, 'tp-blog', 'Blog', 'tekst')", [$otherId]);
        $blogId = (int)$this->db->fetchOne("SELECT id FROM cf_blog_posts WHERE slug = 'tp-blog'")['id'];
        // $c hoort bij de eerste gebruiker
        $res = $c->save($this->req(['markup' => '<b>x</b>'], ['type' => 'blog', 'id' => (string)$blogId]));
        $this->assertSame(403, $res->getStatus());
        // de eigen post mag wel
        $own = $c->save($this->req(['markup' => 'nieuw'], ['type' => 'blog', 'id' => (string)$mineId]));
        $this->assertSame(200, $own->getStatus());
        $this->assertSame('nieuw', $this->db->fetchOne('SELECT content FROM cf_blog_posts WHERE id = ?', [$mineId])['content']);
        $this->assertSame('tekst', $this->db->fetchOne('SELECT content FROM cf_blog_posts WHERE id = ?', [$blogId])['content']);
    }

    #[Test]
    public function previewsAreRateLimited(): void
    {
        $c = $this->ctl(self::FULL);
        $last = 200;
        for ($i = 0; $i < 70 && $last === 200; $i++) {
            $last = $c->preview($this->req(['type' => 'page', 'markup' => '<p>x</p>']))->getStatus();
        }
        $this->assertSame(429, $last);
    }
}
