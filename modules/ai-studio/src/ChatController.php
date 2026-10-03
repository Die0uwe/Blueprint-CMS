<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\ContentSanitizer;
use CommunityFusion\Modules\AiStudio\Provider\ProviderException;

/**
 * POST /admin/ai-studio/chat        (SSE-stream)
 * POST /admin/ai-studio/apply-diff
 *
 * Beide routes hangen aan Auth + PermissionMiddleware(aistudio.use) +
 * RateLimitMiddleware (zie routes.php); CSRF wordt hier in de controller
 * gecontroleerd (zelfde patroon als de rest van het CMS), en een extra
 * per-gebruiker limiet beschermt het API-tegoed.
 *
 * AI-output komt NOOIT rechtstreeks in de editor: de chat-route slaat een
 * voorstel op als diff (met hash van de editorinhoud waarop het gebaseerd is),
 * en alleen apply-diff — na een expliciete klik — rekent het resultaat uit,
 * server-side, strikt gevalideerd.
 */
final class ChatController
{
    public const MAX_EDITOR_BYTES = PromptBuilder::MAX_CONTEXT_BYTES;
    private const MAX_REPLY_BYTES = 200000;
    private const HISTORY = 20;

    /** @var callable(): SseWriter */
    private $sseFactory;
    /** @var callable(): void */
    private $terminate;
    private bool $closeSession;
    private string $locale;
    private string $timezone;

    /**
     * @param array{sse?: callable(): SseWriter, terminate?: callable(): void, close_session?: bool, locale?: string, timezone?: string} $runtime
     */
    public function __construct(
        private readonly StudioAuth $auth,
        private readonly ProviderRegistry $registry,
        private readonly ConversationRepository $conversations,
        private readonly MessageRepository $messages,
        private readonly PromptBuilder $prompts,
        private readonly DiffService $diffs,
        private readonly AuditLogger $audit,
        private readonly UserRateLimiter $limiter,
        private readonly SettingsStore $settings,
        array $runtime = [],
    ) {
        $this->sseFactory = $runtime['sse'] ?? static fn (): SseWriter => SseWriter::forOutput();
        // Na een stream mag er niets meer naar de client: Response::send() zou
        // headers/statuscode toevoegen aan een al verzonden body.
        $this->terminate = $runtime['terminate'] ?? static function (): void {
            exit;
        };
        $this->closeSession = $runtime['close_session'] ?? true;
        $this->locale = $runtime['locale'] ?? 'nl';
        $this->timezone = $runtime['timezone'] ?? date_default_timezone_get();
    }

    public function chat(Request $request): Response
    {
        $this->auth->authorize('aistudio.use');
        if (!CsrfGuard::valid($request)) {
            return $this->error('Ongeldige CSRF-token. Herlaad de pagina.', 403, 'csrf');
        }
        $userId = $this->auth->id();
        if ($userId === null) {
            return $this->error('Niet ingelogd.', 401, 'auth');
        }
        if (!$this->limiter->allow('chat:' . $userId, 20, 60)) {
            return $this->error('Te veel berichten. Wacht even.', 429, 'rate');
        }

        $conversationId = $this->intInput($request, 'conversation_id');
        $slug = $this->stringInput($request, 'provider');
        $model = trim($this->stringInput($request, 'model'));
        $text = ContentSanitizer::text($this->stringInput($request, 'message'));

        if ($text === '') {
            return $this->error('Bericht is leeg.', 422, 'validation');
        }
        if (strlen($text) > PromptBuilder::MAX_USER_MESSAGE_BYTES) {
            return $this->error('Bericht is te lang.', 413, 'validation');
        }
        if ($model !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/\-]{0,99}$/', $model) !== 1) {
            return $this->error('Ongeldige modelnaam.', 422, 'validation');
        }

        $editor = $this->editorInput($request);
        if ($editor === false) {
            return $this->error('Editorinhoud is te groot (maximaal ' . intdiv(self::MAX_EDITOR_BYTES, 1000) . ' KB).', 413, 'validation');
        }

