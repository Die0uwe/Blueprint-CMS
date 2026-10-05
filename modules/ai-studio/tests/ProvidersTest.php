<?php

// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Tests;

require_once __DIR__ . '/Support/bootstrap.php';

use CommunityFusion\Modules\AiStudio\Http\HttpResponse;
use CommunityFusion\Modules\AiStudio\Http\SsrfGuard;
use CommunityFusion\Modules\AiStudio\Http\TransportException;
use CommunityFusion\Modules\AiStudio\Provider\AnthropicProvider;
use CommunityFusion\Modules\AiStudio\Provider\DeepSeekProvider;
use CommunityFusion\Modules\AiStudio\Provider\GoogleProvider;
use CommunityFusion\Modules\AiStudio\Provider\MistralProvider;
use CommunityFusion\Modules\AiStudio\Provider\OllamaProvider;
use CommunityFusion\Modules\AiStudio\Provider\OpenAiProvider;
use CommunityFusion\Modules\AiStudio\Provider\ProviderException;
use CommunityFusion\Modules\AiStudio\Provider\ProviderInterface;
use CommunityFusion\Modules\AiStudio\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Alle provider-streams lopen over een nep-transport: nooit echt netwerk.
 */
final class ProvidersTest extends TestCase
{
    private const KEY = 'test-key-1234567890';

    /** @var list<array{role: string, content: string}> */
    private array $msgs = [
        ['role' => 'system', 'content' => 'Wees kort.'],
        ['role' => 'user', 'content' => 'Hallo'],
        ['role' => 'assistant', 'content' => 'Hoi'],
        ['role' => 'user', 'content' => 'Nog een vraag'],
    ];

    /**
     * @return list<string>
     */
    private function collect(ProviderInterface $p, array $options = []): array
    {
        $out = [];
        foreach ($p->stream($this->msgs, $options) as $t) {
            $out[] = $t;
        }
        return $out;
    }

