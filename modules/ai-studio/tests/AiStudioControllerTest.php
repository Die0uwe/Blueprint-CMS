<?php

// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Tests;

require_once __DIR__ . '/Support/bootstrap.php';

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\HttpException;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Modules\AiStudio\AiStudioController;
use CommunityFusion\Modules\AiStudio\ConversationRepository;
use CommunityFusion\Modules\AiStudio\Http\HttpResponse;
use CommunityFusion\Modules\AiStudio\Http\SsrfGuard;
use CommunityFusion\Modules\AiStudio\MessageRepository;
use CommunityFusion\Modules\AiStudio\ProviderRegistry;
use CommunityFusion\Modules\AiStudio\SchemaMigrator;
use CommunityFusion\Modules\AiStudio\SettingsStore;
use CommunityFusion\Modules\AiStudio\Tests\Support\ArrayCache;
use CommunityFusion\Modules\AiStudio\Tests\Support\FakeAuth;
use CommunityFusion\Modules\AiStudio\Tests\Support\FakeTransport;
use CommunityFusion\Modules\AiStudio\Tests\Support\TestDb;
use CommunityFusion\Modules\AiStudio\UserRateLimiter;
use PDO;
use PHPUnit\Framework\TestCase;

final class AiStudioControllerTest extends TestCase
{
    private const CSRF = 'csrf-token-for-tests-0123456789abcdef';
    private const KEY = 'sk-live-NEVERLEAK-9876543210';

    private PDO $pdo;
    private FakeTransport $http;
    private FakeAuth $auth;
    private AiStudioController $controller;
    private ConversationRepository $conversations;
    private SettingsStore $settings;

