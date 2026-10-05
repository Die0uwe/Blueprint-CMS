<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\Ollama;

use CommunityFusion\Core\Ai\ThinkFilter;
use CommunityFusion\Core\Cache\CacheManager;

/**
 * OllamaClient
 *
 * PHP client voor de Ollama REST API (http://localhost:11434).
 * Ondersteunt: generate, chat (multi-turn), embeddings, model list.
 *
 * Ollama API docs: https://github.com/ollama/ollama/blob/main/docs/api.md
 *
 * Open WebUI integratie:
 *   Open WebUI (ghcr.io/open-webui/open-webui:v0.9.2) biedt een
 *   ChatGPT-achtige UI bovenop Ollama én ondersteunt de OpenAI-compatibele
 *   API (/api/chat/completions). Dit maakt het mogelijk om vanuit Blueprint CMS
 *   óf direct Ollama te benaderen óf via Open WebUI (voor meer controle,
 *   gebruikersbeheer, model-switching en RAG). Beide endpoints zijn ondersteund.
 */
final class OllamaClient
{
    private const DEFAULT_PORT = 11434;

    public function __construct(
        private readonly string       $host    = 'http://localhost:11434',
        private readonly string       $model   = 'llama3.2',
        private readonly int          $timeout = 30,
        private readonly ?CacheManager $cache  = null,
        private readonly string       $openWebUiUrl = '',
        private readonly string       $openWebUiKey = '',
        private readonly int          $numCtx  = 0,
        private readonly string       $keepAlive = '',
    ) {}

    // ─── GENERATE (enkelvoudige prompt) ───────────────────────────────────

    /**
     * Stuur een prompt en ontvang een antwoord (zonder streaming).
     * Met een Open WebUI-URL gaat het via Open WebUI, anders rechtstreeks naar Ollama.
     *
     * @throws \RuntimeException als de AI niet bereikbaar is
     */
    public function generate(
        string  $prompt,
        string  $systemPrompt = '',
        ?string $model        = null,
        array   $options      = [],
    ): string {
        if ($this->usesOpenWebUi()) {
            return $this->chat([['role' => 'user', 'content' => $prompt]], $systemPrompt, $model, $options);
        }

        $payload = [
            'model'  => $model ?? $this->model,
            'prompt' => $prompt,
            'stream' => false,
        ];
        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }
        $options = $this->withDefaultOptions($options);
        if ($options !== []) {
            $payload['options'] = $options;
        }
        if ($this->keepAlive !== '') {
            $payload['keep_alive'] = $this->keepAlive;
        }

