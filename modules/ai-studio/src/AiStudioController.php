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
use CommunityFusion\Core\Security\CsrfProtection;

/**
 * Admin-routes van AI Studio: hoofdscherm, instellingen, gesprekken en assets.
 *
 * GET  /admin/ai-studio                      (aistudio.use)
 * GET  /admin/ai-studio/settings             (aistudio.admin)
 * POST /admin/ai-studio/settings             (aistudio.admin + CSRF + rate limit)
 * POST /admin/ai-studio/conversation/new     (aistudio.use + CSRF + rate limit)
 * POST /admin/ai-studio/conversation/delete  (aistudio.use + CSRF + rate limit)
 * GET  /admin/ai-studio/conversation/{id}    (berichten als JSON, alleen eigen gesprekken)
 * GET  /admin/ai-studio/assets/{file}        (css/js van de module; staan buiten de webroot)
 */
final class AiStudioController
{
    private const CSP = "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; "
        . "base-uri 'none'; frame-ancestors 'none'; frame-src 'none'; object-src 'none'";

    /** @var array<string, string> */
    private const ASSETS = [
        'studio.css' => 'text/css; charset=utf-8',
        'studio.js' => 'text/javascript; charset=utf-8',
        'editor.js' => 'text/javascript; charset=utf-8',
        'sse.js' => 'text/javascript; charset=utf-8',
    ];

    public function __construct(
        private readonly StudioAuth $auth,
        private readonly ProviderRegistry $registry,
        private readonly ConversationRepository $conversations,
        private readonly MessageRepository $messages,
        private readonly SettingsStore $settings,
        private readonly SchemaMigrator $migrator,
        private readonly AuditLogger $audit,
        private readonly UserRateLimiter $limiter,
        private readonly string $moduleDir,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->auth->authorize('aistudio.use');
        $userId = $this->auth->id();
        if ($userId === null) {
            return Response::redirect('/login');
        }
        if (($failure = $this->ensureSchema()) !== null) {
            return $failure;
        }

        $providers = [];
        foreach ($this->registry->configuredSlugs() as $slug) {
            $providers[] = ['slug' => $slug, 'label' => $this->registry->label($slug), 'model' => $this->registry->defaultModel($slug)];
        }
        $defaultProvider = $this->settings->get(SettingsStore::GROUP, 'default_provider', 'ollama');

        return $this->render('index', [
            'conversations' => $this->conversations->listForUser($userId),
            'providers' => $providers,
            'defaultProvider' => $defaultProvider,
            'csrf' => CsrfProtection::getToken(),
            'canAdmin' => $this->auth->can('aistudio.admin'),
            'assetVersion' => $this->moduleVersion(),
        ]);
    }

    public function conversation(Request $request): Response
    {
        $this->auth->authorize('aistudio.use');
        $userId = $this->auth->id();
        $id = (int) $request->param('id', 0);
        if ($userId === null || $this->conversations->find($id, $userId) === null) {
            return Response::json(['error' => 'Gesprek niet gevonden.'], 404);
        }
        return Response::json(['messages' => $this->messages->recent($id, 100)]);
    }

    public function newConversation(Request $request): Response
    {
        $this->auth->authorize('aistudio.use');
        if (!CsrfGuard::valid($request)) {
            return Response::json(['error' => 'Ongeldige CSRF-token.'], 403);
        }
        $userId = $this->auth->id();
        if ($userId === null) {
            return Response::json(['error' => 'Niet ingelogd.'], 401);
        }
        if (!$this->limiter->allow('conv:' . $userId, 30, 60)) {
            return Response::json(['error' => 'Te veel verzoeken.'], 429);
        }

        $title = $request->input('title', '');
        $slug = $request->input('provider', '');
        $slug = is_string($slug) && $this->registry->isConfigured($slug) ? $slug : '';

        $id = $this->conversations->create($userId, is_string($title) ? $title : '', $slug, $slug !== '' ? $this->registry->defaultModel($slug) : '');
        $this->audit->log('aistudio.conversation.created', $userId, $this->auth->username(), ['conversation_id' => $id]);

        $row = $this->conversations->find($id, $userId);
        return Response::json(['id' => $id, 'title' => $row['title'] ?? 'Nieuw gesprek'], 201);
    }