    protected function setUp(): void
    {
        $_SESSION['_csrf_token'] = self::CSRF;
        $this->pdo = TestDb::pdo();
        $db = TestDb::connection($this->pdo);
        $this->settings = new SettingsStore($db);
        $this->settings->set(SettingsStore::GROUP, SchemaMigrator::VERSION_KEY, SchemaMigrator::SCHEMA_VERSION);
        $this->http = new FakeTransport();
        $registry = new ProviderRegistry($this->settings, $this->http, new SsrfGuard(fn (): array => ['127.0.0.1']));
        $this->conversations = new ConversationRepository($db);
        $this->auth = new FakeAuth(1);
        $this->controller = new AiStudioController(
            $this->auth,
            $registry,
            $this->conversations,
            new MessageRepository($db),
            $this->settings,
            new SchemaMigrator($this->pdo, $this->settings, dirname(__DIR__) . '/migrations'),
            new AuditLogger($db),
            new UserRateLimiter(new ArrayCache()),
            dirname(__DIR__),
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, mixed> $params
     */
    private function req(array $body = [], array $headers = ['X-CSRF-Token' => self::CSRF], array $params = []): Request
    {
        $r = new Request('POST', '/x', [], $body, $headers, [], [], []);
        $r->setParams($params);
        return $r;
    }

    private function storedRaw(): string
    {
        return json_encode($this->pdo->query('SELECT * FROM cf_settings')->fetchAll()) ?: '';
    }

    public function testSaveSettingsStoresKeyEncryptedAndNeverEchoesIt(): void
    {
        $this->http->sendResponse = new HttpResponse(200, '{"data":[]}');

        $r = $this->controller->saveSettings($this->req(['f' => ['provider.openai.api_key' => self::KEY]]));

        self::assertSame(302, $r->getStatus());
        self::assertStringNotContainsString(self::KEY, $r->getBody());
        self::assertStringNotContainsString('NEVERLEAK', $this->storedRaw(), 'In de database staat de key versleuteld.');
        $row = $this->pdo->query("SELECT type FROM cf_settings WHERE \"key\" = 'provider.openai.api_key'")->fetch();
        self::assertSame('encrypted', $row['type']);

        $page = $this->controller->settings($this->req());
        self::assertStringNotContainsString('NEVERLEAK', $page->getBody());
        self::assertStringNotContainsString(self::KEY, json_encode($this->pdo->query('SELECT * FROM cf_audit_log')->fetchAll()) ?: '');
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM cf_audit_log WHERE action = 'aistudio.settings.key_updated'")->fetchColumn());
    }

    public function testRejectedKeyIsNotStored(): void
    {
        $this->http->sendResponse = new HttpResponse(401, '{"error":"bad"}');

        $r = $this->controller->saveSettings($this->req(['f' => ['provider.openai.api_key' => self::KEY]]));

        self::assertSame(422, $r->getStatus());
        self::assertStringNotContainsString('NEVERLEAK', $r->getBody());
        self::assertStringNotContainsString('NEVERLEAK', $this->storedRaw());
    }

    public function testSaveSettingsRequiresCsrf(): void
    {
        $r = $this->controller->saveSettings($this->req(['f' => ['provider.openai.api_key' => self::KEY]], []));

        self::assertSame(403, $r->getStatus());
        self::assertSame([], $this->http->requests);
        self::assertStringNotContainsString('NEVERLEAK', $this->storedRaw());
    }

    public function testSaveSettingsRequiresAdminPermission(): void
    {
        $this->auth->permissions = ['aistudio.use'];
        $this->expectException(HttpException::class);
        $this->controller->saveSettings($this->req());
    }

    public function testBadKeyFormatIsRefusedWithoutNetworkCall(): void
    {
        $r = $this->controller->saveSettings($this->req(['f' => ['provider.openai.api_key' => "bad key\nwith space"]]));

        self::assertSame(422, $r->getStatus());
        self::assertSame([], $this->http->requests);
    }

    public function testClearRemovesKey(): void
    {
        $this->http->sendResponse = new HttpResponse(200, '{}');
        $this->controller->saveSettings($this->req(['f' => ['provider.openai.api_key' => self::KEY]]));
        $this->controller->saveSettings($this->req(['clear' => ['provider.openai.api_key' => '1']]));

        $page = $this->controller->settings($this->req());
        self::assertSame(200, $page->getStatus());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM cf_audit_log WHERE action = 'aistudio.settings.key_removed'")->fetchColumn());
    }

    public function testSaveSettingsRateLimit(): void
    {
        $last = 0;
        for ($i = 0; $i < 11; $i++) {
            $last = $this->controller->saveSettings($this->req())->getStatus();
        }
        self::assertSame(429, $last);
    }

    public function testConversationOwnership(): void
    {
        $mine = $this->conversations->create(1, 'mijn', '', '');
        $theirs = $this->conversations->create(2, 'hunne', '', '');

        self::assertSame(200, $this->controller->conversation($this->req([], [], ['id' => (string) $mine]))->getStatus());
        self::assertSame(404, $this->controller->conversation($this->req([], [], ['id' => (string) $theirs]))->getStatus());
    }

    public function testDeleteConversationChecksCsrfAndOwner(): void
    {
        $theirs = $this->conversations->create(2, 'hunne', '', '');

        self::assertSame(403, $this->controller->deleteConversation($this->req(['conversation_id' => $theirs], []))->getStatus());
        $this->controller->deleteConversation($this->req(['conversation_id' => $theirs]));
        self::assertNotNull($this->conversations->find($theirs, 2), 'Andermans gesprek blijft bestaan.');
    }

    public function testNewConversationRequiresCsrf(): void
    {
        self::assertSame(403, $this->controller->newConversation($this->req(['title' => 'x'], []))->getStatus());
    }

    public function testAssetWhitelist(): void
    {
        foreach (['studio.css', 'studio.js', 'editor.js', 'sse.js'] as $f) {
            self::assertSame(200, $this->controller->asset($this->req([], [], ['file' => $f]))->getStatus(), $f);
        }
        foreach (['../module.json', '..%2fmodule.json', 'module.json', 'routes.php', '', 'studio.css/../../x'] as $f) {
            self::assertSame(404, $this->controller->asset($this->req([], [], ['file' => $f]))->getStatus(), $f);
        }
    }

    public function testIndexSendsCspAndEscapesOutput(): void
    {
        $this->conversations->create(1, '<script>alert(1)</script>', '', '');

        $r = $this->controller->index($this->req());

        self::assertSame(200, $r->getStatus());
        self::assertStringContainsString('&lt;script&gt;alert(1)', $r->getBody());
        self::assertStringNotContainsString('<script>alert(1)', $r->getBody());
        self::assertStringContainsString("default-src 'none'", (string) ((new \ReflectionProperty($r, 'headers'))->getValue($r)['Content-Security-Policy'] ?? ''));
    }
}
