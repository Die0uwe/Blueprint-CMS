<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Provider;

use CommunityFusion\Modules\AiStudio\Http\HttpRequest;
use CommunityFusion\Modules\AiStudio\Http\SseParser;
use CommunityFusion\Modules\AiStudio\Http\TransportException;

final class AnthropicProvider extends AbstractProvider
{
    private const BASE = 'https://api.anthropic.com/v1';
    private const VERSION = '2023-06-01';

    public function slug(): string
    {
        return 'anthropic';
    }

    public function label(): string
    {
        return 'Anthropic';
    }

    public function stream(array $messages, array $options): iterable
    {
        $this->requireKey();

        $system = [];
        $turns = [];
        foreach ($this->normalize($messages) as $m) {
            if ($m['role'] === 'system') {
                $system[] = $m['content'];
            } else {
                $turns[] = $m;
            }
        }
        $turns = $this->mergeSameRole($turns);
        if ($turns === [] || $turns[0]['role'] !== 'user') {
            throw new ProviderException('Anthropic: een gesprek moet met een gebruikersbericht beginnen.');
        }

        $payload = [
            'model' => $this->resolveModel($options),
            'max_tokens' => $this->maxTokens($options),
            'messages' => $turns,
            'stream' => true,
        ];
        if ($system !== []) {
            $payload['system'] = implode("\n\n", $system);
        }

        $request = new HttpRequest(
            'POST',
            self::BASE . '/messages',
            [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::VERSION,
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
                    $result = $this->handle($event);
                    if ($result === null) {
                        return;
                    }
                    if ($result !== '') {
                        yield $result;
                    }
                }
            }
            foreach ($parser->finish() as $event) {
                $result = $this->handle($event);
                if ($result !== null && $result !== '') {
                    yield $result;
                }
            }
        } catch (TransportException $e) {
            throw ProviderException::fromTransport($this->label(), $e, $this->secrets());
        }
    }

    /**
     * @param array{event: string, data: string} $event
     * @return string|null tekst ('' = niets), null = einde van de stroom
     */
    private function handle(array $event): ?string
    {
        $json = json_decode($event['data'], true);
        if (!is_array($json)) {
            return '';
        }
        $type = $json['type'] ?? $event['event'];

        if ($type === 'error') {
            $err = $json['error'] ?? null;
            $msg = is_array($err) && isset($err['message']) && is_string($err['message']) ? $err['message'] : 'onbekende fout';
            throw ProviderException::remote($this->label(), $msg, $this->secrets());
        }
        if ($type === 'message_stop') {
            return null;
        }
        if ($type === 'content_block_delta') {
            $delta = $json['delta'] ?? null;
            if (is_array($delta) && ($delta['type'] ?? '') === 'text_delta' && isset($delta['text']) && is_string($delta['text'])) {
                return $delta['text'];
            }
        }
        return '';
    }

    public function validateKey(string $key): bool
    {
        if ($key === '' || preg_match('/^[\x21-\x7E]{8,512}$/', $key) !== 1) {
            return false;
        }
        try {
            return $this->http->send(new HttpRequest(
                'GET',
                self::BASE . '/models',
                ['x-api-key' => $key, 'anthropic-version' => self::VERSION, 'Accept' => 'application/json'],
                null,
                15,
            ))->isSuccess();
        } catch (TransportException) {
            return false;
        }
    }
}
