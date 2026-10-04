<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core;

use CommunityFusion\Api\Middleware\RateLimitMiddleware;
use CommunityFusion\Blocks\Types\AdBlock;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Cache\FileCache;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Modules\Gallery\GalleryThumbnailer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Batch-3 hardening: AdBlock-URL's, route-gebonden rate limit, atomaire cache, pixelgrens. */
final class SecurityHardeningTest extends TestCase
{
    #[Test]
    public function adBlockDropsDangerousUrlSchemes(): void
    {
        $html = (new AdBlock())->render(['image_url' => 'javascript:alert(1)', 'link_url' => 'javascript:alert(1)']);
        $this->assertStringContainsString('geen afbeelding', $html);

        $html = (new AdBlock())->render(['image_url' => 'https://cdn.example/x.png', 'link_url' => 'data:text/html,<script>']);
        $this->assertStringContainsString('src="https://cdn.example/x.png"', $html);
        $this->assertStringContainsString('href="#"', $html);
        $this->assertStringNotContainsString('data:', $html);

        $html = (new AdBlock())->render(['image_url' => '/media/ads/a.png', 'link_url' => '//evil.example']);
        $this->assertStringContainsString('src="/media/ads/a.png"', $html);
        $this->assertStringContainsString('href="#"', $html);
    }

    #[Test]
    public function rateLimitCountsPerRoute(): void
    {
        $cache = new CacheManager(['driver' => 'file', 'path' => sys_get_temp_dir() . '/cf_rl_' . bin2hex(random_bytes(4))]);
        $mw    = new RateLimitMiddleware($cache, 1, 60);
        $ok    = static fn(): Response => Response::json(['ok' => true]);
        $req   = static fn(string $path): Request => new Request('GET', $path, [], [], [], [], ['REMOTE_ADDR' => '203.0.113.5'], []);

        $this->assertSame(200, $mw->handle($req('/api/v1/a'), $ok)->getStatus());
        $this->assertSame(200, $mw->handle($req('/api/v1/b'), $ok)->getStatus());   // andere route: eigen teller
        $this->assertSame(429, $mw->handle($req('/api/v1/a'), $ok)->getStatus());
    }

    #[Test]
    public function fileCacheWritesAtomicallyAndLeavesNoTempFiles(): void
    {
        $dir = sys_get_temp_dir() . '/cf_fc_' . bin2hex(random_bytes(4));
        $fc  = new FileCache($dir);
        $this->assertTrue($fc->set('k', ['a' => 1], 60));
        $this->assertSame(['a' => 1], $fc->get('k'));
        $this->assertSame([], glob($dir . '/*.tmp') ?: []);
        $this->assertTrue($fc->delete('k'));
        $this->assertTrue($fc->delete('k'));   // al weg → nog steeds true
        $this->assertNull($fc->get('k'));
    }

    #[Test]
    public function thumbnailerSkipsHugeImagesEvenWithUnlimitedMemory(): void
    {
        $old = ini_set('memory_limit', '-1');
        $m = new \ReflectionMethod(GalleryThumbnailer::class, 'fitsInMemory');
        $t = new GalleryThumbnailer(480, true);
        $this->assertFalse($m->invoke($t, 9000, 9000));
        $this->assertTrue($m->invoke($t, 800, 600));
        ini_set('memory_limit', (string) $old);
    }

    #[Test]
    public function languageSwitchNeverRedirectsToAnotherHost(): void
    {
        $ctl = (new \ReflectionClass(\CommunityFusion\Modules\I18n\LanguageController::class))->newInstanceWithoutConstructor();
        $m   = new \ReflectionMethod($ctl, 'safeRedirectTarget');
        $req = static fn(string $ref): Request => new Request('GET', '/taal/en', [], [], ['Referer' => $ref], [], [], []);

        $this->assertSame('/forum?x=1', $m->invoke($ctl, $req('https://site.nl/forum?x=1')));
        $this->assertSame('/', $m->invoke($ctl, $req('https://site.nl//evil.example/pad')));
        $this->assertSame('/', $m->invoke($ctl, $req('https://site.nl/\\evil.example')));
        $this->assertSame('/', $m->invoke($ctl, $req('')));
    }
}

