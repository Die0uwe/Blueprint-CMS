<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Modules;

// Module-klassen worden in productie door Application::registerModuleAutoload() geladen.
require_once __DIR__ . '/../../../modules/discord/src/DiscordWidgetApi.php';
require_once __DIR__ . '/../../../modules/discord/src/DiscordWidgetBlock.php';

use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Modules\Discord\DiscordWidgetApi;
use CommunityFusion\Modules\Discord\DiscordWidgetBlock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Discord widget-blok: alle kamers (iframe) of één gekozen kamer (eigen kaart). Geen netwerk: widget.json staat voorgezaaid in de cache. */
final class DiscordWidgetBlockTest extends TestCase
{
    private const GUILD   = '123456789012345678';
    private const GENERAL = '111111111111111111';
    private const AFK     = '222222222222222222';
    private const EMPTY   = '333333333333333333';

    private CacheManager $cache;

    protected function setUp(): void
    {
        $this->cache = new CacheManager(['driver' => 'file', 'path' => sys_get_temp_dir() . '/cf_cache_' . bin2hex(random_bytes(4))]);
        $this->cache->set('discord.widget.' . self::GUILD, [
            'id'             => self::GUILD,
            'name'           => 'Slayer Alliance',
            'instant_invite' => 'https://discord.gg/abc',
            'channels'       => [
                ['id' => self::GENERAL, 'name' => 'General', 'position' => 1],
                ['id' => self::AFK,     'name' => 'AFK',     'position' => 2],
                ['id' => self::EMPTY,   'name' => 'Leeg',    'position' => 3],
            ],
            'members' => [
                ['id' => '1', 'username' => 'Ouwe',      'status' => 'online', 'channel_id' => self::GENERAL, 'avatar_url' => 'https://cdn.discordapp.com/a.png'],
                ['id' => '2', 'username' => 'Jan',       'status' => 'online', 'channel_id' => self::AFK,     'avatar_url' => 'https://cdn.discordapp.com/b.png'],
                ['id' => '3', 'username' => '<b>x</b>',  'status' => 'online', 'channel_id' => self::GENERAL, 'avatar_url' => 'javascript:alert(1)'],
                ['id' => '4', 'username' => 'Zonderkamer', 'status' => 'online'],
            ],
            'presence_count' => 4,
        ], 60);
    }

    private function block(): DiscordWidgetBlock
    {
        return new DiscordWidgetBlock(['guild_id' => self::GUILD], $this->cache);
    }

    #[Test]
    public function withoutARoomItStillRendersTheOfficialIframe(): void
    {
        $html = $this->block()->render(['theme' => 'light', 'width' => 300, 'height' => 400]);
        $this->assertStringContainsString('https://discord.com/widget?id=' . self::GUILD . '&theme=light', $html);
        $this->assertStringContainsString('width="300"', $html);
        $this->assertStringNotContainsString('cf-discord-room', $html);
    }

    #[Test]
    public function aMissingConfigKeyDoesNotRaiseWarnings(): void
    {
        // Een blok dat nog nooit is opgeslagen heeft een lege config.
        $this->assertStringContainsString('discord.com/widget', $this->block()->render([]));
        $this->assertStringContainsString('niet ingesteld', (new DiscordWidgetBlock([], $this->cache))->render([]));
    }

    #[Test]
    public function aChosenRoomShowsOnlyThatRoomAndItsMembers(): void
    {
        $html = $this->block()->render(['channel_id' => self::GENERAL]);

        $this->assertStringContainsString('cf-discord-room', $html);
        $this->assertStringContainsString('General', $html);
        $this->assertStringContainsString('2 in de kamer', $html);
        $this->assertStringContainsString('Ouwe', $html);
        $this->assertStringNotContainsString('Jan', $html, 'wie in een andere kamer zit, hoort er niet bij');
        $this->assertStringNotContainsString('Zonderkamer', $html);
        $this->assertStringNotContainsString('AFK', $html);
        $this->assertStringNotContainsString('discord.com/widget', $html, 'geen iframe in kamer-modus');
    }

    #[Test]
    public function memberDataIsEscapedAndOnlyHttpsAvatarsAreUsed(): void
    {
        $html = $this->block()->render(['channel_id' => self::GENERAL]);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('https://cdn.discordapp.com/a.png', $html);
    }

    #[Test]
    public function theCardShowsHowManyAreOnlineOnTheServer(): void
    {
        $this->assertStringContainsString('2 in de kamer · 4 online ·', $this->block()->render(['channel_id' => self::GENERAL]));
    }

    #[Test]
    public function anEmptyRoomSaysSo(): void
    {
        $html = $this->block()->render(['channel_id' => self::EMPTY]);
        $this->assertStringContainsString('Leeg', $html);
        $this->assertStringContainsString('0 in de kamer', $html);
        $this->assertStringContainsString('Er zit nu niemand in deze kamer.', $html);
    }

    #[Test]
    public function theJoinButtonPrefersTheConfiguredInviteAndRejectsNonHttps(): void
    {
        $custom = $this->block()->render(['channel_id' => self::GENERAL, 'invite_url' => 'https://discord.gg/mijn']);
        $this->assertStringContainsString('href="https://discord.gg/mijn"', $custom);

        $fallback = $this->block()->render(['channel_id' => self::GENERAL]);
        $this->assertStringContainsString('href="https://discord.gg/abc"', $fallback);

        $evil = $this->block()->render(['channel_id' => self::GENERAL, 'invite_url' => 'javascript:alert(1)']);
        $this->assertStringNotContainsString('javascript:', $evil);
        $this->assertStringContainsString('href="https://discord.gg/abc"', $evil, 'ongeldige invite valt terug op die van de widget');
    }

    #[Test]
    public function anUnknownOrMalformedRoomGivesAClearMessage(): void
    {
        $this->assertStringContainsString('Kamer niet gevonden', $this->block()->render(['channel_id' => '999999999999999999']));
        $this->assertStringContainsString('uit alleen cijfers', $this->block()->render(['channel_id' => 'general']));
    }

    #[Test]
    public function aRoomWithoutCacheOrWidgetDataDegradesGracefully(): void
    {
        $noCache = new DiscordWidgetBlock(['guild_id' => self::GUILD], null);
        $this->assertStringContainsString('niet beschikbaar', $noCache->render(['channel_id' => self::GENERAL]));
    }

    #[Test]
    public function theApiHelperRejectsNonNumericServerIdsWithoutAnyRequest(): void
    {
        $this->assertNull(DiscordWidgetApi::fetch($this->cache, '../../etc'));
        $this->assertNull(DiscordWidgetApi::fetch($this->cache, ''));
        $this->assertIsArray(DiscordWidgetApi::fetch($this->cache, self::GUILD));
    }

    #[Test]
    public function theSchemaExposesTheRoomSettings(): void
    {
        $schema = $this->block()->getConfigSchema();
        $this->assertArrayHasKey('channel_id', $schema);
        $this->assertArrayHasKey('invite_url', $schema);
        $this->assertNotEmpty($schema['channel_id']['help']);
    }
}
