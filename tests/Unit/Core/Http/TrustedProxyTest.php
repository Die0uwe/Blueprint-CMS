<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Http;

use CommunityFusion\Core\Http\TrustedProxy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TrustedProxyTest extends TestCase
{
    #[Test]
    public function cloudflareConnectionGetsRealIpAndHttps(): void
    {
        $s = ['REMOTE_ADDR' => '172.67.10.5', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9', 'HTTP_CF_VISITOR' => '{"scheme":"https"}'];
        TrustedProxy::apply($s);
        $this->assertSame('203.0.113.9', $s['REMOTE_ADDR']);
        $this->assertSame('172.67.10.5', $s['PROXY_REMOTE_ADDR']);
        $this->assertSame('on', $s['HTTPS']);
    }

    #[Test]
    public function ipv6CloudflareAndVisitorWork(): void
    {
        $s = ['REMOTE_ADDR' => '2606:4700::1111', 'HTTP_CF_CONNECTING_IP' => '2001:db8::7', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        TrustedProxy::apply($s);
        $this->assertSame('2001:db8::7', $s['REMOTE_ADDR']);
        $this->assertTrue(TrustedProxy::isHttps($s));
    }

    #[Test]
    public function untrustedClientCannotSpoofHeaders(): void
    {
        $s = ['REMOTE_ADDR' => '198.51.100.20', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4', 'HTTP_X_FORWARDED_FOR' => '5.6.7.8', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        TrustedProxy::apply($s);
        $this->assertSame('198.51.100.20', $s['REMOTE_ADDR']);
        $this->assertFalse(isset($s['HTTPS']));
    }

    #[Test]
    public function cfHeaderIgnoredWhenNotFromCloudflare(): void
    {
        // Loopback-proxy (bv. nginx) mag geen CF-Connecting-IP laten gelden, wel X-Forwarded-For.
        $s = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4', 'HTTP_X_FORWARDED_FOR' => '203.0.113.50'];
        TrustedProxy::apply($s);
        $this->assertSame('203.0.113.50', $s['REMOTE_ADDR']);
    }

    #[Test]
    public function forwardedForChainTakesFirstUntrustedFromTheRight(): void
    {
        $s = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 203.0.113.77, 10.0.0.9'];
        TrustedProxy::apply($s, ['10.0.0.0/24']);
        $this->assertSame('203.0.113.77', $s['REMOTE_ADDR']);
    }

    #[Test]
    public function garbageHeaderKeepsRemoteAddr(): void
    {
        $s = ['REMOTE_ADDR' => '172.67.10.5', 'HTTP_CF_CONNECTING_IP' => 'niet-een-ip'];
        TrustedProxy::apply($s);
        $this->assertSame('172.67.10.5', $s['REMOTE_ADDR']);
    }

    #[Test]
    public function httpsOffIsNotHttps(): void
    {
        $this->assertFalse(TrustedProxy::isHttps(['HTTPS' => 'off']));
        $this->assertFalse(TrustedProxy::isHttps([]));
        $this->assertTrue(TrustedProxy::isHttps(['HTTPS' => 'on']));
    }

    #[Test]
    public function appUrlUpgradedOnlyForSameHostOverHttps(): void
    {
        $https = ['HTTPS' => 'on', 'HTTP_HOST' => 'site.nl'];
        $this->assertSame('https://site.nl', TrustedProxy::upgradeUrl('http://site.nl', $https));
        $this->assertSame('http://andere.nl', TrustedProxy::upgradeUrl('http://andere.nl', $https));
        $this->assertSame('http://site.nl', TrustedProxy::upgradeUrl('http://site.nl', ['HTTP_HOST' => 'site.nl']));
    }

    #[Test]
    public function cidrBoundaries(): void
    {
        $this->assertTrue(TrustedProxy::inRanges('104.23.255.255', ['104.16.0.0/13']));
        $this->assertFalse(TrustedProxy::inRanges('104.24.0.1', ['104.16.0.0/13']));
        $this->assertTrue(TrustedProxy::inRanges('1.2.3.4', ['1.2.3.4']));
        $this->assertFalse(TrustedProxy::inRanges('1.2.3.4', ['2606:4700::/32']));
    }
}
