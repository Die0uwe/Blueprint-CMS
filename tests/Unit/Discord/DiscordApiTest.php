<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Discord;

use CommunityFusion\Modules\Discord\DiscordApi;
use CommunityFusion\Modules\Discord\DiscordApiException;
use CommunityFusion\Modules\Discord\DiscordRoleSyncJob;
use CommunityFusion\Modules\Discord\DiscordTransportException;
use CommunityFusion\Tests\Support\FakeDiscordTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Support/FakeDiscordTransport.php';

final class DiscordApiTest extends TestCase
{
    private const G = '123456789012345678';
    private const TOKEN = 'MTIzNDU2.Nzg5MDEy.secret-token_XYZ';

    private function api(FakeDiscordTransport $t): DiscordApi
    {
        return new DiscordApi(self::TOKEN, $t);
    }

    #[Test]
    public function requestsCarryTheBotTokenAndWithCounts(): void
    {
        $t = new FakeDiscordTransport();
        $t->queue(200, ['id' => self::G, 'name' => 'Slayers', 'approximate_member_count' => 10]);
        $g = $this->api($t)->getGuild(self::G);
        $this->assertSame('Slayers', $g['name']);
        $req = $t->last();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('https://discord.com/api/v10/guilds/' . self::G . '?with_counts=true', $req['url']);
        $this->assertTrue($t->hasHeader(0, 'Authorization: Bot ' . self::TOKEN));
    }

    #[Test]
    public function errorStatusesAreTranslatedSeparatelyAndKeepDiscordsMessage(): void
    {
        $cases = [
            [401, ['message' => '401: Unauthorized', 'code' => 0], 'bot-token', '401: Unauthorized'],
            [403, ['message' => 'Missing Permissions', 'code' => 50013], 'Serverbeheer', 'Missing Permissions'],
            [404, ['message' => 'Unknown Guild', 'code' => 10004], 'Niet gevonden', 'Unknown Guild'],
            [429, ['message' => 'You are being rate limited.', 'retry_after' => 1.2], '2 seconde', 'rate limited'],
            [503, [], 'storing', ''],
        ];
        foreach ($cases as [$status, $body, $needle, $discord]) {
            $t = new FakeDiscordTransport();
            $t->queue($status, $body);
            try {
                $this->api($t)->getGuild(self::G);
                $this->fail("status {$status} had moeten gooien");
            } catch (DiscordApiException $e) {
                $this->assertSame($status, $e->status);
                $this->assertStringContainsString($needle, $e->getMessage(), "status {$status}");
                $this->assertStringContainsString($discord, $e->getMessage(), "status {$status}");
                $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            }
        }
    }

    #[Test]
    public function retryAfterComesFromBodyOrHeader(): void
    {
        $t = new FakeDiscordTransport();
        $t->queue(429, ['retry_after' => 4.5])->queue(429, ['message' => 'x'], ['retry-after' => '9']);
        foreach ([4.5, 9.0] as $expected) {
            try {
                $this->api($t)->getGuild(self::G);
                $this->fail('429');
            } catch (DiscordApiException $e) {
                $this->assertSame($expected, $e->retryAfter);
            }
        }
    }

    #[Test]
    public function discordMessageIsSanitised(): void
    {
        $t = new FakeDiscordTransport();
        $t->queue(400, ['message' => "kwaad\r\nX-Injected: 1" . str_repeat('a', 500)]);
        try {
            $this->api($t)->getGuild(self::G);
            $this->fail('400');
        } catch (DiscordApiException $e) {
            $this->assertStringNotContainsString("\n", $e->getMessage());
            $this->assertTrue(mb_strlen((string) $e->discordMessage) <= 200);
        }
    }

    #[Test]
    public function nonSnowflakeIdsNeverReachTheUrl(): void
    {
        foreach (['../users/@me', '123', '123456789012345678/../x', '12345678901234a', '', "123456789012345678\n"] as $bad) {
            $t = new FakeDiscordTransport();
            foreach (['getGuild', 'getWidgetSettings', 'getChannels', 'getRoles'] as $m) {
                try {
                    $this->api($t)->$m($bad);
                    $this->fail("{$m}({$bad})");
                } catch (DiscordApiException) {
                }
            }
            try {
                $this->api($t)->setWidget(self::G, true, $bad);
                $this->fail('channel');
            } catch (DiscordApiException) {
            }
            try {
                $this->api($t)->getGuildMember(self::G, $bad);
                $this->fail('member');
            } catch (DiscordApiException) {
            }
            $this->assertCount(0, $t->requests, "niets verstuurd voor id '{$bad}'");
        }
    }

