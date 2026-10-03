<?php

// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Tests;

require_once __DIR__ . '/Support/bootstrap.php';

use CommunityFusion\Modules\AiStudio\Http\CurlTransport;
use CommunityFusion\Modules\AiStudio\Http\HttpRequest;
use CommunityFusion\Modules\AiStudio\Http\TransportException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test alleen de opgebouwde cURL-opties; er wordt nooit een verbinding gemaakt.
 */
final class CurlTransportTest extends TestCase
{
    #[Test]
    public function testHardenedDefaults(): void
    {
        $o = (new CurlTransport())->buildOptions(new HttpRequest('POST', 'https://api.openai.com/v1/x', ['Authorization' => 'Bearer k'], '{}'));

        self::assertFalse($o[CURLOPT_FOLLOWLOCATION], 'Geen redirects volgen.');
        self::assertSame(CURLPROTO_HTTPS, $o[CURLOPT_PROTOCOLS]);
        self::assertSame(0, $o[CURLOPT_REDIR_PROTOCOLS]);
        self::assertTrue($o[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $o[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(['Authorization: Bearer k'], $o[CURLOPT_HTTPHEADER]);
        self::assertArrayNotHasKey(CURLOPT_RESOLVE, $o);
    }

    #[Test]
    public function testHttpOnlyWhenExplicitlyAllowed(): void
    {
        $t = new CurlTransport();
        $o = $t->buildOptions(new HttpRequest('GET', 'http://localhost:11434/api/tags', [], null, 10, true, '127.0.0.1'));
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $o[CURLOPT_PROTOCOLS]);
        self::assertSame(['localhost:11434:127.0.0.1'], $o[CURLOPT_RESOLVE], 'Vastgepind op het gecontroleerde IP.');

        $this->expectException(TransportException::class);
        $t->buildOptions(new HttpRequest('GET', 'http://localhost:11434/api/tags'));
    }

    #[Test]
    public function testIpv6PinIsBracketed(): void
    {
        $o = (new CurlTransport())->buildOptions(new HttpRequest('GET', 'http://[::1]:11434/', [], null, 10, true, '::1'));
        self::assertSame(['[::1]:11434:[::1]'], $o[CURLOPT_RESOLVE]);
    }

    #[Test]
    public function testRejectsOtherSchemesAndHeaderInjection(): void
    {
        $t = new CurlTransport();
        foreach (['file:///etc/passwd', 'gopher://x', 'ftp://x/y', 'javascript:1', '//x', ''] as $url) {
            try {
                $t->buildOptions(new HttpRequest('GET', $url, [], null, 10, true));
                self::fail('Had geweigerd moeten worden: ' . $url);
            } catch (TransportException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(TransportException::class);
        $t->buildOptions(new HttpRequest('GET', 'https://a.nl', ['X-Test' => "a\r\nX-Evil: 1"]));
    }
}
