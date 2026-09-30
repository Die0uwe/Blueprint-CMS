<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Discord;

use CommunityFusion\Modules\Discord\DiscordTransportException;
use CommunityFusion\Modules\Discord\DiscordWebhook;
use CommunityFusion\Tests\Support\FakeDiscordTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Support/FakeDiscordTransport.php';

final class DiscordWebhookTest extends TestCase
{
    private const ID  = '123456789012345678';
    private const TOK = 'abcDEF_123-xyz.QRS';

    private function url(string $host = 'discord.com'): string
    {
        return 'https://' . $host . '/api/webhooks/' . self::ID . '/' . self::TOK;
    }

    #[Test]
    public function goodUrlsAreAcceptedAndRebuilt(): void
    {
        $this->assertSame($this->url(), DiscordWebhook::normalize($this->url()));
        $this->assertSame($this->url('discordapp.com'), DiscordWebhook::normalize($this->url('discordapp.com')));
        $this->assertSame($this->url(), DiscordWebhook::normalize("  " . $this->url() . "\n"), 'spaties worden genegeerd');
        $this->assertSame($this->url(), DiscordWebhook::normalize($this->url('DISCORD.COM')), 'host wordt naar kleine letters herbouwd');
        $this->assertSame($this->url() . '?thread_id=223456789012345678', DiscordWebhook::normalize($this->url() . '?thread_id=223456789012345678'));
    }

    /** @return array<string,string> */
    private static function evil(): array
    {
        $p = '/api/webhooks/' . self::ID . '/' . self::TOK;
        return [
            'http'                  => 'http://discord.com' . $p,
            'andere host'           => 'https://evil.com' . $p,
            'host als subdomein'    => 'https://discord.com.evil.com' . $p,
            'discord als prefix'    => 'https://evildiscord.com' . $p,
            'subdomein'             => 'https://ptb.discord.com' . $p,
            'user-info trucje'      => 'https://discord.com@evil.com' . $p,
            'user-info'             => 'https://user:pw@discord.com' . $p,
            'backslash-trucje'      => 'https://evil.com\\@discord.com' . $p,
            'poort'                 => 'https://discord.com:8443' . $p,
            'poort 443'             => 'https://discord.com:443' . $p,
            'redirect-pad'          => 'https://discord.com/redirect?to=https://evil.com',
            'pad-traversal'         => 'https://discord.com/api/webhooks/' . self::ID . '/..',
            'pad-traversal 2'       => 'https://discord.com/api/webhooks/../../x/' . self::TOK,
            'geen snowflake'        => 'https://discord.com/api/webhooks/abc/' . self::TOK,
            'te kort id'            => 'https://discord.com/api/webhooks/1234567890123456/' . self::TOK,
            'te lang id'            => 'https://discord.com/api/webhooks/123456789012345678901/' . self::TOK,
            'extra pad'             => 'https://discord.com' . $p . '/extra',
            'slash op het eind'     => 'https://discord.com' . $p . '/',
            'willekeurige query'    => 'https://discord.com' . $p . '?url=https://evil.com',
            'thread_id niet numeriek' => 'https://discord.com' . $p . '?thread_id=abc',
            'thread_id + extra'     => 'https://discord.com' . $p . '?thread_id=223456789012345678&x=1',
            'fragment'              => 'https://discord.com' . $p . '#x',
            'spatie in url'         => 'https://discord.com/api/webhooks/' . self::ID . '/tok en',
            'newline-injectie'      => "https://discord.com" . $p . "\nHost: evil.com",
            'ip-adres'              => 'https://127.0.0.1' . $p,
            'localhost'             => 'https://localhost' . $p,
            'lege string'           => '',
            'alleen schema'         => 'https://',
            'ftp'                   => 'ftp://discord.com' . $p,
            'protocol-relatief'     => '//discord.com' . $p,
            'hoofdletter schema'    => 'HTTPS://discord.com' . $p,
            'tokenloos'             => 'https://discord.com/api/webhooks/' . self::ID . '/',
            'te lange url'          => 'https://discord.com/api/webhooks/' . self::ID . '/' . str_repeat('a', 500),
        ];
    }

    #[Test]
    public function maliciousUrlsAreAllRejected(): void
    {
        foreach (self::evil() as $label => $u) {
            $this->assertNull(DiscordWebhook::normalize($u), "moet geweigerd worden: {$label}");
            $this->assertFalse(DiscordWebhook::isValid($u), $label);
        }
    }

    #[Test]
    public function invalidUrlNeverReachesTheTransport(): void
    {
        $t = new FakeDiscordTransport();
        $hook = new DiscordWebhook($t);
        foreach (self::evil() as $u) {
            $r = $hook->send($u, DiscordWebhook::buildPayload('hoi'));
            $this->assertFalse($r['ok']);
        }
        $this->assertCount(0, $t->requests);
    }

