<?php

// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Tests;

require_once __DIR__ . '/Support/bootstrap.php';

use CommunityFusion\Core\Security\Crypto;
use CommunityFusion\Modules\AiStudio\Http\HttpResponse;
use CommunityFusion\Modules\AiStudio\Http\SsrfGuard;
use CommunityFusion\Modules\AiStudio\Provider\ProviderException;
use CommunityFusion\Modules\AiStudio\ProviderRegistry;
use CommunityFusion\Modules\AiStudio\SettingsStore;
use CommunityFusion\Modules\AiStudio\Tests\Support\FakeTransport;
use CommunityFusion\Modules\AiStudio\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    private PDO $pdo;
    private SettingsStore $store;
    private FakeTransport $http;
    private ProviderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = TestDb::pdo();
        $this->store = new SettingsStore(TestDb::connection($this->pdo));
        $this->http = new FakeTransport();
        $this->registry = new ProviderRegistry($this->store, $this->http, new SsrfGuard(fn (): array => ['127.0.0.1']));
    }

    #[Test]
    public function testKnowsExactlyTheSixProviders(): void
    {
        self::assertSame(['openai', 'anthropic', 'google', 'deepseek', 'mistral', 'ollama'], $this->registry->slugs());
        self::assertTrue($this->registry->exists('openai'));
        self::assertFalse($this->registry->exists('evil'));
        self::assertFalse($this->registry->exists('../openai'));
    }

    #[Test]
    public function testSettingKeyUsesTheAistudioPrefix(): void
    {
        self::assertSame('provider.openai.api_key', ProviderRegistry::keySetting('openai'));
        self::assertSame('aistudio', SettingsStore::GROUP);
    }

    #[Test]
    public function testOnlyProvidersWithAKeyAreConfiguredExceptOllama(): void
    {
        self::assertSame(['ollama'], $this->registry->configuredSlugs());

        $this->registry->storeKey('openai', 'sk-test-0123456789');
        $this->registry->storeKey('mistral', 'mistral-key-0123456789');
        self::assertSame(['openai', 'mistral', 'ollama'], $this->registry->configuredSlugs());
        self::assertTrue($this->registry->hasKey('openai'));
        self::assertFalse($this->registry->hasKey('google'));
    }

    #[Test]
    public function testKeyIsStoredEncryptedNotAsPlaintext(): void
    {
        $key = 'sk-test-PLAINTEXT-0123456789';
        $this->registry->storeKey('openai', $key);

        $row = $this->pdo->query('SELECT "group", "key", value, type FROM cf_settings')->fetch();
        self::assertSame('aistudio', $row['group']);
        self::assertSame('provider.openai.api_key', $row['key']);
        self::assertSame('encrypted', $row['type']);
        self::assertStringNotContainsString($key, (string) $row['value']);
        self::assertStringNotContainsString('PLAINTEXT', (string) $row['value']);
        self::assertSame($key, Crypto::decrypt((string) $row['value']), 'Roundtrip via Core\Security\Crypto (AES-256-GCM).');

        // AES-256-GCM: iv(12) + tag(16) + ciphertext, base64.
        $raw = base64_decode((string) $row['value'], true);
        self::assertIsString($raw);
        self::assertSame(12 + 16 + strlen($key), strlen($raw));
    }

    #[Test]
    public function testEncryptingTwiceGivesDifferentCiphertext(): void
    {
        $this->registry->storeKey('openai', 'sk-test-0123456789');
        $first = (string) $this->pdo->query('SELECT value FROM cf_settings')->fetchColumn();
        $this->registry->storeKey('openai', 'sk-test-0123456789');
        $second = (string) $this->pdo->query('SELECT value FROM cf_settings')->fetchColumn();

        self::assertNotSame($first, $second, 'Willekeurige IV per versleuteling.');
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM cf_settings')->fetchColumn(), 'Upsert, geen duplicaat.');
    }

    #[Test]
    public function testGetBuildsProviderWithDecryptedKey(): void
    {
        $this->registry->storeKey('anthropic', 'sk-ant-test-0123456789');
        $provider = $this->registry->get('anthropic');
        self::assertSame('anthropic', $provider->slug());

        $this->http->chunks = ["event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n"];
        iterator_to_array($provider->stream([['role' => 'user', 'content' => 'hoi']], []), false);
        self::assertSame('sk-ant-test-0123456789', $this->http->requests[0]->headers['x-api-key']);
        self::assertSame('claude-sonnet-4-5', json_decode((string) $this->http->requests[0]->body, true)['model']);
    }

    #[Test]
    public function testGetRefusesUnknownOrUnconfiguredProviders(): void
    {
        foreach (['nope', '', 'OpenAI', 'openai'] as $slug) {
            try {
                $this->registry->get($slug);
                self::fail('Had moeten falen voor: ' . $slug);
            } catch (ProviderException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testClearKeyRemovesAccess(): void
    {
        $this->registry->storeKey('google', 'AIza-test-0123456789');
        self::assertTrue($this->registry->isConfigured('google'));

        $this->registry->clearKey('google');

        self::assertFalse($this->registry->isConfigured('google'));
        self::assertFalse($this->registry->hasKey('google'));
        self::assertSame('', (string) $this->pdo->query('SELECT value FROM cf_settings')->fetchColumn());
    }

    #[Test]
    public function testStoreKeyRejectsUnknownProvider(): void
    {
        $this->expectException(ProviderException::class);
        $this->registry->storeKey('evil', 'sk-test-0123456789');
    }

    #[Test]
    public function testModelDefaultsAndOverrides(): void
    {
        self::assertSame('gpt-4o-mini', $this->registry->defaultModel('openai'));
        $this->store->set(SettingsStore::GROUP, 'provider.openai.model', 'gpt-4.1');
        self::assertSame('gpt-4.1', $this->registry->defaultModel('openai'));
    }

    #[Test]
    public function testOllamaReusesTheExistingOllamaModuleSettings(): void
    {
        $this->store->set('ollama', 'host', 'http://192.168.1.50:11434');
        $this->store->set('ollama', 'default_model', 'mistral');
        $this->store->set('ollama', 'timeout', '45');

        self::assertSame('mistral', $this->registry->defaultModel('ollama'));

        $provider = $this->registry->get('ollama');
        $this->http->chunks = ["{\"message\":{\"content\":\"ok\"},\"done\":true}\n"];
        iterator_to_array($provider->stream([['role' => 'user', 'content' => 'hoi']], []), false);

        self::assertSame('http://192.168.1.50:11434/api/chat', $this->http->requests[0]->url);
        self::assertSame('mistral', json_decode((string) $this->http->requests[0]->body, true)['model']);
        self::assertSame(45, $this->http->requests[0]->timeoutSeconds);
    }

    #[Test]
    public function testOllamaUsesEncryptedOpenWebUiKeyFromOllamaModule(): void
    {
        $this->store->set('ollama', 'open_webui_url', 'https://ai.example.nl');
        $this->store->set('ollama', 'open_webui_key', 'owui-secret-0123456789', 'encrypted');

        $provider = $this->registry->get('ollama');
        $this->http->chunks = ["data: [DONE]\n\n"];
        iterator_to_array($provider->stream([['role' => 'user', 'content' => 'hoi']], []), false);

        self::assertSame('https://ai.example.nl/api/chat/completions', $this->http->requests[0]->url);
        self::assertSame('Bearer owui-secret-0123456789', $this->http->requests[0]->headers['Authorization']);
    }

    #[Test]
    public function testValidateKeyDelegatesToProviderWithoutStoring(): void
    {
        self::assertTrue($this->registry->validateKey('openai', 'sk-test-0123456789'));
        self::assertSame('https://api.openai.com/v1/models', $this->http->requests[0]->url);

        $this->http->sendResponse = new HttpResponse(401, '');
        self::assertFalse($this->registry->validateKey('openai', 'sk-bad-0123456789'));
        self::assertFalse($this->registry->validateKey('evil', 'sk-test-0123456789'));
        self::assertFalse($this->registry->hasKey('openai'), 'Valideren bewaart niets.');
    }

    #[Test]
    public function testNoMethodExposesTheKeyToCallers(): void
    {
        $public = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(ProviderRegistry::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        foreach ($public as $name) {
            self::assertDoesNotMatchRegularExpression('/^(get|read|reveal|export)Key$/i', $name);
        }
        self::assertContains('hasKey', $public);
    }
}
