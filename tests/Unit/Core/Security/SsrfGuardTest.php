<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Security;

use CommunityFusion\Core\Security\SsrfGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SsrfGuardTest extends TestCase
{
    private function guard(array $ips = ['93.184.216.34']): SsrfGuard
    {
        return new SsrfGuard(fn (string $host): array => $ips);
    }

    private function assertBlocked(SsrfGuard $g, string $url): void
    {
        try {
            $g->assertPublicHttps($url);
            $this->fail("Had geweigerd moeten worden: {$url}");
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function allowsPublicHttps(): void
    {
        $r = $this->guard()->assertPublicHttps('https://example.com/pkg.zip');
        $this->assertSame('example.com', $r['host']);
        $this->assertSame(['93.184.216.34'], $r['ips']);
    }

    #[Test]
    public function blocksWrongSchemePortAndCredentials(): void
    {
        foreach (['http://example.com/a.zip', 'ftp://example.com/a', 'file:///etc/passwd', 'gopher://example.com',
                  'https://example.com:8443/a.zip', 'https://user:pw@example.com/a.zip', 'https:///a', 'javascript:alert(1)',
                  "https://example.com/a b"] as $url) {
            $this->assertBlocked($this->guard(), $url);
        }
    }

    #[Test]
    public function blocksLocalAndPrivateTargets(): void
    {
        foreach (['https://localhost/a', 'https://foo.localhost/a', 'https://db.internal/a', 'https://127.0.0.1/a',
                  'https://10.0.0.5/a', 'https://192.168.1.1/a', 'https://172.16.0.1/a', 'https://169.254.169.254/latest/meta-data',
                  'https://100.64.0.1/a', 'https://[::1]/a', 'https://[fc00::1]/a', 'https://[fe80::1]/a',
                  'https://[::ffff:10.0.0.1]/a', 'https://0.0.0.0/a'] as $url) {
            $this->assertBlocked($this->guard(), $url);
        }
    }

    #[Test]
    public function blocksHostnamesThatResolveToPrivateAddresses(): void
    {
        $this->assertBlocked($this->guard(['10.0.0.1']), 'https://evil.example/a.zip');
        $this->assertBlocked($this->guard(['93.184.216.34', '127.0.0.1']), 'https://mixed.example/a.zip');
        $this->assertBlocked($this->guard([]), 'https://nxdomain.example/a.zip');
    }

    #[Test]
    public function isPublicIpClassifiesCommonRanges(): void
    {
        $this->assertTrue(SsrfGuard::isPublicIp('8.8.8.8'));
        $this->assertTrue(SsrfGuard::isPublicIp('2606:4700:4700::1111'));
        $this->assertFalse(SsrfGuard::isPublicIp('127.0.0.1'));
        $this->assertFalse(SsrfGuard::isPublicIp('not-an-ip'));
    }
}
