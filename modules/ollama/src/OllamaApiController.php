<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\Ollama;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;

final class OllamaApiController
{
    private OllamaClient $client;
    /** @var array<string, string> */
    private array $cfg;

    public function __construct(Connection $db, CacheManager $cache)
    {
        $this->cfg    = OllamaConfig::load($db);
        $this->client = OllamaConfig::client($this->cfg, $cache);
    }

    /**
     * POST /api/ollama/chat
     * Body: { "messages": [{"role":"user","content":"..."}] }
     */
    public function chat(Request $request): Response
    {
        $messages = $request->input('messages', []);
        if (empty($messages) || !is_array($messages)) {
            return Response::json(['error' => 'messages array vereist.'], 422);
        }

        // Sanitize messages
        $clean = [];
        // Alleen user/assistant van de client: een 'system'-bericht zou de ingestelde systeemprompt kunnen
        // overrulen (prompt-injectie). Maximaal de laatste 20 berichten.
        foreach (array_slice(array_values($messages), -20) as $m) {
            if (is_array($m) && isset($m['role'], $m['content']) && is_string($m['content'])
                && in_array($m['role'], ['user', 'assistant'], true)) {
                $clean[] = ['role' => $m['role'], 'content' => mb_substr($m['content'], 0, 2000)];
            }
        }

        if (empty($clean)) {
            return Response::json(['error' => 'Geen geldige berichten.'], 422);
        }

        try {
            $reply = $this->client->communityChat($clean, $this->cfg['guild_name'] ?? '', 'World of Warcraft', $this->cfg['system_prompt'] ?? '');
        } catch (\Throwable $e) {
            // Details (host, upstream-fout) alleen in het serverlog en de admin-test, nooit naar een bezoeker.
            error_log('[ollama] chat mislukt: ' . $e->getMessage());
            return Response::json(['error' => 'De AI is tijdelijk niet beschikbaar.'], 503);
        }
        if (trim($reply) === '') {
            return Response::json(['error' => 'Het model gaf geen antwoord. Probeer het nog eens.'], 502);
        }
        return Response::json(['reply' => $reply, 'ok' => true]);
    }

    /**
     * POST /api/ollama/summarize
     * Body: { "content": "...", "title": "..." }
     */
    public function summarize(Request $request): Response
    {
        $content = $request->input('content', '');
        $title   = $request->input('title', '');

        if (empty($content)) {
            return Response::json(['error' => 'content is verplicht.'], 422);
        }

        try {
            $summary = $this->client->summarizeNews($content, $title);
            return Response::json(['summary' => $summary, 'ok' => true]);
        } catch (\Throwable $e) {
            error_log('[ollama] samenvatting mislukt: ' . $e->getMessage());
            return Response::json(['error' => 'Samenvatting mislukt.'], 503);
        }
    }

    /**
     * GET /api/ollama/models — beschikbare Ollama modellen
     */
    public function models(Request $request): Response
    {
        if (!$this->client->isAvailable()) {
            return Response::json(['available' => false, 'models' => []]);
        }

        $models = $this->client->listModels();
        return Response::json([
            'available' => true,
            'models'    => array_map(fn($m) => [
                'name'        => $m['name'] ?? '',
                'size'        => $m['size'] ?? 0,
                'modified_at' => $m['modified_at'] ?? '',
            ], $models),
        ]);
    }
}
