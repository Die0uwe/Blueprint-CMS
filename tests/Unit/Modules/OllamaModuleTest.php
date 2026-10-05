<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Modules;

use CommunityFusion\Modules\Ollama\OllamaChatBlock;
use CommunityFusion\Modules\Ollama\OllamaClient;
use CommunityFusion\Modules\Ollama\OllamaConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OllamaModuleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        spl_autoload_register(static function (string $c): void {
            $pre = 'CommunityFusion\\Modules\\Ollama\\';
            if (str_starts_with($c, $pre)) {
                $f = dirname(__DIR__, 3) . '/modules/ollama/src/' . substr($c, strlen($pre)) . '.php';
                if (is_file($f)) { require_once $f; }
            }
        });
    }

    #[Test]
    public function hintsExplainTheCommonFailuresInPlainLanguage(): void
    {
        $this->assertStringContainsString('Cloudflare Tunnel', OllamaClient::hintForStatus(0));
        $this->assertStringContainsString('API-sleutel', OllamaClient::hintForStatus(401, '', true));
        $this->assertStringContainsString('SSL', OllamaClient::hintForStatus(0, 'SSL certificate problem'));
        $this->assertStringContainsString('100 s', OllamaClient::hintForStatus(524));
        $this->assertStringContainsString('Docker', OllamaClient::hintForStatus(502));
        $this->assertStringContainsString('404', OllamaClient::hintForStatus(404));
    }

    #[Test]
    public function clientSettingsAreClampedAndValidated(): void
    {
        $c = OllamaConfig::client(['open_webui_url' => 'https://ai.example.nl', 'timeout' => '9999', 'num_ctx' => '-5', 'keep_alive' => '24h; rm -rf']);
        $this->assertTrue($c->usesOpenWebUi());
        $c2 = OllamaConfig::client([]);
        $this->assertFalse($c2->usesOpenWebUi());
        $this->assertContains('deepseek-r1:8b', OllamaConfig::DEEPSEEK_SUGGESTIONS);
    }

    #[Test]
    public function chatBlockRendersWithoutNetworkAndEscapesInput(): void
    {
        $b = new OllamaChatBlock(new OllamaClient('http://127.0.0.1:1'));
        $h = $b->render(['title' => '<b>Hoi</b>', 'welcome' => '"x"', 'suggestions' => "Eén\nTwee\nDrie\nVier\nVijf", 'max_height' => 99999]);
        $this->assertStringContainsString('cf-aichat', $h);
        $this->assertStringContainsString('&lt;b&gt;Hoi&lt;/b&gt;', $h);
        $this->assertStringContainsString('data-welcome="&quot;x&quot;"', $h);
        $this->assertSame(4, substr_count($h, 'cf-aichat-chip"'));
        $this->assertStringContainsString('max-height:800px', $h);
        $this->assertSame('ollama-chat', $b->getSlug());
        $this->assertSame(['Eén', 'Twee'], OllamaChatBlock::parseSuggestions("Eén\n\n  Twee  \n"));
    }
}
