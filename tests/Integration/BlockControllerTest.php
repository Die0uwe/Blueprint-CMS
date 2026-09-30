<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Integration;

use CommunityFusion\Core\Block\BlockRegistry;
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
        $this->registry = new BlockRegistry($this->db, new CacheManager(['path' => sys_get_temp_dir() . '/bp-bc-' . bin2hex(random_bytes(4))]));
        $this->registry->register(new DiscordWidgetBlock([]));
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
        $this->ctl = new BlockController($this->registry, $this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute("DELETE FROM cf_blocks WHERE zone IN ('sidebar_right','footer')");
        }
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

        $res = $this->ctl->update($this->req(['config' => ['server_id' => '999', 'theme' => 'dark', 'width' => '50000', 'evil' => 'x']]));
        $this->assertSame(200, $res->getStatus());
        $c = $this->config();
        $this->assertSame(1000, $c['width']);
        $this->assertFalse(array_key_exists('evil', $c));
        $this->assertSame('dark', $c['theme']);
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
}
