<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Provider;

use CommunityFusion\Modules\AiStudio\Http\HttpRequest;
use CommunityFusion\Modules\AiStudio\Http\SseParser;
use CommunityFusion\Modules\AiStudio\Http\TransportException;

/**
 * Google Gemini (generativelanguage API). De key gaat in de header
 * x-goog-api-key, bewust NIET in de query-string: URL's belanden in
 * access-logs, proxy-logs en foutmeldingen.
 */
final class GoogleProvider extends AbstractProvider
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function slug(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google';
    }

    public function stream(array $messages, array $options): iterable
    {
        $this->requireKey();

        $model = $this->resolveModel($options);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,79}$/', $model) !== 1) {
            throw new ProviderException('Google: ongeldige modelnaam.');
        }

        $system = [];
        $turns = [];
        foreach ($this->normalize($messages) as $m) {
            if ($m['role'] === 'system') {
                $system[] = $m['content'];
            } else {
                $turns[] = $m;
            }
        }
        $contents = [];
        foreach ($this->mergeSameRole($turns) as $m) {
            $contents[] = [
                'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $m['content']]],
            ];
        }
        if ($contents === []) {
            throw new ProviderException('Google: geen bericht om te versturen.');
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => $this->maxTokens($options)],
        ];
        if ($system !== []) {
            $payload['systemInstruction'] = ['parts' => [['text' => implode("\n\n", $system)]]];
        }

        $request = new HttpRequest(
            'POST',
            self::BASE . '/models/' . $model . ':streamGenerateContent?alt=sse',
            [
                'x-goog-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
            ],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            120,
        );

        return $this->read($request);
    }

    /**
     * @return \Generator<int, string>
     */
    private function read(HttpRequest $request): \Generator
    {
        $parser = new SseParser();
        try {
            foreach ($this->http->stream($request) as $chunk) {
                foreach ($parser->feed($chunk) as $event) {
                    yield from $this->handle($event['data']);
                }
            }
            foreach ($parser->finish() as $event) {
                yield from $this->handle($event['data']);
            }
        } catch (TransportException $e) {
            throw ProviderException::fromTransport($this->label(), $e, $this->secrets());
        }
    }

    /**
     * @return list<string>
     */
    private function handle(string $data): array
    {
        $json = json_decode($data, true);
        if (!is_array($json)) {
            return [];
        }
        if (isset($json['error'])) {
            $err = $json['error'];
            $msg = is_array($err) && isset($err['message']) && is_string($err['message']) ? $err['message'] : 'onbekende fout';
            throw ProviderException::remote($this->label(), $msg, $this->secrets());
        }
        $out = [];
        $candidates = $json['candidates'] ?? null;
        $content = is_array($candidates) && isset($candidates[0]) && is_array($candidates[0]) ? ($candidates[0]['content'] ?? null) : null;
        $parts = is_array($content) ? ($content['parts'] ?? null) : null;
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text']) && $part['text'] !== '') {
                    $out[] = $part['text'];
                }
            }
        }
        return $out;
    }

    public function validateKey(string $key): bool
    {
        if ($key === '' || preg_match('/^[\x21-\x7E]{8,512}$/', $key) !== 1) {
            return false;
        }
        try {
            return $this->http->send(new HttpRequest(
                'GET',
                self::BASE . '/models?pageSize=1',
                ['x-goog-api-key' => $key, 'Accept' => 'application/json'],
                null,
                15,
            ))->isSuccess();
        } catch (TransportException) {
            return false;
        }
    }
}