    public function deleteConversation(Request $request): Response
    {
        $this->auth->authorize('aistudio.use');
        if (!CsrfGuard::valid($request)) {
            return Response::json(['error' => 'Ongeldige CSRF-token.'], 403);
        }
        $userId = $this->auth->id();
        if ($userId === null) {
            return Response::json(['error' => 'Niet ingelogd.'], 401);
        }
        if (!$this->limiter->allow('conv:' . $userId, 30, 60)) {
            return Response::json(['error' => 'Te veel verzoeken.'], 429);
        }

        $raw = $request->input('id', 0);
        $id = is_int($raw) ? $raw : (is_string($raw) && ctype_digit($raw) ? (int) $raw : 0);
        if (!$this->conversations->delete($id, $userId)) {
            return Response::json(['error' => 'Gesprek niet gevonden.'], 404);
        }
        $this->audit->log('aistudio.conversation.deleted', $userId, $this->auth->username(), ['conversation_id' => $id]);
        return Response::json(['ok' => true]);
    }

    // ─── instellingen ────────────────────────────────────────────────────

    public function settings(Request $request): Response
    {
        $this->auth->authorize('aistudio.admin');
        return $this->renderSettings([], $request->query('saved', '') === '1');
    }

    public function saveSettings(Request $request): Response
    {
        $this->auth->authorize('aistudio.admin');
        $userId = $this->auth->id();
        if (!CsrfGuard::valid($request)) {
            return $this->renderSettings(['Ongeldige CSRF-token. Herlaad de pagina en probeer opnieuw.'], false, 403);
        }
        if ($userId === null || !$this->limiter->allow('settings:' . $userId, 10, 60)) {
            return $this->renderSettings(['Te veel verzoeken. Wacht even.'], false, 429);
        }

        $input = $request->input('f', []);
        $input = is_array($input) ? $input : [];
        $clear = $request->input('clear', []);
        $clear = is_array($clear) ? $clear : [];

        $errors = [];
        $changedKeys = [];

        foreach ($this->schema() as $field) {
            $key = $field['key'];
            $value = $input[$key] ?? '';
            $value = is_string($value) ? trim($value) : '';

            if ($field['type'] === 'encrypted') {
                $slug = $field['provider'] ?? '';
                if (!$this->registry->exists($slug)) {
                    continue;
                }
                if (isset($clear[$key]) && $clear[$key] === '1') {
                    $this->registry->clearKey($slug);
                    $changedKeys[] = ['provider' => $slug, 'action' => 'removed'];
                    continue;
                }
                if ($value === '') {
                    continue; // leeg = bestaande key behouden
                }
                if (preg_match('/^[\x21-\x7E]{8,512}$/', $value) !== 1) {
                    $errors[] = $this->registry->label($slug) . ': de key heeft een ongeldig formaat.';
                    continue;
                }
                if (!$this->registry->validateKey($slug, $value)) {
                    $errors[] = $this->registry->label($slug) . ': de provider accepteerde deze key niet (of is onbereikbaar). Niet opgeslagen.';
                    continue;
                }
                $this->registry->storeKey($slug, $value);
                $changedKeys[] = ['provider' => $slug, 'action' => 'updated'];
                continue;
            }

            if (str_ends_with($key, '.model')) {
                if ($value === '') {
                    $this->settings->set(SettingsStore::GROUP, $key, '');
                } elseif (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,99}$/', $value) === 1) {
                    $this->settings->set(SettingsStore::GROUP, $key, $value);
                } else {
                    $errors[] = $field['label'] . ': ongeldige modelnaam.';
                }
            } elseif ($key === 'default_provider') {
                if ($this->registry->exists($value)) {
                    $this->settings->set(SettingsStore::GROUP, $key, $value);
                }
            } elseif ($key === 'extra_prompt') {
                $this->settings->set(SettingsStore::GROUP, $key, ContentSanitizer::text($value, 1000));
            }
        }

