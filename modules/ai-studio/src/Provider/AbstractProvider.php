<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Provider;

use CommunityFusion\Modules\AiStudio\Http\HttpRequest;
use CommunityFusion\Modules\AiStudio\Http\HttpTransportInterface;
use CommunityFusion\Modules\AiStudio\Http\SseParser;
use CommunityFusion\Modules\AiStudio\Http\TransportException;

/**
 * Gedeelde bouwstenen: berichtnormalisatie, modelvalidatie en de
 * OpenAI-compatibele SSE-stroom (OpenAI, DeepSeek, Mistral, Open WebUI).
 */
abstract class AbstractProvider implements ProviderInterface
{
    protected const MAX_TOKENS_DEFAULT = 4096;

    public function __construct(
        protected readonly HttpTransportInterface $http,
        protected readonly string $apiKey = '',
        protected readonly string $defaultModel = '',
    ) {
    }

    /**
     * Rollen whitelisten, lege berichten weglaten, niets anders doorlaten.
     *
     * @param list<array{role: string, content: string}> $messages
     * @return list<array{role: string, content: string}>
     */
    protected function normalize(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $role = $m['role'];
            if (!in_array($role, ['system', 'user', 'assistant'], true) || trim($m['content']) === '') {
                continue;
            }
            $out[] = ['role' => $role, 'content' => $m['content']];
        }
        return $out;
    }

    /**
     * Voeg opeenvolgende berichten met dezelfde rol samen (Anthropic en Google
     * eisen afwisselende beurten).
     *
     * @param list<array{role: string, content: string}> $messages
     * @return list<array{role: string, content: string}>
     */
    protected function mergeSameRole(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]['role'] === $m['role']) {
                $out[$last] = ['role' => $m['role'], 'content' => $out[$last]['content'] . "\n\n" . $m['content']];
            } else {
                $out[] = $m;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function resolveModel(array $options): string
    {
        $model = $options['model'] ?? '';
        if (!is_string($model) || $model === '') {
            $model = $this->defaultModel;
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/\-]{0,99}$/', $model) !== 1 || str_contains($model, '..')) {
            throw new ProviderException($this->label() . ': ongeldige modelnaam.');
        }
        return $model;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function maxTokens(array $options): int
    {
        $n = $options['max_tokens'] ?? self::MAX_TOKENS_DEFAULT;
        return is_int($n) ? max(16, min($n, 32000)) : self::MAX_TOKENS_DEFAULT;
    }

    protected function requireKey(): void
    {
        if ($this->apiKey === '') {
            throw new ProviderException($this->label() . ': geen API key ingesteld.');
        }
    }

    /**
     * @return list<string>
     */
    protected function secrets(): array
    {
        return $this->apiKey === '' ? [] : [$this->apiKey];
    }

    /**
     * Lees een OpenAI-compatibele chat-completions-stream en geef de tekst-delta's.
     *
     * @return iterable<string>
     */
    protected function streamOpenAiSse(HttpRequest $request): iterable
    {
        $parser = new SseParser();
        try {
            foreach ($this->http->stream($request) as $chunk) {
                foreach ($parser->feed($chunk) as $event) {
                    $done = false;
                    foreach ($this->handleOpenAiEvent($event['data'], $done) as $text) {
                        yield $text;
                    }
                    if ($done) {
                        return;
                    }
                }
            }
            foreach ($parser->finish() as $event) {
                $done = false;
                foreach ($this->handleOpenAiEvent($event['data'], $done) as $text) {
                    yield $text;
                }
            }
        } catch (TransportException $e) {
            throw ProviderException::fromTransport($this->label(), $e, $this->secrets());
        }
    }

    /**
     * @return list<string>
     */
    private function handleOpenAiEvent(string $data, bool &$done): array
    {
        if (trim($data) === '[DONE]') {
            $done = true;
            return [];
        }
        $json = json_decode($data, true);
        if (!is_array($json)) {
            return [];
        }
        if (isset($json['error'])) {
            $err = $json['error'];
            $msg = is_array($err) && isset($err['message']) && is_string($err['message']) ? $err['message'] : 'onbekende fout';
            throw ProviderException::remote($this->label(), $msg, $this->secrets());
        }
        $choices = $json['choices'] ?? null;
        $delta = is_array($choices) && isset($choices[0]) && is_array($choices[0]) ? ($choices[0]['delta'] ?? null) : null;
        $text = is_array($delta) ? ($delta['content'] ?? null) : null;
        return is_string($text) && $text !== '' ? [$text] : [];
    }
}
