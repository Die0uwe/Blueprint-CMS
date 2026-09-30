<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Integration;

use CommunityFusion\Core\Application;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Hook\HookManager;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Template\ThemeManager;
use CommunityFusion\Modules\Discord\DiscordNewsAnnouncer;
use CommunityFusion\Modules\Discord\DiscordStatusBlock;
use CommunityFusion\Modules\Discord\DiscordStore;
use CommunityFusion\Modules\News\NewsController;
use CommunityFusion\Modules\News\NewsRepository;
use CommunityFusion\Modules\Settings\SettingsRepository;
use CommunityFusion\Tests\Support\FakeDiscordTransport;
use CommunityFusion\Tests\Support\TestAuth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/TestAuth.php';
require_once __DIR__ . '/../Support/FakeDiscordTransport.php';

/** news.published → Discord (announcer + NewsController-haakje) en het blok discord-status. Draait alleen met BP_TEST_DB. */
final class DiscordNewsTest extends TestCase
{
    private const G  = '123456789012345678';
    private const WH = 'https://discord.com/api/webhooks/123456789012345678/SECRETtokenABCD';

    private Connection $db;
    private SettingsRepository $settings;
    private CacheManager $cache;
    private FakeDiscordTransport $t;
    /** @var list<array{0:string,1:callable}> */
    private array $listeners = [];