        foreach ($changedKeys as $change) {
            // Alleen WELKE provider en WAT er gebeurde; nooit de key zelf.
            $this->audit->log('aistudio.settings.key_' . $change['action'], $userId, $this->auth->username(), ['provider' => $change['provider']]);
        }

        if ($errors !== []) {
            return $this->renderSettings($errors, $changedKeys !== [], 422);
        }
        return Response::redirect('/admin/ai-studio/settings?saved=1');
    }

    /**
     * @param list<string> $errors
     */
    private function renderSettings(array $errors, bool $saved, int $status = 200): Response
    {
        $fields = [];
        foreach ($this->schema() as $field) {
            $isSecret = $field['type'] === 'encrypted';
            $fields[] = [
                'key' => $field['key'],
                'label' => $field['label'],
                'secret' => $isSecret,
                // Voor geheimen alleen "is er een key?"; de waarde zelf verlaat de server nooit.
                'isSet' => $isSecret ? $this->registry->hasKey($field['provider'] ?? '') : false,
                'value' => $isSecret ? '' : $this->settings->get(SettingsStore::GROUP, $field['key'], $field['default'] ?? ''),
                'default' => $field['default'] ?? '',
            ];
        }
        return $this->render('settings', [
            'fields' => $fields,
            'errors' => $errors,
            'saved' => $saved,
            'csrf' => CsrfProtection::getToken(),
        ], $status);
    }

    // ─── assets ──────────────────────────────────────────────────────────

    public function asset(Request $request): Response
    {
        $this->auth->authorize('aistudio.use');
        $file = $request->param('file', '');
        if (!is_string($file) || !isset(self::ASSETS[$file])) {
            return new Response('Niet gevonden.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $body = file_get_contents($this->moduleDir . '/assets/' . $file);
        if ($body === false) {
            return new Response('Niet gevonden.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return new Response($body, 200, [
            'Content-Type' => self::ASSETS[$file],
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    // ─── intern ──────────────────────────────────────────────────────────

    private function ensureSchema(): ?Response
    {
        try {
            $this->migrator->ensure();
        } catch (\Throwable $e) {
            error_log('[ai-studio] migratie mislukt: ' . get_class($e));
            return Response::html(
                '<h1>AI Studio</h1><p>De database-migratie is mislukt. Draai <code>php cli/console.php ai-studio:migrate</code> en kijk in de serverlog.</p>',
                500
            );
        }
        return null;
    }

    private function moduleVersion(): string
    {
        $json = file_get_contents($this->moduleDir . '/module.json');
        $manifest = $json === false ? null : json_decode($json, true);
        $version = is_array($manifest) ? ($manifest['version'] ?? '1') : '1';
        return is_string($version) ? $version : '1';
    }

    /**
     * @return list<array{key: string, label: string, type: string, provider?: string, default?: string}>
     */
    private function schema(): array
    {
        $json = file_get_contents($this->moduleDir . '/module.json');
        $manifest = $json === false ? null : json_decode($json, true);
        $schema = is_array($manifest) && is_array($manifest['settings_schema'] ?? null) ? $manifest['settings_schema'] : [];

        $out = [];
        foreach ($schema as $f) {
            if (!is_array($f) || !is_string($f['key'] ?? null) || !is_string($f['label'] ?? null) || !is_string($f['type'] ?? null)) {
                continue;
            }
            $entry = ['key' => $f['key'], 'label' => $f['label'], 'type' => $f['type']];
            if (is_string($f['provider'] ?? null)) {
                $entry['provider'] = $f['provider'];
            }
            if (is_string($f['default'] ?? null)) {
                $entry['default'] = $f['default'];
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(string $view, array $vars, int $status = 200): Response
    {
        $file = $this->moduleDir . '/views/' . $view . '.php';
        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                include $__file;
            })($file, $vars);
            $html = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        // CSP: alleen eigen scripts (geen inline script), geen frames, geen externe verbindingen.
        return Response::html($html, $status)
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('Cache-Control', 'no-store');
    }
}
