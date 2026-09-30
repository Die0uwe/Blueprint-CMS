<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Integration;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Auth\JWTManager;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Container;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Hook\HookManager;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Router;
use CommunityFusion\Modules\Discord\DiscordModule;
use CommunityFusion\Modules\Discord\DiscordStore;
use CommunityFusion\Modules\Settings\SettingsRepository;
use CommunityFusion\Tests\Support\TestAuth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/TestAuth.php';

/**
 * De echte Router met de module-routes (via de 'router.routes'-hook, zoals in productie): middleware
 * en volgorde t.o.v. de /admin/{path}-catch-all. Draait alleen met BP_TEST_DB.
 */
final class DiscordRoutesTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $name = getenv('BP_TEST_DB');
        if ($name === false || $name === '') {
            $this->markTestSkipped('BP_TEST_DB niet gezet.');
        }
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('k', 32));
        $this->db = new Connection([
            'host' => getenv('BP_TEST_HOST') ?: '127.0.0.1', 'name' => $name,
            'user' => (string) getenv('BP_TEST_USER'), 'password' => (string) getenv('BP_TEST_PASS'),
        ]);
        DiscordStore::ensureSchema($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            TestAuth::cleanup($this->db);
        }
        unset($_SESSION['user_id']);
    }

    /** @param list<string>|null $perms null = niet ingelogd */
    private function router(?array $perms): Router
    {
        unset($_SESSION['user_id']);
        $cache = new CacheManager(['path' => sys_get_temp_dir() . '/bp-dcr-' . bin2hex(random_bytes(4))]);
        $c = new Container();
        $c->instance(Connection::class, $this->db);
        $c->instance(CacheManager::class, $cache);
        $c->instance(JWTManager::class, new JWTManager('test-secret-test-secret-test-secret'));
        $c->instance(AuditLogger::class, new AuditLogger($this->db));
        $c->instance(SettingsRepository::class, new SettingsRepository($this->db, $cache));
        if ($perms !== null) {
            $auth = TestAuth::make($this->db, $perms);
        } else {
            $auth = @new AuthManager($this->db, new \CommunityFusion\Core\Auth\RBAC\RBACManager($this->db, $cache), new JWTManager('test-secret-test-secret-test-secret'), new AuditLogger($this->db));
        }
        $c->instance(AuthManager::class, $auth);
        $hooks = new HookManager();
        $hooks->addAction('router.routes', fn($r) => DiscordModule::registerRoutes($r));
        return new Router($c, $hooks);
    }

    private function get(Router $r, string $path)
    {
        return $r->dispatch(new Request('GET', $path, [], [], [], [], [], []));
    }

    #[Test]
    public function anonymousVisitorsGoToLogin(): void
    {
        $res = $this->get($this->router(null), '/admin/discord');
        $this->assertSame(302, $res->getStatus());
    }

    #[Test]
    public function withoutDiscordAdminTheRouteIsForbidden(): void
    {
        $res = $this->get($this->router(['admin.access', 'blocks.manage']), '/admin/discord');
        $this->assertSame(403, $res->getStatus());
        $res = $this->get($this->router(['admin.access']), '/admin/discord/rollen');
        $this->assertSame(403, $res->getStatus());
    }

    #[Test]
    public function withDiscordAdminTheModuleScreenAnswersInsteadOfTheAdminCatchAll(): void
    {
        $r = $this->router(['discord.admin']);
        foreach (['/admin/discord' => 'Verbinding', '/admin/discord/widget' => 'Server-widget', '/admin/discord/meldingen' => 'Nieuws melden', '/admin/discord/rollen' => 'Rolkoppeling'] as $path => $needle) {
            $res = $this->get($r, $path);
            $this->assertSame(200, $res->getStatus(), $path);
            $this->assertStringContainsString($needle, $res->getBody(), $path);
        }
    }

    #[Test]
    public function postRoutesAreCsrfProtectedThroughTheRouter(): void
    {
        $r = $this->router(['discord.admin']);
        $_POST = [];
        $this->expectException(\Throwable::class);
        $this->expectExceptionCode(403);
        $r->dispatch(new Request('POST', '/admin/discord/rollen/7/verwijderen', [], [], [], [], [], []));
    }

    #[Test]
    public function roleIdParameterMustBeNumeric(): void
    {
        $r = $this->router(['discord.admin']);
        // geen numeriek id: valt niet onder onze route maar onder de catch-all/404, nooit onder de controller
        $res = $r->dispatch(new Request('POST', '/admin/discord/rollen/abc/verwijderen', [], [], [], [], [], []));
        $this->assertTrue(in_array($res->getStatus(), [404, 302, 403], true));
    }

    // ─── module-boot: blok, menu, hooks, queue ────────────────────────────

    /** @param list<string> $perms @return array{0:HookManager,1:\CommunityFusion\Core\Block\BlockRegistry} */
    private function bootModule(array $perms): array
    {
        $app   = \CommunityFusion\Core\Application::getInstance();
        $cache = new CacheManager(['path' => sys_get_temp_dir() . '/bp-dcb-' . bin2hex(random_bytes(4))]);
        $hooks = new HookManager();
        $reg   = new \CommunityFusion\Core\Block\BlockRegistry($this->db, $cache);
        $c = $app->getContainer();
        $c->instance(HookManager::class, $hooks);
        $c->instance(Connection::class, $this->db);
        $c->instance(CacheManager::class, $cache);
        $c->instance(\CommunityFusion\Core\Block\BlockRegistry::class, $reg);
        $c->instance(AuthManager::class, TestAuth::make($this->db, $perms));
        (new DiscordModule())->boot($app);
        return [$hooks, $reg];
    }

    #[Test]
    public function bootRegistersTheStatusBlockNewsHookAndMenuItem(): void
    {
        [$hooks, $reg] = $this->bootModule(['discord.admin']);
        $this->assertNotNull($reg->find('discord-status'));
        $this->assertNotNull($reg->find('discord-widget'));
        $this->assertNotNull($reg->find('discord-online'));
        $this->assertTrue($hooks->hasAction('news.published'));
        $this->assertTrue($hooks->hasAction('router.routes'));

        $items = $hooks->applyFilters('admin.menu', []);
        $this->assertCount(1, $items);
        $this->assertMatchesRegularExpression('#^/admin/[A-Za-z0-9/_\-]{1,100}$#', $items[0]['href']);
        $this->assertTrue(mb_strlen($items[0]['label']) <= 40);
        $this->assertSame('/admin/discord', $items[0]['href']);
    }

    #[Test]
    public function menuItemIsHiddenWithoutDiscordAdmin(): void
    {
        [$hooks] = $this->bootModule(['blocks.manage']);
        $this->assertSame([], $hooks->applyFilters('admin.menu', []));
    }

    #[Test]
    public function loginQueuesAWorkerCompatibleSyncJobOnlyForLinkedUsers(): void
    {
        new \CommunityFusion\Core\Queue\QueueManager($this->db);   // maakt cf_queue_jobs aan als die nog niet bestaat
        $this->db->execute("DELETE FROM cf_settings WHERE `group` = 'discord'");
        $this->db->execute("DELETE FROM cf_queue_jobs WHERE queue = 'discord-sync'");
        $cache = new CacheManager(['path' => sys_get_temp_dir() . '/bp-dcq-' . bin2hex(random_bytes(4))]);
        $s = new SettingsRepository($this->db, $cache);
        $s->set('discord', 'guild_id', '123456789012345678');
        $s->set('discord', 'bot_token', 'BOTTOKEN-abcdefghij', 'encrypted');
        [$hooks] = $this->bootModule(['discord.admin']);
        $uid = (int) $_SESSION['user_id'];

        $hooks->doAction('user.login', ['id' => $uid]);
        $this->assertCount(0, $this->db->fetchAll("SELECT 1 FROM cf_queue_jobs WHERE queue = 'discord-sync'"), 'zonder Discord-koppeling geen job');

        $this->db->execute("INSERT INTO cf_user_oauth (user_id, provider, provider_user_id, access_token) VALUES (?, 'discord', '423456789012345678', 'x')", [$uid]);
        $hooks->doAction('user.login', ['id' => $uid]);
        $rows = $this->db->fetchAll("SELECT payload FROM cf_queue_jobs WHERE queue = 'discord-sync'");
        $this->assertCount(1, $rows);
        // precies wat de worker doet: unserialize + handle() bestaat
        $job = unserialize($rows[0]['payload']);
        $this->assertTrue(is_object($job) && method_exists($job, 'handle'));
        $this->assertSame($uid, $job->userId);

        $s->set('discord', 'bot_token', '', 'encrypted');
        $hooks->doAction('user.login', ['id' => $uid]);
        $this->assertCount(1, $this->db->fetchAll("SELECT 1 FROM cf_queue_jobs WHERE queue = 'discord-sync'"), 'zonder bot-token geen nieuwe job');

        $this->db->execute("DELETE FROM cf_queue_jobs WHERE queue = 'discord-sync'");
        $this->db->execute("DELETE FROM cf_settings WHERE `group` = 'discord'");
        $this->db->execute("DELETE FROM cf_user_oauth WHERE user_id = ?", [$uid]);
    }
}