    protected function setUp(): void
    {
        $name = getenv('BP_TEST_DB');
        if ($name === false || $name === '') {
            $this->markTestSkipped('BP_TEST_DB niet gezet.');
        }
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('k', 32));
        $_ENV['APP_URL'] = 'https://site.example.test';
        $this->db = new Connection([
            'host' => getenv('BP_TEST_HOST') ?: '127.0.0.1', 'name' => $name,
            'user' => (string) getenv('BP_TEST_USER'), 'password' => (string) getenv('BP_TEST_PASS'),
        ]);
        $this->cleanDb();
        $this->cache = new CacheManager(['path' => sys_get_temp_dir() . '/bp-dcn-' . bin2hex(random_bytes(4))]);
        $this->settings = new SettingsRepository($this->db, $this->cache);
        $this->t = new FakeDiscordTransport();
    }

    protected function tearDown(): void
    {
        foreach ($this->listeners as [$hook, $cb]) {
            Application::getInstance()->getHooks()->removeAction($hook, $cb);
        }
        if (isset($this->db)) {
            $this->cleanDb();
            TestAuth::cleanup($this->db);
        }
        unset($_ENV['APP_URL']);
    }

    private function cleanDb(): void
    {
        $this->db->execute("DELETE FROM cf_news WHERE title LIKE 'Dtest %'");
        $this->db->execute("DELETE FROM cf_settings WHERE `group` = 'discord'");
    }

    private function configure(bool $announce = true, string $webhook = self::WH): void
    {
        $this->settings->set('discord', 'webhook_url', $webhook, 'encrypted');
        $this->settings->set('discord', 'announce_news', $announce ? '1' : '0', 'bool');
    }

    private function listen(string $hook, callable $cb): void
    {
        Application::getInstance()->getHooks()->addAction($hook, $cb);
        $this->listeners[] = [$hook, $cb];
    }

    // ─── announcer ────────────────────────────────────────────────────────

    #[Test]
    public function announcesAnEmbedWithTitleLinkAndSummary(): void
    {
        $uid = $this->user();
        $this->db->execute("INSERT INTO cf_news (author_id, slug, title, summary, content, status, published_at) VALUES (?, 'dtest-a', 'Dtest A', '<b>Kort</b> &amp; krachtig', 'x', 'published', NOW())", [$uid]);
        $id = (int) $this->db->fetchOne("SELECT id FROM cf_news WHERE slug = 'dtest-a'")['id'];
        $this->configure();
        $this->t->queue(204, '');
        $r = (new DiscordNewsAnnouncer($this->db, $this->t))->announce(['id' => $id, 'title' => 'Dtest A', 'slug' => 'dtest-a', 'url' => 'https://site.example.test/news/dtest-a']);
        $this->assertSame(['sent' => true, 'reason' => 'ok'], $r);
        $this->assertSame(self::WH, $this->t->last()['url']);
        $e = $this->t->lastJson()['embeds'][0];
        $this->assertSame('Dtest A', $e['title']);
        $this->assertSame('https://site.example.test/news/dtest-a', $e['url']);
        $this->assertSame('Kort & krachtig', $e['description']);
        $this->assertSame(['parse' => []], $this->t->lastJson()['allowed_mentions']);
    }

    #[Test]
    public function doesNothingWhenOffOrWithoutAValidWebhook(): void
    {
        $this->configure(false);
        $this->assertSame('uit', (new DiscordNewsAnnouncer($this->db, $this->t))->announce(['id' => 1, 'title' => 'T', 'url' => 'https://a.test/x'])['reason']);
        $this->configure(true, '');
        $this->assertSame('geen_webhook', (new DiscordNewsAnnouncer($this->db, $this->t))->announce(['title' => 'T'])['reason']);
        // iemand heeft via het generieke instellingenscherm een kwaadaardige URL opgeslagen: nooit versturen
        $this->configure(true, 'https://evil.example.test/api/webhooks/123456789012345678/tok');
        $this->assertSame('geen_webhook', (new DiscordNewsAnnouncer($this->db, $this->t))->announce(['title' => 'T'])['reason']);
        $this->configure();
        $this->assertSame('geen_titel', (new DiscordNewsAnnouncer($this->db, $this->t))->announce(['title' => ' '])['reason']);
        $this->assertCount(0, $this->t->requests);
    }

    #[Test]
    public function discordFailuresNeverEscapeTheHook(): void
    {
        $this->configure();
        $hooks = new HookManager();
        DiscordNewsAnnouncer::register($hooks, $this->db, $this->t);
        $payload = ['id' => 0, 'title' => 'T', 'slug' => 't', 'url' => 'https://a.test/news/t'];

        foreach ([
            fn() => $this->t->queue(500, []),
            fn() => $this->t->queue(404, ['message' => 'Unknown Webhook']),
            fn() => $this->t->queue(429, ['retry_after' => 3]),
            fn() => $this->t->queueException(new \RuntimeException('boem')),
            fn() => $this->t->queueException(new \Error('zelfs een Error')),
        ] as $arrange) {
            $arrange();
            $hooks->doAction('news.published', $payload);   // mag nooit gooien
        }
        $hooks->doAction('news.published', 'onzin');
        $hooks->doAction('news.published');
        $this->assertTrue(count($this->t->requests) >= 5);
    }

    // ─── NewsController-haakje ────────────────────────────────────────────

    private function user(): int
    {
        $tag = bin2hex(random_bytes(4));
        $this->db->execute("INSERT INTO cf_users (username, email, password_hash) VALUES (?, ?, 'x')", ["t_{$tag}", "t_{$tag}@example.test"]);
        return (int) $this->db->fetchOne('SELECT id FROM cf_users WHERE username = ?', ["t_{$tag}"])['id'];
    }

    private function newsController(): NewsController
    {
        $auth = TestAuth::make($this->db, ['news.create']);
        $theme = (new \ReflectionClass(ThemeManager::class))->newInstanceWithoutConstructor();
        return new NewsController(new NewsRepository($this->db, $this->cache), $theme, $auth);
    }

    private function post(array $body, array $params = []): Request
    {
        $_POST = ['_csrf_token' => CsrfProtection::getToken()];
        $r = new Request('POST', '/x', [], $body + $_POST, [], [], [], []);
        $r->setParams($params);
        return $r;
    }

    #[Test]
    public function newsControllerFiresTheHookOnlyOnTheFirstPublication(): void
    {
        $fired = [];
        $this->listen('news.published', function (array $p) use (&$fired) { $fired[] = $p; });
        $c = $this->newsController();

        // concept: niets
        $c->store($this->post(['title' => 'Dtest concept', 'content' => 'x', 'status' => 'draft']));
        $this->assertCount(0, $fired);
        $id = (int) $this->db->fetchOne("SELECT id FROM cf_news WHERE title = 'Dtest concept'")['id'];

        // concept → gepubliceerd: één keer, met [id,title,slug,url]
        $c->update($this->post(['title' => 'Dtest concept', 'content' => 'x', 'status' => 'published'], ['id' => (string) $id]));
        $this->assertCount(1, $fired);
        $this->assertSame($id, $fired[0]['id']);
        $this->assertSame('Dtest concept', $fired[0]['title']);
        $this->assertSame('dtest-concept', $fired[0]['slug']);
        $this->assertSame('https://site.example.test/news/dtest-concept', $fired[0]['url']);
        $this->assertSame(['id', 'title', 'slug', 'url'], array_keys($fired[0]));

        // nogmaals bewerken terwijl het al gepubliceerd is: niets
        $c->update($this->post(['title' => 'Dtest concept', 'content' => 'y', 'status' => 'published'], ['id' => (string) $id]));
        $this->assertCount(1, $fired);

        // terug naar concept en opnieuw publiceren: niet "voor het eerst"
        $c->update($this->post(['title' => 'Dtest concept', 'content' => 'y', 'status' => 'draft'], ['id' => (string) $id]));
        $c->update($this->post(['title' => 'Dtest concept', 'content' => 'y', 'status' => 'published'], ['id' => (string) $id]));
        $this->assertCount(1, $fired);

        // direct gepubliceerd aanmaken: wél
        $c->store($this->post(['title' => 'Dtest direct', 'content' => 'x', 'status' => 'published']));
        $this->assertCount(2, $fired);
        $this->assertSame('dtest-direct', $fired[1]['slug']);

        // archief telt niet
        $c->store($this->post(['title' => 'Dtest archief', 'content' => 'x', 'status' => 'archived']));
        $this->assertCount(2, $fired);
    }

    #[Test]
    public function aFailingListenerNeverBreaksPublishing(): void
    {
        $this->listen('news.published', function () { throw new \RuntimeException('Discord ligt eruit'); });
        $res = $this->newsController()->store($this->post(['title' => 'Dtest kapot', 'content' => 'x', 'status' => 'published']));
        $this->assertSame(302, $res->getStatus());
        $row = $this->db->fetchOne("SELECT status FROM cf_news WHERE title = 'Dtest kapot'");
        $this->assertSame('published', $row['status'], 'artikel is gewoon opgeslagen en gepubliceerd');
    }

    #[Test]
    public function endToEndPublishingSendsTheEmbed(): void
    {
        $this->configure();
        $this->t->queue(204, '');
        $hooks = Application::getInstance()->getHooks();
        DiscordNewsAnnouncer::register($hooks, $this->db, $this->t);   // blijft hangen op de singleton; hieronder opruimen
        try {
            $this->newsController()->store($this->post(['title' => 'Dtest e2e', 'content' => 'x', 'summary' => 'Samenvatting', 'status' => 'published']));
        } finally {
            // alle listeners van dit hook op de singleton weghalen zodat andere tests er geen last van hebben
            (function () { $this->actions['news.published'] = []; })->call($hooks);
        }
        $this->assertCount(1, $this->t->requests);
        $e = $this->t->lastJson()['embeds'][0];
        $this->assertSame('Dtest e2e', $e['title']);
        $this->assertSame('https://site.example.test/news/dtest-e2e', $e['url']);
        $this->assertSame('Samenvatting', $e['description']);
    }

    // ─── blok discord-status ──────────────────────────────────────────────

    private function block(): DiscordStatusBlock
    {
        return new DiscordStatusBlock(new DiscordStore($this->db), $this->cache, $this->t);
    }

    #[Test]
    public function statusBlockShowsCountsAndCachesOnlySuccess(): void
    {
        $this->settings->set('discord', 'guild_id', self::G);
        $this->settings->set('discord', 'bot_token', 'BOTTOKEN-abcdefghij', 'encrypted');
        $b = $this->block();

        // fout: alleen kort (negatief) gecachet, zodat een storing niet bij elke paginaweergave een Discord-call kost
        $this->t->queue(403, ['message' => 'Missing Access', 'code' => 50001]);
        $html = $b->render([]);
        $this->assertStringContainsString('geen toegang', $html);
        $this->assertStringNotContainsString('BOTTOKEN', $html);
        $n0 = count($this->t->requests);
        for ($i = 0; $i < 5; $i++) {
            $this->assertStringContainsString('geen toegang', $b->render([]));
        }
        $this->assertCount($n0, $this->t->requests, 'fout wordt kort gecachet: geen nieuwe Discord-calls');

        // na afloop van de negatieve cache: succes direct zichtbaar, één request
        $this->cache->delete('discord.status.err.' . self::G);
        $this->t->queue(200, ['name' => '<b>Slayers</b>', 'approximate_member_count' => 1234, 'approximate_presence_count' => 56]);
        $html = $b->render(['invite_url' => 'https://discord.gg/abc123']);
        $this->assertStringContainsString('&lt;b&gt;Slayers&lt;/b&gt;', $html);
        $this->assertStringContainsString('1.234 leden', $html);
        $this->assertStringContainsString('56 online', $html);
        $this->assertStringContainsString('href="https://discord.gg/abc123"', $html);
        $this->assertSame('GET', $this->t->last()['method']);
        $this->assertStringContainsString('with_counts=true', $this->t->last()['url']);
        $n = count($this->t->requests);

        // tweede keer: uit de cache
        $this->assertStringContainsString('1.234 leden', $b->render([]));
        $this->assertCount($n, $this->t->requests);
    }

    #[Test]
    public function statusBlockHandlesMissingConfigAndUnsafeInviteLinks(): void
    {
        $b = $this->block();
        $this->assertStringContainsString('Server ID', $b->render([]));
        $this->settings->set('discord', 'guild_id', self::G);
        $this->assertStringContainsString('Bot Token ontbreekt', $b->render([]));
        $this->assertCount(0, $this->t->requests);

        $this->assertNull(DiscordStatusBlock::safeInvite('javascript:alert(1)'));
        $this->assertNull(DiscordStatusBlock::safeInvite('https://evil.test/invite/abc'));
        $this->assertNull(DiscordStatusBlock::safeInvite('http://discord.gg/abc'));
        $this->assertNull(DiscordStatusBlock::safeInvite('https://discord.gg/abc"onclick="x'));
        $this->assertSame('https://discord.com/invite/abc-1_2', DiscordStatusBlock::safeInvite('https://discord.com/invite/abc-1_2'));
        $this->assertSame('discord-status', $b->getSlug());
        $this->assertArrayHasKey('server_id', $b->getConfigSchema());
    }
}