    #[Test]
    public function aMalformedTokenMakesNoRequest(): void
    {
        foreach (['', 'kort', "tok\r\nX: y", 'met spatie in het token'] as $tok) {
            $t = new FakeDiscordTransport();
            try {
                (new DiscordApi($tok, $t))->getGuild(self::G);
                $this->fail('token');
            } catch (DiscordApiException $e) {
                $this->assertStringContainsString('bot-token', $e->getMessage());
            }
            $this->assertCount(0, $t->requests);
        }
    }

    #[Test]
    public function setWidgetPatchesEnabledAndChannel(): void
    {
        $t = new FakeDiscordTransport();
        $t->queue(200, ['enabled' => true, 'channel_id' => '223456789012345678']);
        $this->api($t)->setWidget(self::G, true, '223456789012345678');
        $req = $t->last();
        $this->assertSame('PATCH', $req['method']);
        $this->assertSame('https://discord.com/api/v10/guilds/' . self::G . '/widget', $req['url']);
        $this->assertSame(['enabled' => true, 'channel_id' => '223456789012345678'], $t->lastJson());
        $this->assertTrue($t->hasHeader(0, 'Content-Type: application/json'));
    }

    #[Test]
    public function channelsAreFilteredToTextAndAnnouncementAndRolesLoseEveryone(): void
    {
        $t = new FakeDiscordTransport();
        $t->queue(200, [
            ['id' => '223456789012345671', 'name' => 'voice', 'type' => 2, 'position' => 0],
            ['id' => '223456789012345672', 'name' => 'algemeen', 'type' => 0, 'position' => 2],
            ['id' => '223456789012345673', 'name' => 'nieuws', 'type' => 5, 'position' => 1],
            ['id' => '223456789012345674', 'name' => 'categorie', 'type' => 4, 'position' => 0],
        ])->queue(200, [
            ['id' => self::G, 'name' => '@everyone', 'position' => 0],
            ['id' => '323456789012345678', 'name' => 'Officer', 'position' => 5, 'managed' => false],
            ['id' => '323456789012345679', 'name' => 'Bot', 'position' => 9, 'managed' => true],
        ]);
        $ch = $this->api($t)->getChannels(self::G);
        $this->assertSame(['nieuws', 'algemeen'], array_column($ch, 'name'));
        $roles = $this->api($t)->getRoles(self::G);
        $this->assertSame(['Bot', 'Officer'], array_column($roles, 'name'));
    }

    #[Test]
    public function memberNotFoundIsNullButOtherErrorsThrow(): void
    {
        $t = new FakeDiscordTransport();
        $t->queue(404, ['message' => 'Unknown Member', 'code' => 10007])->queue(500, []);
        $this->assertNull($this->api($t)->getGuildMember(self::G, '423456789012345678'));
        $this->expectException(DiscordApiException::class);
        $this->api($t)->getGuildMember(self::G, '423456789012345678');
    }

    #[Test]
    public function networkFailureBecomesAnApiException(): void
    {
        $t = new FakeDiscordTransport();
        $t->queueException(new DiscordTransportException('Discord is niet bereikbaar (verbinding mislukt of time-out).'));
        try {
            $this->api($t)->getGuild(self::G);
            $this->fail('netwerk');
        } catch (DiscordApiException $e) {
            $this->assertSame(0, $e->status);
            $this->assertStringContainsString('niet bereikbaar', $e->getMessage());
        }
    }

    #[Test]
    public function nonJsonSuccessIsAnError(): void
    {
        $t = new FakeDiscordTransport();
        $t->queue(200, '<html>captive portal</html>');
        $this->expectException(DiscordApiException::class);
        $this->api($t)->getGuild(self::G);
    }

