<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Modules;

use CommunityFusion\Modules\Ollama\OllamaConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cloudflare Access (1.36.1): het service token gaat mee naar de eigen tunnel (Ollama en Open WebUI),
 * maar nooit naar de reserve-AI in de cloud.
 */
final class OllamaCloudflareAccessTest extends TestCase
{
    /** @return list<string> */
    private static function call(object $client, string $method): array
    {
        $m = new \ReflectionMethod($client, $method);
        return $m->invoke($client);
    }

    #[Test]
    public function testTokenGoesToOwnTunnelHeaders(): void
    {
        $c = OllamaConfig::client(['host' => 'https://ollama.example.nl', 'open_webui_url' => 'https://ai.example.nl', 'open_webui_key' => 'sk-1',
            'cf_access_id' => 'abc.access', 'cf_access_secret' => 'geheim123']);
        self::assertSame(['CF-Access-Client-Id: abc.access', 'CF-Access-Client-Secret: geheim123'], self::call($c, 'accessHeaders'));
        self::assertSame(['Authorization: Bearer sk-1', 'CF-Access-Client-Id: abc.access', 'CF-Access-Client-Secret: geheim123'], self::call($c, 'webUiHeaders'));
    }

    #[Test]
    public function testNoHeadersWithoutCompleteToken(): void
    {
        foreach ([['cf_access_id' => 'abc.access'], ['cf_access_secret' => 'geheim123'], []] as $part) {
            $c = OllamaConfig::client(['host' => 'https://ollama.example.nl'] + $part);
            self::assertSame([], self::call($c, 'accessHeaders'));
        }
    }

    #[Test]
    public function testFallbackNeverGetsTheToken(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/modules/ollama/src/OllamaClient.php');
        $start = strpos($src, 'private function chatFallback');
        $end = strpos($src, 'private function chatDirect');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        self::assertStringNotContainsString('accessHeaders', substr($src, $start, $end - $start));
    }
}