    /**
     * @param list<string> $deltas
     * @return list<string> SSE-brokken in OpenAI-formaat, bewust midden door events geknipt
     */
    private static function openAiChunks(array $deltas): array
    {
        $raw = '';
        foreach ($deltas as $d) {
            $raw .= 'data: ' . json_encode(['choices' => [['delta' => ['content' => $d]]]]) . "\n\n";
        }
        $raw .= "data: [DONE]\n\n";
        return str_split($raw, 17);
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(FakeTransport $t): array
    {
        $body = $t->requests[0]->body;
        self::assertNotNull($body);
        $json = json_decode($body, true);
        self::assertIsArray($json);
        return $json;
    }

    #[Test]
    public function testOpenAiStreamsDeltasAndSendsBearerKey(): void
    {
        $t = FakeTransport::withChunks(self::openAiChunks(['Hel', 'lo ', 'wereld']));
        $p = new OpenAiProvider($t, self::KEY, 'gpt-4o-mini');

        self::assertSame(['Hel', 'lo ', 'wereld'], $this->collect($p));

        $r = $t->requests[0];
        self::assertSame('POST', $r->method);
        self::assertSame('https://api.openai.com/v1/chat/completions', $r->url);
        self::assertSame('Bearer ' . self::KEY, $r->headers['Authorization']);
        self::assertFalse($r->allowHttp, 'Gehoste providers: alleen https.');
        self::assertNull($r->pinnedIp);
        $body = self::body($t);
        self::assertSame('gpt-4o-mini', $body['model']);
        self::assertTrue($body['stream']);
        self::assertCount(4, $body['messages']);
    }

    #[Test]
    public function testOpenAiCompatibleSiblingsUseTheirOwnEndpoints(): void
    {
        $cases = [
            [DeepSeekProvider::class, 'https://api.deepseek.com/chat/completions', 'deepseek'],
            [MistralProvider::class, 'https://api.mistral.ai/v1/chat/completions', 'mistral'],
        ];
        foreach ($cases as [$class, $url, $slug]) {
            $t = FakeTransport::withChunks(self::openAiChunks(['ok']));
            $p = new $class($t, self::KEY, 'm');
            self::assertSame(['ok'], $this->collect($p));
            self::assertSame($url, $t->requests[0]->url);
            self::assertSame($slug, $p->slug());
        }
    }

    #[Test]
    public function testMaxTokensParameterNameDiffersPerProvider(): void
    {
        $t = FakeTransport::withChunks(self::openAiChunks(['x']));
        $this->collect(new OpenAiProvider($t, self::KEY, 'm'), ['max_tokens' => 100]);
        self::assertArrayHasKey('max_completion_tokens', self::body($t));

        $t = FakeTransport::withChunks(self::openAiChunks(['x']));
        $this->collect(new MistralProvider($t, self::KEY, 'm'), ['max_tokens' => 100]);
        self::assertArrayHasKey('max_tokens', self::body($t));
    }

    #[Test]
    public function testStreamErrorEventBecomesSafeProviderException(): void
    {
        $t = FakeTransport::withChunks(['data: ' . json_encode(['error' => ['message' => 'Incorrect API key: ' . self::KEY]]) . "\n\n"]);
        $p = new OpenAiProvider($t, self::KEY, 'm');

        try {
            $this->collect($p);
            self::fail('ProviderException verwacht');
        } catch (ProviderException $e) {
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    #[Test]
    public function testHttpErrorIsMappedWithoutLeakingBodyOrKey(): void
    {
        $t = new FakeTransport();
        $t->streamError = new TransportException('HTTP 401', 401, '{"error":"bad key ' . self::KEY . '"}');
        $p = new OpenAiProvider($t, self::KEY, 'm');

        try {
            $this->collect($p);
            self::fail('ProviderException verwacht');
        } catch (ProviderException $e) {
            self::assertSame('OpenAI: HTTP 401 (key geweigerd)', $e->getMessage());
        }
    }

    #[Test]
    public function testMissingKeyAndBadModelAreRejectedBeforeAnyRequest(): void
    {
        $t = new FakeTransport();
        try {
            (new OpenAiProvider($t, '', 'm'))->stream($this->msgs, []);
            self::fail('Geen key: ProviderException verwacht');
        } catch (ProviderException) {
            self::addToAssertionCount(1);
        }
        foreach (['../../etc/passwd', 'a b', "m\nx", '', str_repeat('a', 200), 'ok/..'] as $bad) {
            try {
                (new OpenAiProvider($t, self::KEY, ''))->stream($this->msgs, ['model' => $bad]);
                self::fail('Ongeldig model had geweigerd moeten worden: ' . $bad);
            } catch (ProviderException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $t->requests);
    }

    #[Test]
    public function testAnthropicStreamsTextDeltasAndMapsSystemPrompt(): void
    {
        $events = [
            ['message_start', ['type' => 'message_start']],
            ['content_block_start', ['type' => 'content_block_start']],
            ['content_block_delta', ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Goe']]],
            ['ping', ['type' => 'ping']],
            ['content_block_delta', ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'ie dag']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']]],
            ['message_stop', ['type' => 'message_stop']],
            ['content_block_delta', ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'NA STOP']]],
        ];
        $raw = '';
        foreach ($events as [$name, $data]) {
            $raw .= "event: {$name}\ndata: " . json_encode($data) . "\n\n";
        }
        $t = FakeTransport::withChunks(str_split($raw, 23));
        $p = new AnthropicProvider($t, self::KEY, 'claude-x');

        self::assertSame(['Goe', 'ie dag'], $this->collect($p));

        $r = $t->requests[0];
        self::assertSame('https://api.anthropic.com/v1/messages', $r->url);
        self::assertSame(self::KEY, $r->headers['x-api-key']);
        self::assertArrayHasKey('anthropic-version', $r->headers);
        self::assertArrayNotHasKey('Authorization', $r->headers);
        $body = self::body($t);
        self::assertSame('Wees kort.', $body['system']);
        self::assertSame(['user', 'assistant', 'user'], array_column($body['messages'], 'role'));
        self::assertArrayHasKey('max_tokens', $body);
    }

    #[Test]
    public function testAnthropicMergesConsecutiveSameRoleTurns(): void
    {
        $t = FakeTransport::withChunks(["event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n"]);
        $p = new AnthropicProvider($t, self::KEY, 'm');
        iterator_to_array($p->stream([
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'user', 'content' => 'b'],
        ], []), false);

        $body = self::body($t);
        self::assertCount(1, $body['messages']);
        self::assertSame("a\n\nb", $body['messages'][0]['content']);
    }

    #[Test]
    public function testAnthropicErrorEvent(): void
    {
        $t = FakeTransport::withChunks(["event: error\ndata: {\"type\":\"error\",\"error\":{\"type\":\"overloaded_error\",\"message\":\"Overloaded\"}}\n\n"]);
        $this->expectException(ProviderException::class);
        $this->collect(new AnthropicProvider($t, self::KEY, 'm'));
    }

    #[Test]
    public function testGoogleStreamsPartsAndKeepsKeyOutOfTheUrl(): void
    {
        $chunk = static fn (string $text): string => 'data: ' . json_encode(['candidates' => [['content' => ['parts' => [['text' => $text]], 'role' => 'model']]]]) . "\n\n";
        $t = FakeTransport::withChunks(str_split($chunk('Hal') . $chunk('lo'), 31));
        $p = new GoogleProvider($t, self::KEY, 'gemini-2.0-flash');

        self::assertSame(['Hal', 'lo'], $this->collect($p));

        $r = $t->requests[0];
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:streamGenerateContent?alt=sse', $r->url);
        self::assertStringNotContainsString(self::KEY, $r->url);
        self::assertSame(self::KEY, $r->headers['x-goog-api-key']);
        $body = self::body($t);
        self::assertSame('Wees kort.', $body['systemInstruction']['parts'][0]['text']);
        self::assertSame(['user', 'model', 'user'], array_column($body['contents'], 'role'));
    }

    #[Test]
    public function testGoogleRejectsModelNamesThatCouldChangeThePath(): void
    {
        $t = new FakeTransport();
        foreach (['models/x', 'a:b', 'x?y=1', 'x#y'] as $bad) {
            try {
                (new GoogleProvider($t, self::KEY, 'm'))->stream($this->msgs, ['model' => $bad]);
                self::fail('Had geweigerd moeten worden: ' . $bad);
            } catch (ProviderException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $t->requests);
    }

    #[Test]
    public function testOllamaNativeNdjsonStream(): void
    {
        $lines = [
            ['message' => ['role' => 'assistant', 'content' => 'He'], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => 'llo'], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => ''], 'done' => true],
        ];
        $raw = implode("\n", array_map('json_encode', $lines)) . "\n";
        $t = FakeTransport::withChunks(str_split($raw, 11));
        $p = new OllamaProvider($t, new SsrfGuard(fn (): array => ['127.0.0.1']), ['host' => 'http://localhost:11434', 'default_model' => 'llama3.2']);

        self::assertSame(['He', 'llo'], $this->collect($p));

        $r = $t->requests[0];
        self::assertSame('http://localhost:11434/api/chat', $r->url);
        self::assertTrue($r->allowHttp);
        self::assertSame('127.0.0.1', $r->pinnedIp, 'Verbinding is vastgepind op het gecontroleerde IP.');
        self::assertSame('llama3.2', self::body($t)['model']);
    }

    #[Test]
    public function testOllamaHidesDeepSeekThinkBlockEvenWhenTagsAreSplitOverChunks(): void
    {
        $lines = [];
        foreach (['<thi', 'nk>Even na', 'denken</th', 'ink>', "\n\nHal", 'lo!'] as $c) {
            $lines[] = ['message' => ['role' => 'assistant', 'content' => $c], 'done' => false];
        }
        $lines[] = ['message' => ['role' => 'assistant', 'content' => ''], 'done' => true];
        $raw = implode("\n", array_map('json_encode', $lines)) . "\n";
        $t = FakeTransport::withChunks(str_split($raw, 7));
        $p = new OllamaProvider($t, new SsrfGuard(fn (): array => ['127.0.0.1']), ['host' => 'http://localhost:11434', 'default_model' => 'deepseek-r1:8b']);

        self::assertSame('Hallo!', implode('', $this->collect($p)));
        self::assertSame('deepseek-r1:8b', self::body($t)['model']);
    }

    #[Test]
    public function testOllamaUsesOpenWebUiOpenAiEndpointWhenConfigured(): void
    {
        $t = FakeTransport::withChunks(self::openAiChunks(['Dieouwe', ' AI']));
        $p = new OllamaProvider(
            $t,
            new SsrfGuard(fn (): array => ['203.0.113.7']),
            ['host' => 'http://localhost:11434', 'default_model' => 'mistral', 'open_webui_url' => 'https://ai.example.nl/'],
            'webui-key-123456'
        );

        self::assertSame(['Dieouwe', ' AI'], $this->collect($p));

        $r = $t->requests[0];
        self::assertSame('https://ai.example.nl/api/chat/completions', $r->url);
        self::assertSame('Bearer webui-key-123456', $r->headers['Authorization']);
        self::assertSame('203.0.113.7', $r->pinnedIp);
    }

    #[Test]
    public function testOllamaRefusesMetadataAndNonHttpHostsBeforeAnyRequest(): void
    {
        $guard = new SsrfGuard(fn (): array => ['93.184.216.34']);
        $bad = [
            ['host' => 'http://169.254.169.254/latest'],
            ['host' => 'file:///etc/passwd'],
            ['host' => 'gopher://localhost:11434'],
            ['host' => 'http://localhost:11434', 'open_webui_url' => 'http://169.254.169.254'],
        ];
        foreach ($bad as $config) {
            $t = new FakeTransport();
            $p = new OllamaProvider($t, $guard, $config);
            try {
                $this->collect($p);
                self::fail('SSRF had geweigerd moeten worden: ' . json_encode($config));
            } catch (ProviderException $e) {
                self::assertStringContainsString('Ollama', $e->getMessage());
            }
            self::assertSame([], $t->requests, 'Er mag geen enkele request uitgaan.');
        }
    }

    #[Test]
    public function testOllamaErrorLine(): void
    {
        $t = FakeTransport::withChunks(["{\"error\":\"model 'x' not found\"}\n"]);
        $p = new OllamaProvider($t, new SsrfGuard(fn (): array => ['127.0.0.1']), []);
        $this->expectException(ProviderException::class);
        $this->collect($p);
    }

    #[Test]
    public function testValidateKeyReturnsTrueOnlyOnSuccess(): void
    {
        $guard = new SsrfGuard(fn (): array => ['127.0.0.1']);
        $providers = [
            new OpenAiProvider(new FakeTransport(), '', ''),
            new AnthropicProvider(new FakeTransport(), '', ''),
            new GoogleProvider(new FakeTransport(), '', ''),
            new DeepSeekProvider(new FakeTransport(), '', ''),
            new MistralProvider(new FakeTransport(), '', ''),
        ];
        foreach ($providers as $p) {
            $ok = new FakeTransport();
            $okProvider = new (get_class($p))($ok, '', '');
            self::assertTrue($okProvider->validateKey(self::KEY), $p->slug());
            self::assertSame('GET', $ok->requests[0]->method);
            self::assertStringNotContainsString(self::KEY, $ok->requests[0]->url);

            $denied = new FakeTransport();
            $denied->sendResponse = new HttpResponse(401, '{}');
            self::assertFalse((new (get_class($p))($denied, '', ''))->validateKey(self::KEY), $p->slug() . ' 401');

            $down = new FakeTransport();
            $down->sendError = new TransportException('Verbinding mislukt');
            self::assertFalse((new (get_class($p))($down, '', ''))->validateKey(self::KEY), $p->slug() . ' netwerk');

            $none = new FakeTransport();
            self::assertFalse((new (get_class($p))($none, '', ''))->validateKey("bad key\n"), $p->slug() . ' ongeldig formaat');
            self::assertSame([], $none->requests);
        }

        $t = new FakeTransport();
        self::assertTrue((new OllamaProvider($t, $guard, ['host' => 'http://localhost:11434']))->validateKey(''));
        self::assertSame('http://localhost:11434/api/tags', $t->requests[0]->url);
    }

    #[Test]
    public function testEverySlugAndLabelIsUniqueAndUrlSafe(): void
    {
        $t = new FakeTransport();
        $g = new SsrfGuard();
        $all = [
            new OpenAiProvider($t), new AnthropicProvider($t), new GoogleProvider($t),
            new DeepSeekProvider($t), new MistralProvider($t), new OllamaProvider($t, $g),
        ];
        $slugs = array_map(static fn (ProviderInterface $p): string => $p->slug(), $all);
        self::assertSame(['openai', 'anthropic', 'google', 'deepseek', 'mistral', 'ollama'], $slugs);
        foreach ($all as $p) {
            self::assertMatchesRegularExpression('/^[a-z]+$/', $p->slug());
            self::assertNotSame('', $p->label());
        }
    }
}