    #[Test]
    public function maskNeverShowsTheUrl(): void
    {
        $m = DiscordWebhook::mask($this->url());
        $this->assertStringContainsString('ingesteld', $m);
        $this->assertStringContainsString('.QRS', $m);
        $this->assertStringNotContainsString(self::ID, $m);
        $this->assertStringNotContainsString('abcDEF', $m);
        $this->assertSame('ongeldig', DiscordWebhook::mask('https://evil.com/x'));
    }

    #[Test]
    public function payloadLimitsAndNoMentions(): void
    {
        $p = DiscordWebhook::buildPayload(str_repeat('é', 2500), [[
            'title' => str_repeat('t', 400), 'description' => str_repeat('d', 5000), 'url' => 'https://example.test/news/x', 'color' => 0x6C3DF4,
        ]]);
        $this->assertSame(2000, mb_strlen($p['content']));
        $this->assertSame(256, mb_strlen($p['embeds'][0]['title']));
        $this->assertSame(4096, mb_strlen($p['embeds'][0]['description']));
        $this->assertStringEndsWith('…', $p['embeds'][0]['title']);
        $this->assertSame(['parse' => []], $p['allowed_mentions']);
        $this->assertStringContainsString('"allowed_mentions":{"parse":[]}', json_encode($p));
    }

    #[Test]
    public function shortTextIsNotCutAndBadEmbedUrlsAreDropped(): void
    {
        $p = DiscordWebhook::buildPayload('', [['title' => 'Kort', 'url' => 'javascript:alert(1)'], ['title' => 'Ok', 'url' => 'https://a.test/x']]);
        $this->assertArrayNotHasKey('content', $p);
        $this->assertSame('Kort', $p['embeds'][0]['title']);
        $this->assertArrayNotHasKey('url', $p['embeds'][0]);
        $this->assertSame('https://a.test/x', $p['embeds'][1]['url']);
    }

    #[Test]
    public function sendPostsJsonToTheRebuiltUrlWithoutMentions(): void
    {
        $t = new FakeDiscordTransport();
        $t->queue(204, '');
        $r = (new DiscordWebhook($t))->send($this->url('DISCORD.com'), ['content' => '@everyone hoi', 'allowed_mentions' => ['parse' => ['everyone']]]);
        $this->assertTrue($r['ok']);
        $req = $t->last();
        $this->assertSame('POST', $req['method']);
        $this->assertSame($this->url(), $req['url']);
        $this->assertSame(['parse' => []], $t->lastJson()['allowed_mentions'], 'aanroeper kan pings niet aanzetten');
        $this->assertTrue($t->hasHeader(0, 'Content-Type: application/json'));
    }

    #[Test]
    public function statusCodesGetDutchMessagesWithoutLeakingTheUrl(): void
    {
        $cases = [
            [200, '', true, ''],
            [204, '', true, ''],
            [404, ['message' => 'Unknown Webhook', 'code' => 10015], false, 'bestaat niet meer'],
            [401, ['message' => 'Invalid Webhook Token'], false, 'Invalid Webhook Token'],
            [400, ['message' => 'Cannot send an empty message', 'code' => 50006], false, 'Cannot send an empty message'],
            [429, ['message' => 'You are being rate limited.', 'retry_after' => 2.3], false, '3 seconde'],
            [500, '', false, 'storing'],
            [302, '', false, 'omleiding'],
        ];
        foreach ($cases as [$status, $body, $ok, $needle]) {
            $t = new FakeDiscordTransport();
            $t->queue($status, $body);
            $r = (new DiscordWebhook($t))->send($this->url(), DiscordWebhook::buildPayload('x'));
            $this->assertSame($ok, $r['ok'], "status {$status}");
            $this->assertStringContainsString($needle, $r['message'], "status {$status}");
            $this->assertStringNotContainsString(self::TOK, $r['message']);
            $this->assertStringNotContainsString(self::ID, $r['message']);
        }
    }

    #[Test]
    public function rateLimitExposesRetryAfterAndHeaderIsAFallback(): void
    {
        $t = new FakeDiscordTransport();
        $t->queue(429, ['message' => 'rate'], ['retry-after' => '7']);
        $r = (new DiscordWebhook($t))->send($this->url(), DiscordWebhook::buildPayload('x'));
        $this->assertSame(7.0, $r['retry_after']);
        $this->assertStringContainsString('7 seconde', $r['message']);
    }

    #[Test]
    public function aFailingTransportIsReportedNotThrown(): void
    {
        $t = new FakeDiscordTransport();
        $t->queueException(new DiscordTransportException('Discord is niet bereikbaar (verbinding mislukt of time-out).'));
        $r = (new DiscordWebhook($t))->send($this->url(), DiscordWebhook::buildPayload('x'));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('niet bereikbaar', $r['message']);
    }

    #[Test]
    public function emptyMessageIsNotSent(): void
    {
        $t = new FakeDiscordTransport();
        $r = (new DiscordWebhook($t))->send($this->url(), DiscordWebhook::buildPayload('   '));
        $this->assertFalse($r['ok']);
        $this->assertCount(0, $t->requests);
    }
}