        $response = $this->post('/api/generate', $payload);
        return ThinkFilter::strip((string) ($response['response'] ?? ''));
    }

    // ─── CHAT (multi-turn conversatie) ────────────────────────────────────

    /**
     * Chat met gespreksgeschiedenis.
     *
     * $messages = [
     *   ['role' => 'user',      'content' => 'Hallo!'],
     *   ['role' => 'assistant', 'content' => 'Hoi! Hoe kan ik helpen?'],
     *   ['role' => 'user',      'content' => 'Wat is WoW?'],
     * ]
     *
     * Het denkwerk van redeneermodellen (DeepSeek-R1 e.d.) wordt uit het antwoord gehaald.
     */
    public function chat(
        array   $messages,
        string  $systemPrompt = '',
        ?string $model        = null,
        array   $options      = [],
    ): string {
        if ($systemPrompt !== '') {
            array_unshift($messages, ['role' => 'system', 'content' => $systemPrompt]);
        }
        if ($this->usesOpenWebUi()) {
            return $this->chatViaOpenWebUI($messages, '', $model, $options);
        }
        return $this->chatDirect($messages, $model, $options);
    }

    private function chatDirect(array $messages, ?string $model, array $options = []): string
    {
        $payload = [
            'model'    => $model ?? $this->model,
            'messages' => $messages,
            'stream'   => false,
        ];
        $options = $this->withDefaultOptions($options);
        if ($options !== []) {
            $payload['options'] = $options; // temperature, top_p, num_ctx, …
        }
        if ($this->keepAlive !== '') {
            $payload['keep_alive'] = $this->keepAlive;
        }

        $response = $this->post('/api/chat', $payload);
        // Nieuwere Ollama-versies leveren het denkwerk apart ('thinking'); oudere zetten het als <think> in 'content'.
        return ThinkFilter::strip((string) ($response['message']['content'] ?? ''));
    }

    // ─── EMBEDDINGS ───────────────────────────────────────────────────────

    /**
     * Genereer een embedding vector voor tekst.
     * Handig voor semantisch zoeken.
     *
     * @return float[]
     */
    public function embed(string $text, ?string $model = null): array
    {
        $response = $this->post('/api/embed', [
            'model' => $model ?? $this->model,
            'input' => $text,
        ]);
        return $response['embeddings'][0] ?? [];
    }

    // ─── MODEL MANAGEMENT ─────────────────────────────────────────────────

    /** Gebruikt de beheerder een Open WebUI-adres (bv. via een Cloudflare Tunnel)? */
    public function usesOpenWebUi(): bool
    {
        return $this->openWebUiUrl !== '';
    }

    /**
     * Geef een lijst van beschikbare modellen terug (zowel via Ollama als via Open WebUI).
     *
     * @return array<int, array{name: string, size: int, modified_at: string}>
     */
    public function listModels(): array
    {
        try {
            if ($this->usesOpenWebUi()) {
                $data = $this->getJson(rtrim($this->openWebUiUrl, '/') . '/api/models', $this->webUiHeaders());
                $out  = [];
                foreach ((array) ($data['data'] ?? $data['models'] ?? []) as $m) {
                    $name = is_array($m) ? (string) ($m['id'] ?? $m['name'] ?? '') : '';
                    if ($name !== '') {
                        $out[] = ['name' => $name, 'size' => (int) ($m['size'] ?? 0), 'modified_at' => (string) ($m['modified_at'] ?? '')];
                    }
                }
                return $out;
            }
            $response = $this->get('/api/tags');
            return $response['models'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Controleer of de AI bereikbaar is (Open WebUI als dat is ingesteld, anders Ollama).
     */
    public function isAvailable(): bool
    {
        try {
            if ($this->usesOpenWebUi()) {
                return $this->probe(rtrim($this->openWebUiUrl, '/') . '/api/models', $this->webUiHeaders())['status'] === 200;
            }
            return $this->probe(rtrim($this->host, '/') . '/api/tags')['status'] === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Stap-voor-stap verbindingstest met uitleg in gewone taal (voor de beheerpagina).
     *
     * @return array{ok:bool, steps:list<array{ok:bool,label:string,hint:string}>, reply:string, ms:int, models:list<string>}
     */
    public function diagnose(?string $model = null): array
    {
        $steps = [];
        $res   = ['ok' => false, 'steps' => &$steps, 'reply' => '', 'ms' => 0, 'models' => []];
        $web   = $this->usesOpenWebUi();
        $base  = $web ? rtrim($this->openWebUiUrl, '/') : rtrim($this->host, '/');
        $url   = $base . ($web ? '/api/models' : '/api/tags');

        $p = $this->probe($url, $web ? $this->webUiHeaders() : []);
        if ($p['status'] !== 200) {
            $steps[] = ['ok' => false, 'label' => 'Verbinding met ' . $base, 'hint' => self::hintForStatus($p['status'], $p['error'], $web)];
            return $res;
        }
        $steps[] = ['ok' => true, 'label' => 'Verbinding met ' . $base, 'hint' => ''];

        $models = array_values(array_filter(array_map(static fn($m) => (string) ($m['name'] ?? ''), $this->listModels())));
        $res['models'] = $models;
        $steps[] = ['ok' => $models !== [], 'label' => 'Modellen gevonden: ' . count($models),
            'hint' => $models === [] ? 'Er is nog geen model. Haal er een op, bijvoorbeeld: ollama pull deepseek-r1:8b' : ''];

        $model = $model !== null && $model !== '' ? $model : $this->model;
        if ($models !== [] && !in_array($model, $models, true)) {
            $steps[] = ['ok' => false, 'label' => 'Model "' . $model . '" is niet geïnstalleerd',
                'hint' => 'Kies een model uit de lijst, of haal het op met: ollama pull ' . $model];
            return $res;
        }

        $t = microtime(true);
        try {
            $reply = $this->chat([['role' => 'user', 'content' => 'Zeg in één korte zin hallo in het Nederlands.']], '', $model, ['num_predict' => 120]);
            $res['ms']    = (int) round((microtime(true) - $t) * 1000);
            $res['reply'] = mb_substr($reply, 0, 300);
            $steps[] = ['ok' => $reply !== '', 'label' => 'Testvraag aan "' . $model . '"',
                'hint' => $reply === '' ? 'Het model gaf een leeg antwoord (bij redeneermodellen: verhoog de timeout of kies een kleiner model).' : ''];
            $res['ok'] = $reply !== '';
        } catch (\Throwable $e) {
            $steps[] = ['ok' => false, 'label' => 'Testvraag aan "' . $model . '"', 'hint' => $e->getMessage()];
        }
        return $res;
    }

    /** Uitleg bij een mislukte verbinding (in gewone taal). */
    public static function hintForStatus(int $status, string $error = '', bool $webUi = false): string
    {
        return match (true) {
            $status === 0 && stripos($error, 'ssl') !== false => 'SSL-fout. Gebruik https:// en zet SSL/TLS bij Cloudflare op "Full".',
            $status === 0 && stripos($error, 'timed out') !== false => 'Geen antwoord op tijd. Staat de AI-server (of de tunnel) aan?',
            $status === 0 => 'Niet bereikbaar. Het adres klopt niet, of de server/tunnel staat uit. Je webhost kan "localhost" van jouw pc niet bereiken: gebruik een openbaar https-adres (Cloudflare Tunnel).',
            $status === 401 || $status === 403 => $webUi
                ? 'Geweigerd (' . $status . '). Controleer de Open WebUI API-sleutel, of een Cloudflare-regel die verkeer van je webhost blokkeert (Bot Fight Mode / Access).'
                : 'Geweigerd (' . $status . '). Een proxy of Cloudflare-regel blokkeert de aanroep.',
            $status === 404 => 'Niet gevonden (404). Controleer het adres: Ollama = http://host:11434, Open WebUI = het hoofdadres zonder /api.',
            $status === 502, $status === 503 || ($status >= 520 && $status <= 523) => 'De tunnel of Docker staat uit (' . $status . ').',
            $status === 524 => 'Cloudflare wacht maximaal 100 s. Kies een kleiner model of laat het model geladen staan (OLLAMA_KEEP_ALIVE=24h).',
            default => 'Onverwacht antwoord (HTTP ' . $status . ').',
        };
    }

    // ─── OPEN WEBUI INTEGRATIE ────────────────────────────────────────────

    /**
     * Stuur een chat request via Open WebUI (OpenAI-compatible API).
     * Open WebUI ondersteunt:
     *   POST /api/chat/completions (OpenAI-compatible)
     *   GET  /api/models           (beschikbare modellen)
     *
     * Valt terug op directe Ollama als Open WebUI niet antwoordt én de Ollama-host
     * ook echt bereikbaar is; anders volgt een duidelijke fout.
     */
    public function chatViaOpenWebUI(
        array   $messages,
        string  $systemPrompt = '',
        ?string $model        = null,
        array   $options      = [],
    ): string {
        if (!$this->usesOpenWebUi()) {
            return $this->chat($messages, $systemPrompt, $model, $options);
        }
        if ($systemPrompt !== '') {
            array_unshift($messages, ['role' => 'system', 'content' => $systemPrompt]);
        }

        $payload = [
            'model'    => $model ?? $this->model,
            'messages' => $messages,
            'stream'   => false,
        ];
        foreach (['temperature', 'top_p'] as $k) {
            if (isset($options[$k])) {
                $payload[$k] = $options[$k];
            }
        }
        if (isset($options['num_predict'])) {
            $payload['max_tokens'] = (int) $options['num_predict'];
        }

        $url = rtrim($this->openWebUiUrl, '/') . '/api/chat/completions';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $this->webUiHeaders()),
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($status !== 200 || !is_string($body)) {
            if ($this->host !== '' && $this->isDirectReachable()) {
                return $this->chatDirect($messages, $model, $options);
            }
            throw new \RuntimeException('Open WebUI: ' . self::hintForStatus($status, $error, true));
        }

        $data = json_decode($body, true);
        return ThinkFilter::strip((string) ($data['choices'][0]['message']['content'] ?? ''));
    }

    private function isDirectReachable(): bool
    {
        return $this->probe(rtrim($this->host, '/') . '/api/tags')['status'] === 200;
    }

    /** @return list<string> */
    private function webUiHeaders(): array
    {
        return $this->openWebUiKey !== '' ? ['Authorization: Bearer ' . $this->openWebUiKey] : [];
    }

    /** @param array<string, mixed> $options */
    private function withDefaultOptions(array $options): array
    {
        if ($this->numCtx > 0 && !isset($options['num_ctx'])) {
            $options['num_ctx'] = $this->numCtx;
        }
        return $options;
    }

    // ─── BLUEPRINT CMS SPECIFIEKE AI FUNCTIES ────────────────────────────

    /**
     * Genereer een samenvatting van een nieuwsartikel.
     */
    public function summarizeNews(string $content, string $title = '', int $maxWords = 80): string
    {
        $cacheKey = 'ollama.summary.' . md5($content);
        if ($this->cache) {
            $cached = $this->cache->get($cacheKey);
            if ($cached) return $cached;
        }

        $prompt = "Maak een korte samenvatting van maximaal {$maxWords} woorden van dit nieuwsartikel.\n\n";
        if ($title) $prompt .= "Titel: {$title}\n\n";
        $prompt .= "Artikel:\n" . substr($content, 0, 3000) . "\n\nSamenvatting:";

        $result = $this->generate($prompt, 'Je bent een redacteur die neutrale, beknopte samenvattingen schrijft. Antwoord alleen in het Nederlands.');

        if ($this->cache && $result) {
            $this->cache->set($cacheKey, $result, 3600);
        }

        return $result;
    }

    /**
     * Beoordeel een guild aanmelding en geef een eerste analyse.
     */
    public function analyzeGuildApplication(array $application): string
    {
        $prompt = <<<PROMPT
Analyseer deze guild aanmelding voor een World of Warcraft guild.
Geef een korte beoordeling (max 3 zinnen) op basis van:
- Klasse en specialisatie
- Item level
- Motivatie
- Ervaring

Aanmelding:
Karakter: {$application['character_name']}
Klasse/Spec: {$application['class']} - {$application['spec']}
Item level: {$application['item_level']}
Motivatie: {$application['about']}
Ervaring: {$application['experience']}

Geef alleen de beoordeling, geen extra uitleg.
PROMPT;

        return $this->generate(
            $prompt,
            'Je bent een ervaren WoW guild officer die aanmeldingen beoordeelt. Wees eerlijk maar vriendelijk. Antwoord in het Nederlands.'
        );
    }

    /**
     * Genereer community chatbot antwoord.
     * Contextueel: weet van de guild, het spel, en de community.
     */
    public function communityChat(
        array  $messages,
        string $guildName   = '',
        string $gameName    = 'World of Warcraft',
        string $customPrompt = '',
    ): string {
        $systemPrompt = "Je bent een vriendelijke community bot voor {$guildName}. ";
        $systemPrompt .= "De community speelt voornamelijk {$gameName}. ";
        $systemPrompt .= "Beantwoord vragen over de guild, het spel en de community in het Nederlands. ";
        $systemPrompt .= "Wees behulpzaam, positief en beknopt. Gebruik geen markdown in je antwoorden.";
        if (trim($customPrompt) !== '') {
            $systemPrompt .= ' ' . mb_substr(trim($customPrompt), 0, 1000);
        }

        return $this->chat($messages, $systemPrompt);
    }

    /**
     * Genereer recruitment post voor de guild.
     */
    public function generateRecruitmentPost(array $guildInfo): string
    {
        $prompt = "Schrijf een aantrekkelijke guild recruitment post voor Discord of Reddit. ";
        $prompt .= "Guild naam: " . ($guildInfo['name'] ?? 'Onbekend') . ". ";
        $prompt .= "Zoekt: " . ($guildInfo['looking_for'] ?? 'nieuwe leden') . ". ";
        $prompt .= "Raid schema: " . ($guildInfo['schedule'] ?? 'nog te bepalen') . ". ";
        $prompt .= "Extra info: " . ($guildInfo['extra'] ?? '') . ". ";
        $prompt .= "Maximaal 150 woorden. Enthousiastisch en uitnodigend.";

        return $this->generate($prompt, 'Je bent een copywriter voor gaming communities. Schrijf in het Nederlands.');
    }

    // ─── HTTP HELPERS ─────────────────────────────────────────────────────

    private function post(string $path, array $data): array
    {
        $url = rtrim($this->host, '/') . $path;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error || $status === 0) {
            throw new \RuntimeException("Ollama niet bereikbaar op {$this->host}: {$error}");
        }

        if ($status !== 200) {
            throw new \RuntimeException("Ollama API fout HTTP {$status}: " . substr($body, 0, 300));
        }

        return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }

    private function get(string $path): array
    {
        return $this->getJson(rtrim($this->host, '/') . $path);
    }

    /** @param list<string> $headers */
    private function getJson(string $url, array $headers = []): array
    {
        $p = $this->probe($url, $headers, true);
        if ($p['status'] !== 200) {
            throw new \RuntimeException("GET {$url} mislukt: HTTP {$p['status']}");
        }
        return json_decode($p['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string> $headers
     * @return array{status:int, error:string, body:string}
     */
    private function probe(string $url, array $headers = [], bool $withBody = false): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $withBody ? 8 : 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);
        return ['status' => $status, 'error' => $error, 'body' => is_string($body) ? $body : ''];
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: OllamaClient.php | Role: Core | Version: 1.1.0               ║
// ║  Created: 2026-06-06 | Status: New                                  ║
// ║  Notes: Ollama REST API + Open WebUI v0.9.2 integratie              ║
// ║  Open WebUI: ghcr.io/open-webui/open-webui:v0.9.2-cuda             ║
// ║  Created by Dieouwe — www.slayeralliance.com                         ║
// ╚══════════════════════════════════════════════════════════════════════╝