        $conversation = $this->conversations->find($conversationId, $userId);
        if ($conversation === null) {
            return $this->error('Gesprek niet gevonden.', 404, 'not_found');
        }

        try {
            $provider = $this->registry->get($slug);
        } catch (ProviderException $e) {
            return $this->error($e->getMessage(), 422, 'provider');
        }
        if ($model === '') {
            $model = $this->registry->defaultModel($slug);
        }

        $history = [];
        foreach ($this->messages->recent($conversationId, self::HISTORY) as $m) {
            $history[] = ['role' => $m['role'], 'content' => $m['content']];
        }
        $prompt = $this->prompts->build($history, $text, $editor, [
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'extra_prompt' => $this->settings->get(SettingsStore::GROUP, 'extra_prompt'),
        ]);

        $this->messages->add($conversationId, 'user', $text);

        // De sessielock vrijgeven: anders blokkeert dit (minutenlange) verzoek
        // elke andere request van dezelfde gebruiker (bv. de apply-diff-klik).
        if ($this->closeSession && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return $this->stream($provider, $prompt, $slug, $model, $conversationId, $userId, $editor['content'] ?? null);
    }

    /**
     * @param list<array{role: string, content: string}> $prompt
     */
    private function stream(
        Provider\ProviderInterface $provider,
        array $prompt,
        string $slug,
        string $model,
        int $conversationId,
        int $userId,
        ?string $base,
    ): Response {
        $sse = ($this->sseFactory)();
        $sse->start();
        $sse->event('start', ['conversation_id' => $conversationId, 'provider' => $slug, 'model' => $model]);

        $reply = '';
        $failed = false;
        $clientGone = false;

        try {
            foreach ($provider->stream($prompt, ['model' => $model]) as $chunk) {
                $reply .= $chunk;
                if (strlen($reply) > self::MAX_REPLY_BYTES) {
                    $sse->event('notice', ['message' => 'Antwoord afgekapt (te lang).']);
                    break;
                }
                if (!$sse->event('delta', ['text' => $chunk])) {
                    $clientGone = true;
                    break;
                }
            }
        } catch (ProviderException $e) {
            $failed = true;
            $sse->event('error', ['message' => $e->getMessage(), 'code' => 'provider']);
        } catch (\Throwable $e) {
            // Nooit het ruwe bericht doorgeven: kan een URL of key bevatten.
            $failed = true;
            error_log('[ai-studio] stream failed: ' . get_class($e));
            $sse->event('error', ['message' => 'Onverwachte fout bij het ophalen van het antwoord.', 'code' => 'internal']);
        }

        if (!$failed && !$clientGone && $reply !== '') {
            $messageId = $this->messages->add($conversationId, 'assistant', $reply, $slug, $model);
            $this->conversations->touch($conversationId, $userId, $slug, $model);

            if ($base !== null) {
                $this->storeProposal($sse, $messageId, $reply, $base);
            }
            $sse->event('done', ['message_id' => $messageId]);
        } elseif (!$failed && !$clientGone) {
            $sse->event('error', ['message' => 'De provider gaf een leeg antwoord.', 'code' => 'empty']);
        }

        ($this->terminate)();
        return new Response('', 200);
    }

    private function storeProposal(SseWriter $sse, int $messageId, string $reply, string $base): void
    {
        $proposal = $this->prompts->extractProposal($reply);
        if ($proposal === null) {
            return;
        }
        try {
            $proposal = $this->diffs->alignProposal($base, $proposal);
            $diff = $this->diffs->generate($base, $proposal);
            if ($diff === '') {
                $sse->event('notice', ['message' => 'Het voorstel is gelijk aan de huidige editorinhoud.']);
                return;
            }
            $stats = $this->diffs->stats($diff);
            $this->messages->setProposal($messageId, $diff, hash('sha256', $base));
            $sse->event('proposal', ['message_id' => $messageId, 'diff' => $diff, 'stats' => $stats]);
        } catch (DiffException $e) {
            $sse->event('notice', ['message' => 'Voorstel kon niet als diff getoond worden: ' . $e->getMessage()]);
        }
    }

