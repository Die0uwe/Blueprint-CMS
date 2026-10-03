<?php

// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Tests;

require_once __DIR__ . '/Support/bootstrap.php';

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\HttpException;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Modules\AiStudio\ChatController;
use CommunityFusion\Modules\AiStudio\ConversationRepository;
use CommunityFusion\Modules\AiStudio\DiffService;
use CommunityFusion\Modules\AiStudio\Http\SsrfGuard;
use CommunityFusion\Modules\AiStudio\MessageRepository;
use CommunityFusion\Modules\AiStudio\PromptBuilder;
use CommunityFusion\Modules\AiStudio\ProviderRegistry;
use CommunityFusion\Modules\AiStudio\SettingsStore;
use CommunityFusion\Modules\AiStudio\SseWriter;
use CommunityFusion\Modules\AiStudio\Tests\Support\ArrayCache;
use CommunityFusion\Modules\AiStudio\Tests\Support\FakeAuth;
use CommunityFusion\Modules\AiStudio\Tests\Support\FakeTransport;
use CommunityFusion\Modules\AiStudio\Tests\Support\TestDb;
use CommunityFusion\Modules\AiStudio\UserRateLimiter;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChatControllerTest extends TestCase
{
    private const KEY = 'sk-test-SECRET-0123456789';
    private const CSRF = 'csrf-token-for-tests-0123456789abcdef';

    private PDO $pdo;
    private FakeTransport $http;
    private FakeAuth $auth;
    private ChatController $controller;
    private ProviderRegistry $registry;
    private ConversationRepository $conversations;
    private MessageRepository $messages;
    private ArrayCache $cache;
    private string $out = '';
    /** @var list<string> */
    private array $headers = [];
    private int $terminated = 0;
    private int $conversationId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['_csrf_token'] = self::CSRF;

        $this->pdo = TestDb::pdo();
        $db = TestDb::connection($this->pdo);
        $settings = new SettingsStore($db);
        $this->http = new FakeTransport();
        $this->registry = new ProviderRegistry($settings, $this->http, new SsrfGuard(fn (): array => ['127.0.0.1']));
        $this->registry->storeKey('openai', self::KEY);
        $this->conversations = new ConversationRepository($db);
        $this->messages = new MessageRepository($db);
        $this->auth = new FakeAuth(1);
        $this->cache = new ArrayCache();
        $this->conversationId = $this->conversations->create(1, 'test', '', '');

        $this->controller = new ChatController(
            $this->auth,
            $this->registry,
            $this->conversations,
            $this->messages,
            new PromptBuilder(),
            new DiffService(),
            new AuditLogger($db),
            new UserRateLimiter($this->cache),
            $settings,
            [
                'sse' => fn (): SseWriter => new SseWriter(
                    function (string $s): void {
                        $this->out .= $s;
                    },
                    function (string $h): void {
                        $this->headers[] = $h;
                    },
                    static fn (): bool => false,
                    static function (): void {
                    },
                ),
                'terminate' => function (): void {
                    $this->terminated++;
                },
                'close_session' => false,
                'locale' => 'nl',
                'timezone' => 'Europe/Amsterdam',
            ],
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     */
    private function request(array $body, array $headers = ['X-CSRF-Token' => self::CSRF], array $query = []): Request
    {
        return new Request('POST', '/admin/ai-studio/chat', $query, $body, $headers, [], [], []);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function chatBody(array $extra = []): array
    {
        return $extra + [
            'conversation_id' => $this->conversationId,
            'provider' => 'openai',
            'model' => '',
            'message' => 'Hallo AI',
        ];
    }

    /**
     * @param list<string> $deltas
     */
    private function reply(array $deltas): void
    {
        $raw = '';
        foreach ($deltas as $d) {
            $raw .= 'data: ' . json_encode(['choices' => [['delta' => ['content' => $d]]]]) . "\n\n";
        }
        $this->http->chunks = [$raw . "data: [DONE]\n\n"];
    }

    /**
     * @return list<array{event: string, data: array<string, mixed>}>
     */
    private function events(): array
    {
        $events = [];
        foreach (explode("\n\n", trim($this->out)) as $frame) {
            if (!str_starts_with($frame, 'event: ')) {
                continue;
            }
            [$e, $d] = explode("\n", $frame, 2);
            $events[] = ['event' => substr($e, 7), 'data' => json_decode(substr($d, 6), true)];
        }
        return $events;
    }

    /**
     * @return list<string>
     */
    private function eventNames(): array
    {
        return array_column($this->events(), 'event');
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $r): array
    {
        $data = json_decode($r->getBody(), true);
        self::assertIsArray($data);
        return $data;
    }

    // ─── toegang en CSRF ─────────────────────────────────────────────────

    #[Test]
    public function testRequiresThePermission(): void
    {
        $this->auth->permissions = [];
        $this->expectException(HttpException::class);
        $this->controller->chat($this->request($this->chatBody()));
    }

    #[Test]
    public function testRejectsMissingOrWrongCsrfWithoutTouchingProviderOrDatabase(): void
    {
        $cases = [
            'geen token' => [[], $this->chatBody()],
            'verkeerde header' => [['X-CSRF-Token' => 'nope'], $this->chatBody()],
            'leeg' => [['X-CSRF-Token' => ''], $this->chatBody()],
            'verkeerde body' => [[], $this->chatBody(['_csrf_token' => 'nope'])],
        ];
        foreach ($cases as $name => [$headers, $body]) {
            $r = $this->controller->chat($this->request($body, $headers));
            self::assertSame(403, $r->getStatus(), $name);
            self::assertSame('csrf', $this->json($r)['code'], $name);
        }
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->messages->recent($this->conversationId));
        self::assertSame('', $this->out);
    }

    #[Test]
    public function testCsrfInQueryStringIsRefusedEvenWhenCorrect(): void
    {
        $r = $this->controller->chat($this->request($this->chatBody(), [], ['_csrf_token' => self::CSRF]));
        self::assertSame(403, $r->getStatus());
        self::assertSame([], $this->http->requests);
    }

    #[Test]
    public function testCsrfInBodyFieldIsAccepted(): void
    {
        $this->reply(['ok']);
        $r = $this->controller->chat($this->request($this->chatBody(['_csrf_token' => self::CSRF]), []));
        self::assertSame(200, $r->getStatus());
        self::assertContains('done', $this->eventNames());
    }

    // ─── validatie ───────────────────────────────────────────────────────

    #[Test]
    public function testValidationErrors(): void
    {
        $cases = [
            'leeg bericht' => [$this->chatBody(['message' => '   ']), 422],
            'geen bericht' => [$this->chatBody(['message' => ['array']]), 422],
            'te lang' => [$this->chatBody(['message' => str_repeat('x', PromptBuilder::MAX_USER_MESSAGE_BYTES + 1)]), 413],
            'slecht model' => [$this->chatBody(['model' => '../../x']), 422],
            'onbekende provider' => [$this->chatBody(['provider' => 'evil']), 422],
            'niet-ingestelde provider' => [$this->chatBody(['provider' => 'google']), 422],
            'gesprek bestaat niet' => [$this->chatBody(['conversation_id' => 999]), 404],
            'conversation_id als array' => [$this->chatBody(['conversation_id' => [1]]), 404],
            'editor te groot' => [$this->chatBody(['editor' => ['content' => str_repeat('x', ChatController::MAX_EDITOR_BYTES + 1), 'language' => 'html']]), 413],
        ];
        foreach ($cases as $name => [$body, $status]) {
            $r = $this->controller->chat($this->request($body));
            self::assertSame($status, $r->getStatus(), $name);
        }
        self::assertSame([], $this->http->requests, 'Bij validatiefouten gaat er niets naar de provider.');
        self::assertSame('', $this->out);
    }

    #[Test]
    public function testCannotUseSomeoneElsesConversation(): void
    {
        $theirs = $this->conversations->create(2, 'van een ander', '', '');
        $r = $this->controller->chat($this->request($this->chatBody(['conversation_id' => $theirs])));

        self::assertSame(404, $r->getStatus());
        self::assertSame([], $this->messages->recent($theirs), 'Er wordt niets in andermans gesprek geschreven.');
        self::assertSame([], $this->http->requests);
    }

    #[Test]
    public function testPerUserRateLimit(): void
    {
        $this->reply(['ok']);
        for ($i = 0; $i < 20; $i++) {
            $this->http->chunks = $this->http->chunks ?: ["data: [DONE]\n\n"];
            self::assertSame(200, $this->controller->chat($this->request($this->chatBody()))->getStatus(), "poging {$i}");
        }
        $r = $this->controller->chat($this->request($this->chatBody()));
        self::assertSame(429, $r->getStatus());
        self::assertSame('rate', $this->json($r)['code']);
    }

    // ─── streamen ────────────────────────────────────────────────────────

    #[Test]
    public function testStreamsDeltasStoresMessagesAndTerminates(): void
    {
        $this->reply(['Hal', 'lo ', 'wereld']);

        $r = $this->controller->chat($this->request($this->chatBody(['message' => 'Zeg hallo'])));

        self::assertSame(200, $r->getStatus());
        self::assertSame(['start', 'delta', 'delta', 'delta', 'done'], $this->eventNames());
        self::assertSame(1, $this->terminated, 'Na de stream wordt de request beëindigd (geen Response::send erachteraan).');
        self::assertContains('Content-Type: text/event-stream; charset=utf-8', $this->headers);
        self::assertContains('X-Accel-Buffering: no', $this->headers);

        $texts = array_map(static fn (array $e): string => $e['data']['text'], array_filter($this->events(), static fn (array $e): bool => $e['event'] === 'delta'));
        self::assertSame('Hallo wereld', implode('', $texts));

        $stored = $this->messages->recent($this->conversationId);
        self::assertSame(['user', 'assistant'], array_column($stored, 'role'));
        self::assertSame('Zeg hallo', $stored[0]['content']);
        self::assertSame('Hallo wereld', $stored[1]['content']);
        self::assertSame('openai', $stored[1]['provider']);
        self::assertSame('gpt-4o-mini', $stored[1]['model'], 'Zonder model: de standaard van de provider.');
    }

    #[Test]
    public function testHistoryAndSystemPromptReachTheProvider(): void
    {
        $this->messages->add($this->conversationId, 'user', 'eerdere vraag');
        $this->messages->add($this->conversationId, 'assistant', 'eerder antwoord');
        $this->reply(['ok']);

        $this->controller->chat($this->request($this->chatBody(['message' => 'nieuwe vraag'])));

        $sent = json_decode((string) $this->http->requests[0]->body, true)['messages'];
        self::assertSame(['system', 'user', 'assistant', 'user'], array_column($sent, 'role'));
        self::assertSame('nieuwe vraag', $sent[3]['content']);
        self::assertStringContainsString('Europe/Amsterdam', $sent[0]['content']);
    }

    #[Test]
    public function testEditorContentIsSentAsContext(): void
    {
        $this->reply(['ok']);
        $this->controller->chat($this->request($this->chatBody([
            'message' => 'Wat doet dit?',
            'editor' => ['content' => '<?php echo 1;', 'language' => 'php'],
        ])));

        $user = json_decode((string) $this->http->requests[0]->body, true)['messages'][1]['content'];
        self::assertStringContainsString('<?php echo 1;', $user);
        self::assertStringContainsString('language="php"', $user);
    }

    #[Test]
    public function testNoEditorContextWhenNotSent(): void
    {
        $this->reply(['ok']);
        $this->controller->chat($this->request($this->chatBody(['editor' => ['content' => '', 'language' => 'php']])));

        $user = json_decode((string) $this->http->requests[0]->body, true)['messages'][1]['content'];
        self::assertStringNotContainsString('editor-context', $user);
    }

    // ─── wijzigingsvoorstel (diff) ───────────────────────────────────────

    #[Test]
    public function testProposalBecomesADiffEventAndNeverRawEditorContent(): void
    {
        $base = "<h1>Hoi</h1>\n<p>tekst</p>\n";
        $this->reply(["Aangepast:\n```proposed-file\n<h1>Hallo</h1>\n<p>tekst</p>\n```\n"]);

        $this->controller->chat($this->request($this->chatBody(['editor' => ['content' => $base, 'language' => 'html']])));

        $names = $this->eventNames();
        self::assertSame(['start', 'delta', 'proposal', 'done'], $names);
        $proposal = $this->events()[2]['data'];
        self::assertStringContainsString("-<h1>Hoi</h1>\n+<h1>Hallo</h1>", $proposal['diff']);
        self::assertSame(['added' => 1, 'removed' => 1, 'hunks' => 1], $proposal['stats']);
        self::assertArrayNotHasKey('content', $proposal, 'Alleen een diff; nooit de nieuwe editorinhoud zelf.');

        $row = $this->pdo->query("SELECT proposal_diff, proposal_base_sha256, proposal_applied_at FROM cf_ai_messages WHERE role = 'assistant'")->fetch();
        self::assertSame($proposal['diff'], $row['proposal_diff']);
        self::assertSame(hash('sha256', $base), $row['proposal_base_sha256']);
        self::assertNull($row['proposal_applied_at'], 'Opgeslagen is niet toegepast.');
    }

    #[Test]
    public function testNoProposalWithoutEditorOrWithoutFence(): void
    {
        $this->reply(["```proposed-file\n<b>x</b>\n```"]);
        $this->controller->chat($this->request($this->chatBody()));
        self::assertNotContains('proposal', $this->eventNames(), 'Zonder editor geen voorstel.');

        $this->out = '';
        $this->reply(["```html\n<b>x</b>\n```"]);
        $this->controller->chat($this->request($this->chatBody(['editor' => ['content' => 'a', 'language' => 'html']])));
        self::assertNotContains('proposal', $this->eventNames(), 'Gewoon codeblok is geen voorstel.');
    }

    #[Test]
    public function testIdenticalProposalGivesNoticeNotDiff(): void
    {
        $this->reply(["```proposed-file\nzelfde\n```"]);
        $this->controller->chat($this->request($this->chatBody(['editor' => ['content' => "zelfde\n", 'language' => 'text']])));

        self::assertContains('notice', $this->eventNames());
        self::assertNotContains('proposal', $this->eventNames());
    }

    #[Test]
    public function testMaliciousReplyIsJustTextInEvents(): void
    {
        $evil = '<script>alert(1)</script><img src=x onerror=alert(1)>';
        $this->reply([$evil]);

        $this->controller->chat($this->request($this->chatBody()));

        // JSON-gecodeerd in een text/event-stream: de client zet dit met textContent in de DOM.
        self::assertContains('Content-Type: text/event-stream; charset=utf-8', $this->headers);
        self::assertSame($evil, $this->events()[1]['data']['text']);
        self::assertSame($evil, $this->messages->recent($this->conversationId)[1]['content']);
    }

    // ─── fouten ──────────────────────────────────────────────────────────

    #[Test]
    public function testProviderErrorBecomesSafeErrorEventAndStoresNoAnswer(): void
    {
        $this->http->chunks = ['data: ' . json_encode(['choices' => [['delta' => ['content' => 'deel']]]]) . "\n\n"];
        $this->http->streamError = new \CommunityFusion\Modules\AiStudio\Http\TransportException('HTTP 401', 401, 'bad key ' . self::KEY);

        $this->controller->chat($this->request($this->chatBody()));

        $names = $this->eventNames();
        self::assertContains('error', $names);
        self::assertNotContains('done', $names);
        self::assertSame(['user'], array_column($this->messages->recent($this->conversationId), 'role'));
        self::assertSame(1, $this->terminated);
    }

    #[Test]
    public function testApiKeyNeverAppearsInStreamLogsOrAudit(): void
    {
        // Ook niet als een provider de key terugecho't in een foutmelding.
        $this->http->chunks = ['data: ' . json_encode(['error' => ['message' => 'invalid key ' . self::KEY]]) . "\n\n"];
        $this->controller->chat($this->request($this->chatBody()));

        $dump = $this->out . json_encode($this->headers) . json_encode($this->pdo->query('SELECT * FROM cf_audit_log')->fetchAll())
            . json_encode($this->pdo->query('SELECT * FROM cf_ai_messages')->fetchAll())
            . json_encode($this->pdo->query('SELECT * FROM cf_ai_conversations')->fetchAll());
        self::assertStringNotContainsString(self::KEY, $dump);
        self::assertStringNotContainsString('SECRET-0123', $dump);
    }

    #[Test]
    public function testEmptyProviderAnswerGivesErrorEvent(): void
    {
        $this->http->chunks = ["data: [DONE]\n\n"];
        $this->controller->chat($this->request($this->chatBody()));

        self::assertContains('error', $this->eventNames());
        self::assertSame(['user'], array_column($this->messages->recent($this->conversationId), 'role'));
    }

    // ─── apply-diff ──────────────────────────────────────────────────────

    /**
     * @return array{int, string} message_id en de editorinhoud waarop het voorstel gebaseerd is
     */
    private function makeProposal(): array
    {
        $base = "<h1>Hoi</h1>\n<p>tekst</p>\n";
        $this->reply(["```proposed-file\n<h1>Hallo</h1>\n<p>tekst</p>\n```"]);
        $this->controller->chat($this->request($this->chatBody(['editor' => ['content' => $base, 'language' => 'html']])));
        $done = array_values(array_filter($this->events(), static fn (array $e): bool => $e['event'] === 'done'))[0];
        return [(int) $done['data']['message_id'], $base];
    }

    private function apply(int $messageId, mixed $base, array $headers = ['X-CSRF-Token' => self::CSRF]): Response
    {
        return $this->controller->applyDiff(new Request('POST', '/admin/ai-studio/apply-diff', [], ['message_id' => $messageId, 'base' => $base], $headers, [], [], []));
    }

    #[Test]
    public function testApplyDiffReturnsValidatedResultOnlyOnExplicitCall(): void
    {
        [$id, $base] = $this->makeProposal();
        self::assertNull($this->pdo->query("SELECT proposal_applied_at FROM cf_ai_messages WHERE id = {$id}")->fetchColumn() ?: null);

        $r = $this->apply($id, $base);

        self::assertSame(200, $r->getStatus());
        $data = $this->json($r);
        self::assertSame("<h1>Hallo</h1>\n<p>tekst</p>\n", $data['content']);
        self::assertSame(hash('sha256', $data['content']), $data['sha256']);
        self::assertNotNull($this->pdo->query("SELECT proposal_applied_at FROM cf_ai_messages WHERE id = {$id}")->fetchColumn());
    }

    #[Test]
    public function testApplyDiffWritesAuditWithoutContent(): void
    {
        [$id, $base] = $this->makeProposal();
        $this->apply($id, $base);

        $row = $this->pdo->query("SELECT user_id, username, action, context FROM cf_audit_log WHERE action = 'aistudio.diff.applied'")->fetch();
        self::assertSame('tester', $row['username']);
        $ctx = json_decode((string) $row['context'], true);
        self::assertSame(['conversation_id', 'message_id', 'added', 'removed'], array_keys($ctx));
        self::assertStringNotContainsString('Hallo', (string) $row['context']);
        self::assertStringNotContainsString(self::KEY, (string) $row['context']);
    }

    #[Test]
    public function testApplyDiffRefusesWhenEditorChangedSinceProposal(): void
    {
        [$id, $base] = $this->makeProposal();

        $r = $this->apply($id, $base . 'extra regel');

        self::assertSame(409, $r->getStatus());
        self::assertSame('editor_changed', $this->json($r)['code']);
        self::assertArrayNotHasKey('content', $this->json($r));
        self::assertFalse((bool) $this->pdo->query("SELECT proposal_applied_at FROM cf_ai_messages WHERE id = {$id}")->fetchColumn());
    }

    #[Test]
    public function testApplyDiffRefusesWithoutValidCsrf(): void
    {
        [$id, $base] = $this->makeProposal();

        foreach ([[], ['X-CSRF-Token' => 'fout']] as $headers) {
            $r = $this->apply($id, $base, $headers);
            self::assertSame(403, $r->getStatus());
        }
        self::assertFalse((bool) $this->pdo->query("SELECT proposal_applied_at FROM cf_ai_messages WHERE id = {$id}")->fetchColumn());
    }

    #[Test]
    public function testApplyDiffRequiresPermissionAndOwnership(): void
    {
        [$id, $base] = $this->makeProposal();

        $this->auth->userId = 2;
        self::assertSame(404, $this->apply($id, $base)->getStatus(), 'Voorstel van een ander is onvindbaar.');

        $this->auth->userId = 1;
        $this->auth->permissions = [];
        $this->expectException(HttpException::class);
        $this->apply($id, $base);
    }

    #[Test]
    public function testApplyDiffValidatesInput(): void
    {
        [$id, $base] = $this->makeProposal();

        self::assertSame(422, $this->apply($id, ['array'])->getStatus());
        self::assertSame(413, $this->apply($id, str_repeat('x', DiffService::MAX_BYTES + 1))->getStatus());
        self::assertSame(404, $this->apply(0, $base)->getStatus());
        self::assertSame(404, $this->apply(99999, $base)->getStatus());
    }

    #[Test]
    public function testApplyDiffRefusesCorruptedStoredDiff(): void
    {
        [$id, $base] = $this->makeProposal();
        // Hash klopt nog, maar de opgeslagen diff past niet meer op de basis.
        $this->pdo->exec("UPDATE cf_ai_messages SET proposal_diff = '--- a\n+++ b\n@@ -1,1 +1,1 @@\n-niet aanwezig\n+iets\n' WHERE id = {$id}");

        $r = $this->apply($id, $base);

        self::assertSame(422, $r->getStatus());
        self::assertSame('diff_mismatch', $this->json($r)['code']);
    }

    #[Test]
    public function testApplyDiffRefusesResultWithNullBytes(): void
    {
        [$id, $base] = $this->makeProposal();
        $diff = "--- a\n+++ b\n@@ -1,3 +1,3 @@\n-<h1>Hoi</h1>\n+x\0y\n <p>tekst</p>\n \n";
        $this->pdo->prepare('UPDATE cf_ai_messages SET proposal_diff = ? WHERE id = ?')->execute([$diff, $id]);

        $r = $this->apply($id, $base);
        // Ofwel de NUL wordt al bij het opslaan afgekapt (422 mismatch), ofwel expliciet geweigerd: nooit 200.
        self::assertNotSame(200, $r->getStatus());
    }
}