    #[Test]
    public function testConnectionAllGood(): void
    {
        $t = (new FakeDiscordTransport())
            ->route('GET /users/@me', 200, ['id' => '999999999999999999', 'username' => 'SlayerBot'])
            ->route('GET /guilds/' . self::G, 200, ['name' => 'Slayer Alliance', 'approximate_member_count' => 321])
            ->route('GET /guilds/' . self::G . '/widget', 200, ['enabled' => true, 'channel_id' => '223456789012345672'])
            ->route('GET /guilds/' . self::G . '/channels', 200, [['id' => '223456789012345672', 'name' => 'algemeen', 'type' => 0, 'position' => 0]])
            ->route('GET /users/@me/guilds', 200, [['id' => self::G, 'name' => 'Slayer Alliance', 'permissions' => (string) (DiscordApi::PERM_MANAGE_GUILD | DiscordApi::PERM_VIEW_CHANNEL)]]);
        $s = $this->api($t)->testConnection(self::G);
        $this->assertTrue($s->tokenValid);
        $this->assertSame('SlayerBot', $s->botName);
        $this->assertTrue($s->inGuild);
        $this->assertSame('Slayer Alliance', $s->guildName);
        $this->assertSame(321, $s->memberCount);
        $this->assertTrue($s->widgetEnabled);
        $this->assertSame('223456789012345672', $s->widgetChannelId);
        $this->assertSame('algemeen', $s->widgetChannelName);
        $this->assertTrue($s->canManageGuild);
        $this->assertContains('Serverbeheer (MANAGE_GUILD)', $s->permissions);
        $this->assertSame([], $s->problems);
    }

    #[Test]
    public function testConnectionInvalidTokenStopsEarly(): void
    {
        $t = (new FakeDiscordTransport())->route('GET /users/@me', 401, ['message' => '401: Unauthorized']);
        $s = $this->api($t)->testConnection(self::G);
        $this->assertFalse($s->tokenValid);
        $this->assertNull($s->inGuild);
        $this->assertCount(1, $t->requests);
        $this->assertStringContainsString('bot-token', $s->problems[0]);
    }

    #[Test]
    public function testConnectionBotNotInServer(): void
    {
        $t = (new FakeDiscordTransport())
            ->route('GET /users/@me', 200, ['username' => 'SlayerBot'])
            ->route('GET /guilds/' . self::G, 404, ['message' => 'Unknown Guild', 'code' => 10004]);
        $s = $this->api($t)->testConnection(self::G);
        $this->assertTrue($s->tokenValid);
        $this->assertFalse($s->inGuild);
        $this->assertStringContainsString('niet in deze server', $s->problems[0]);
        $this->assertNull($s->widgetEnabled);
    }

    #[Test]
    public function testConnectionReportsMissingManageGuildAndWidgetErrorWithoutThrowing(): void
    {
        $t = (new FakeDiscordTransport())
            ->route('GET /users/@me', 200, ['username' => 'B'])
            ->route('GET /guilds/' . self::G, 200, ['name' => 'S'])
            ->route('GET /guilds/' . self::G . '/widget', 403, ['message' => 'Missing Permissions', 'code' => 50013])
            ->route('GET /users/@me/guilds', 200, [['id' => self::G, 'permissions' => (string) DiscordApi::PERM_VIEW_CHANNEL]]);
        $s = $this->api($t)->testConnection(self::G);
        $this->assertNull($s->widgetEnabled);
        $this->assertFalse($s->canManageGuild);
        $this->assertStringContainsString('Missing Permissions', implode(' ', $s->problems));
    }

    #[Test]
    public function testConnectionRejectsABadGuildIdWithoutRequests(): void
    {
        $t = new FakeDiscordTransport();
        $s = $this->api($t)->testConnection('abc');
        $this->assertCount(0, $t->requests);
        $this->assertCount(1, $s->problems);
    }

    #[Test]
    public function inviteUrlUsesPermissions1056AndBotScope(): void
    {
        $u = DiscordApi::inviteUrl('123456789012345678');
        $this->assertStringContainsString('client_id=123456789012345678', $u);
        $this->assertStringContainsString('permissions=1056', $u);
        $this->assertStringContainsString('scope=bot', $u);
        $this->assertSame(1056, DiscordApi::PERM_MANAGE_GUILD | DiscordApi::PERM_VIEW_CHANNEL);
        $this->assertNull(DiscordApi::inviteUrl(''));
        $this->assertNull(DiscordApi::inviteUrl('abc&x=1'));
    }

    #[Test]
    public function syncJobSerialisesOnlyTheUserId(): void
    {
        $s = serialize(new DiscordRoleSyncJob(42));
        $this->assertStringContainsString('user_id', $s);
        $this->assertStringNotContainsString('tries', $s);
        $j = unserialize($s);
        $this->assertSame(42, $j->userId);
        $this->assertSame('discord-sync', $j->queue);
        // gemanipuleerde payload: onzin wordt een veilig getal
        $evil = 'O:' . strlen(DiscordRoleSyncJob::class) . ':"' . DiscordRoleSyncJob::class . '":1:{s:7:"user_id";s:5:"-5abc";}';
        $this->assertSame(0, unserialize($evil)->userId);
    }
}