    public function applyDiff(Request $request): Response
    {
        $this->auth->authorize('aistudio.use');
        if (!CsrfGuard::valid($request)) {
            return $this->error('Ongeldige CSRF-token. Herlaad de pagina.', 403, 'csrf');
        }
        $userId = $this->auth->id();
        if ($userId === null) {
            return $this->error('Niet ingelogd.', 401, 'auth');
        }
        if (!$this->limiter->allow('apply:' . $userId, 30, 60)) {
            return $this->error('Te veel verzoeken. Wacht even.', 429, 'rate');
        }

        $messageId = $this->intInput($request, 'message_id');
        $base = $request->input('base', '');
        if (!is_string($base)) {
            return $this->error('Ongeldige editorinhoud.', 422, 'validation');
        }
        if (strlen($base) > DiffService::MAX_BYTES) {
            return $this->error('Editorinhoud is te groot.', 413, 'validation');
        }

        $proposal = $this->messages->findOwnedProposal($messageId, $userId);
        if ($proposal === null) {
            return $this->error('Voorstel niet gevonden.', 404, 'not_found');
        }

        // Het voorstel is berekend op één exacte versie van de editor. Is die
        // sindsdien aangepast, dan weigeren we i.p.v. te "proberen".
        if (!hash_equals($proposal['proposal_base_sha256'], hash('sha256', $base))) {
            return $this->error('De editor is gewijzigd sinds dit voorstel. Vraag de AI opnieuw.', 409, 'editor_changed');
        }

        try {
            $content = $this->diffs->apply($base, $proposal['proposal_diff']);
            $stats = $this->diffs->stats($proposal['proposal_diff']);
        } catch (DiffException $e) {
            return $this->error('Toepassen geweigerd: ' . $e->getMessage(), 422, 'diff_mismatch');
        }
        if (str_contains($content, "\0")) {
            return $this->error('Toepassen geweigerd: het resultaat bevat ongeldige tekens.', 422, 'diff_invalid');
        }

        $this->messages->markApplied($messageId);
        // Alleen ids en aantallen: nooit de inhoud van de editor of het voorstel.
        $this->audit->log('aistudio.diff.applied', $userId, $this->auth->username(), [
            'conversation_id' => $proposal['conversation_id'],
            'message_id' => $messageId,
            'added' => $stats['added'],
            'removed' => $stats['removed'],
        ]);

        return Response::json([
            'content' => $content,
            'sha256' => hash('sha256', $content),
            'stats' => $stats,
        ]);
    }

    // ─── invoer ──────────────────────────────────────────────────────────

    private function intInput(Request $request, string $key): int
    {
        $v = $request->input($key, 0);
        return is_int($v) ? $v : (is_string($v) && ctype_digit($v) ? (int) $v : 0);
    }

    private function stringInput(Request $request, string $key): string
    {
        $v = $request->input($key, '');
        return is_string($v) ? $v : '';
    }

    /**
     * @return array{content: string, language: string}|null|false null = geen editorcontext, false = te groot
     */
    private function editorInput(Request $request): array|null|false
    {
        $editor = $request->input('editor');
        if (!is_array($editor) || !isset($editor['content']) || !is_string($editor['content']) || $editor['content'] === '') {
            return null;
        }
        if (strlen($editor['content']) > self::MAX_EDITOR_BYTES) {
            return false;
        }
        $language = is_string($editor['language'] ?? null) ? $editor['language'] : 'text';
        if (!in_array($language, PromptBuilder::LANGUAGES, true)) {
            $language = 'text';
        }
        return ['content' => $editor['content'], 'language' => $language];
    }

    private function error(string $message, int $status, string $code): Response
    {
        return Response::json(['error' => ContentSanitizer::text($message, 300), 'code' => $code], $status);
    }
}
