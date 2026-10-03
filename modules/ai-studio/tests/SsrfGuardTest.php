<?php

// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Tests;

require_once __DIR__ . '/Support/bootstrap.php';

use CommunityFusion\Modules\AiStudio\Http\SsrfException;
use CommunityFusion\Modules\AiStudio\Http\SsrfGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SsrfGuardTest extends TestCase
{
    /** @var array<string, list<string>> */
    private array $dns = [];

    private function guard(): SsrfGuard
    {
        return new SsrfGuard(fn (string $host): array => $this->dns[$host] ?? []);
    }

    /**
     * @return list<string>
     */
    private static function blockedUrls(): array
    {
        return [
            'file:///etc/passwd',
            'gopher://127.0.0.1:11434/_x',
            'ftp://example.nl/x',
            'javascript:alert(1)',
            'http://169.254.169.254/latest/meta-data/',
            'http://169.254.0.1/',
            'http://169.254.169.254:80',
            'https://[fe80::1]/',
            'http://[::ffff:169.254.169.254]/',
            'http://[::ffff:a9fe:a9fe]/',
            'http://[fd00:ec2::254]/',
            'http://100.100.100.200/',
            'http://0.0.0.0/',
            'http://224.0.0.1/',
            'http://255.255.255.255/',
            'http://metadata.google.internal/computeMetadata/v1/',
            'http://METADATA.GOOGLE.INTERNAL./',
            'http://user:pass@localhost:11434/',
            'http://2852039166/',          // decimaal 169.254.169.254
            'http://0xa9fea9fe/',          // hex
            'http://0251.0376.0251.0376/', // octaal
            'http://169.254.169.254.nip.example/', // resolver-gestuurd, zie dns hieronder
            'http://',
            '',
            "http://localhost:11434/\r\nHost: evil",
        ];
    }

    #[Test]
    public function testBlocksNonHttpAndMetadataTargets(): void
    {
        $this->dns['169.254.169.254.nip.example'] = ['169.254.169.254'];
        foreach (self::blockedUrls() as $url) {
            try {
                $this->guard()->check($url);
                self::fail('Had geblokkeerd moeten worden: ' . $url);
            } catch (SsrfException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testAllowsLocalAndPrivateOllamaTargets(): void
    {
        $this->dns['ai.example.nl'] = ['93.184.216.34'];
        $this->dns['nas.lan'] = ['192.168.1.20'];

        $ok = [
            'http://localhost:11434' => '127.0.0.1',
            'http://127.0.0.1:11434/' => '127.0.0.1',
            'http://192.168.1.20:3000' => '192.168.1.20',
            'http://[::1]:11434' => '::1',
            'https://ai.example.nl/api' => '93.184.216.34',
            'http://nas.lan:3000/x' => '192.168.1.20',
        ];
        $this->dns['localhost'] = ['127.0.0.1'];

        foreach ($ok as $url => $ip) {
            $target = $this->guard()->check($url);
            self::assertSame($ip, $target['ip'], $url);
        }
    }

    #[Test]
    public function testReturnsPortAndHost(): void
    {
        $this->dns['ai.example.nl'] = ['93.184.216.34'];
        $t = $this->guard()->check('https://AI.example.nl/v1');
        self::assertSame(['url' => 'https://AI.example.nl/v1', 'host' => 'ai.example.nl', 'port' => 443, 'ip' => '93.184.216.34'], $t);
        self::assertSame(11434, $this->guard()->check('http://127.0.0.1:11434')['port']);
    }

    #[Test]
    public function testBlocksHostnameWhenAnyResolvedIpIsBlocked(): void
    {
        // DNS-rebinding-achtig: één goed en één slecht adres = geweigerd.
        $this->dns['mixed.example'] = ['93.184.216.34', '169.254.169.254'];

        $this->expectException(SsrfException::class);
        $this->guard()->check('http://mixed.example/');
    }

    #[Test]
    public function testUnresolvableHostIsRejected(): void
    {
        $this->expectException(SsrfException::class);
        $this->guard()->check('http://doesnotexist.example/');
    }

    #[Test]
    public function testIsBlockedIp(): void
    {
        $g = $this->guard();
        foreach (['169.254.169.254', '169.254.1.1', '0.0.0.1', '224.0.0.5', '240.0.0.1', 'fe80::1', 'febf::1', 'ff02::1', '::ffff:169.254.169.254', 'not-an-ip'] as $ip) {
            self::assertTrue($g->isBlockedIp($ip), $ip);
        }
        foreach (['127.0.0.1', '10.0.0.5', '172.16.0.1', '192.168.0.1', '8.8.8.8', '::1', 'fd12::1', '2001:db8::1', '::ffff:127.0.0.1'] as $ip) {
            self::assertFalse($g->isBlockedIp($ip), $ip);
        }
    }
}
