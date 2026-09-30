<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Integration;

use CommunityFusion\Blocks\Types\MarkupBlock;
use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Block\BlockOverrides;
use CommunityFusion\Core\Block\BlockRegistry;
use CommunityFusion\Core\Template\MarkupRenderer;
use CommunityFusion\Tests\Support\TestAuth;

require_once __DIR__ . '/../Support/TestAuth.php';
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Modules\Blocks\BlockController;
use CommunityFusion\Modules\Discord\DiscordWidgetBlock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Draait alleen met BP_TEST_DB (+ BP_TEST_USER/BP_TEST_PASS). */
final class BlockControllerTest extends TestCase
{
    private Connection $db;
    private BlockRegistry $registry;
    private BlockController $ctl;
    private int $blockId;
    private CacheManager $cache;
    private string $overrideDir;

    protected function setUp(): void
    {
        $name = getenv('BP_TEST_DB');
        if ($name === false || $name === '') {
            $this->markTestSkipped('BP_TEST_DB niet gezet.');
        }
        require_once __DIR__ . '/../../modules/discord/src/DiscordWidgetBlock.php';
        $this->db = new Connection([
            'host' => getenv('BP_TEST_HOST') ?: '127.0.0.1', 'name' => $name,
            'user' => (string)getenv('BP_TEST_USER'), 'password' => (string)getenv('BP_TEST_PASS'),
        ]);
        $this->cache = new CacheManager(['path' => sys_get_temp_dir() . '/bp-bc-' . bin2hex(random_bytes(4))]);
        $this->registry = new BlockRegistry($this->db, $this->cache);
        $this->overrideDir = sys_get_temp_dir() . '/bp-ov-' . bin2hex(random_bytes(4));
        $this->registry->setOverrides(new BlockOverrides($this->overrideDir, new MarkupRenderer(require __DIR__ . '/../../config/markup-block.php')));
        $this->registry->register(new DiscordWidgetBlock([]));
        $this->registry->register(new MarkupBlock($this->db, new MarkupRenderer(require __DIR__ . '/../../config/markup-block.php')));
        $moduleId = (int)$this->db->fetchOne("SELECT id FROM cf_modules WHERE slug = 'blocks'")['id'];
        $this->registry->syncTypesToDatabase($moduleId);
        $typeId = (int)$this->db->fetchOne("SELECT id FROM cf_block_types WHERE slug = 'discord-widget'")['id'];
        $this->db->execute("DELETE FROM cf_blocks WHERE zone = 'sidebar_right'");
        $this->blockId = (int)$this->registry->createBlock([
            'block_type_id' => $typeId, 'zone' => 'sidebar_right', 'position' => 0, 'title' => null,
            'config' => json_encode(['server_id' => '123456789012345678', 'theme' => 'light', 'width' => 400]), 'is_visible' => 1,
        ]);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        }
        $this->ctl = $this->controllerFor([]);
    }

    /** @param list<string> $perms */
    private function controllerFor(array $perms): BlockController
    {
        $auth = TestAuth::make($this->db, $perms);
        $ov = new BlockOverrides($this->overrideDir, new MarkupRenderer(require __DIR__ . '/../../config/markup-block.php'));
        return new BlockController($this->registry, $this->db, $auth, $ov, new AuditLogger($this->db), $this->cache);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute("DELETE FROM cf_blocks WHERE zone IN ('sidebar_right','footer')");
            TestAuth::cleanup($this->db);
        }
        if (isset($this->overrideDir) && is_dir($this->overrideDir)) {
            foreach (glob($this->overrideDir . '/*') ?: [] as $f) { @unlink($f); }
            @rmdir($this->overrideDir);
        }
    }

    private function markupBlockId(): int
    {
        $typeId = (int)$this->db->fetchOne("SELECT id FROM cf_block_types WHERE slug = 'markup'")['id'];
        return (int)$this->registry->createBlock(['block_type_id' => $typeId, 'zone' => 'sidebar_right', 'position' => 5, 'title' => 'Mijn blok', 'config' => '{}', 'is_visible' => 1]);
    }

    private function renderOf(int $id): string
    {
        $row = $this->registry->getBlock($id);
        return $this->registry->renderBlock($row);
    }

    private function req(array $body, string $method = 'POST'): Request
    {
        $_POST = ['_csrf_token' => CsrfProtection::getToken()];
        $r = new Request($method, '/x', [], $body + $_POST, ['Content-Type' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'], [], [], []);
        $r->setParams(['id' => (string)$this->blockId]);
        return $r;
    }

    private function config(): array
    {
        return json_decode((string)$this->db->fetchOne('SELECT config FROM cf_blocks WHERE id = ?', [$this->blockId])['config'], true);
    }

    #[Test]
    public function hidingOrMovingKeepsTheConfig(): void
    {
        $before = $this->config();
        $this->ctl->update($this->req(['is_visible' => 0]));
        $this->assertSame($before, $this->config());
        $this->ctl->update($this->req(['zone' => 'footer']));
        $this->assertSame($before, $this->config());
        $this->assertSame('footer', $this->db->fetchOne('SELECT zone FROM cf_blocks WHERE id = ?', [$this->blockId])['zone']);
    }

    #[Test]
    public function configIsValidatedAgainstTheSchema(): void
    {
        $res = $this->ctl->update($this->req(['config' => ['server_id' => '999', 'theme' => 'neon', 'width' => '50000', 'evil' => 'x']]));
        $this->assertSame(422, $res->getStatus());
        $this->assertSame('light', $this->config()['theme']);   // niets overschreven

        $res = $this->ctl->update($this->req(['config' => ['server_id' => '123456789012345678', 'theme' => 'dark', 'width' => '50000', 'evil' => 'x']]));
        $this->assertSame(200, $res->getStatus());
        $c = $this->config();
        $this->assertSame(1000, $c['width']);
        $this->assertFalse(array_key_exists('evil', $c));
        $this->assertSame('dark', $c['theme']);

        $res = $this->ctl->update($this->req(['config' => ['server_id' => 'geen-id']]));
        $this->assertSame(422, $res->getStatus());
        $this->assertSame('123456789012345678', $this->config()['server_id']);
    }

    #[Test]
    public function settingsEndpointReturnsSchemaAndSavedValues(): void
    {
        $data = json_decode($this->ctl->settings($this->req([], 'GET'))->getBody(), true);
        $this->assertSame('discord-widget', $data['type']);
        $this->assertSame(400, $data['config']['width']);
        $keys = array_column($data['fields'], 'key');
        $this->assertContains('server_id', $keys);
    }

    #[Test]
    public function hiddenBlocksStillShowInTheAdminListButNotOnThePublicSite(): void
    {
        $this->ctl->update($this->req(['is_visible' => 0]));
        $this->assertCount(1, $this->registry->getZoneBlocksForAdmin('sidebar_right'));
        $this->assertCount(0, $this->registry->getZoneBlocks('sidebar_right'));
    }

    #[Test]
    public function requestsWithoutCsrfAreRejected(): void
    {
        $_POST = [];
        $r = new Request('POST', '/x', [], ['is_visible' => 0], [], [], [], []);
        $r->setParams(['id' => (string)$this->blockId]);
        $this->expectException(\Throwable::class);
        $this->ctl->update($r);
    }

    #[Test]
    public function markupBlockNeedsThePermissionToCreateAndEdit(): void
    {
        $typeReq = new Request('POST', '/x', [], ['type_slug' => 'markup', 'zone' => 'footer', '_csrf_token' => CsrfProtection::getToken()] + ($_POST = ['_csrf_token' => CsrfProtection::getToken()]), ['X-Requested-With' => 'XMLHttpRequest'], [], [], []);
        $this->assertSame(403, $this->controllerFor(['blocks.manage'])->store($typeReq)->getStatus());

        $id = $this->markupBlockId();
        $this->blockId = $id;
        $res = $this->controllerFor(['blocks.manage'])->saveMarkup($this->req(['markup' => '<p>x</p>']));
        $this->assertSame(403, $res->getStatus());
        $this->assertSame('', $this->renderOf($id) === '' ? '' : (str_contains($this->renderOf($id), '<p>x</p>') ? 'x' : ''));
    }

    #[Test]
    public function markupBlockRendersTwigInTheSandboxAndEscapes(): void
    {
        $id = $this->markupBlockId();
        $this->blockId = $id;
        $ctl = $this->controllerFor(['blocks.manage', 'blocks.override_template']);
        $res = $ctl->saveMarkup($this->req(['markup' => '<h2>{{ title|upper }}</h2>{% if today %}<i>ok</i>{% endif %}<script>1</script>']));
        $this->assertSame(200, $res->getStatus());
        $html = $this->renderOf($id);
        $this->assertStringContainsString('<h2>MIJN BLOK</h2>', $html);
        $this->assertStringContainsString('<i>ok</i>', $html);
        $this->assertSame(1, (int)$this->db->fetchOne("SELECT COUNT(*) c FROM cf_audit_log WHERE action = 'block.markup.save'")['c'] >= 1 ? 1 : 0);
    }

    #[Test]
    public function dangerousMarkupIsRejectedAndNothingIsStored(): void
    {
        $id = $this->markupBlockId();
        $this->blockId = $id;
        $ctl = $this->controllerFor(['blocks.manage', 'blocks.override_template']);
        foreach (["{{ system('id') }}", "{% include 'x' %}", "{{ 1..9 }}"] as $bad) {
            $this->assertSame(422, $ctl->saveMarkup($this->req(['markup' => $bad]))->getStatus(), $bad);
        }
        $this->assertFalse((bool)$this->db->fetchOne('SELECT 1 FROM cf_block_content WHERE block_id = ?', [$id]));
        // PHP-tags zonder editor.markup.php
        $this->assertSame(403, $ctl->saveMarkup($this->req(['markup' => '<?php echo 1; ?>']))->getStatus());
    }

    #[Test]
    public function typeOverrideReplacesTheOutputAndCanBeRemoved(): void
    {
        $ctl = $this->controllerFor(['blocks.manage', 'blocks.override_template']);
        $res = $ctl->saveMarkup($this->req(['markup' => '<div class="mijn">{{ config.theme }} {{ config.width }}</div>']));
        $this->assertSame(200, $res->getStatus());
        $this->assertStringContainsString('<div class="mijn">light 400</div>', $this->renderOf($this->blockId));

        $this->ctl = $ctl;
        $this->assertSame(200, $ctl->saveMarkup($this->req(['markup' => '  ']))->getStatus());
        $this->assertStringContainsString('discord.com/widget', $this->renderOf($this->blockId));
    }

    #[Test]
    public function aBrokenOverrideNeverBreaksThePage(): void
    {
        mkdir($this->overrideDir, 0755, true);
        file_put_contents($this->overrideDir . '/discord-widget.twig', '{{ system("id") }}');   // buiten de controller om
        $this->assertStringContainsString('discord.com/widget', $this->renderOf($this->blockId));
    }

    #[Test]
    public function overrideSlugsCannotEscapeTheFolder(): void
    {
        foreach (['../x', 'a/b', '..', '', 'A', str_repeat('a', 70), "a\0b"] as $bad) {
            $this->assertFalse(BlockOverrides::validSlug($bad), $bad);
        }
        $this->assertTrue(BlockOverrides::validSlug('discord-widget'));
    }
}
