<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Provider;

use CommunityFusion\Modules\AiStudio\Http\HttpRequest;
use CommunityFusion\Modules\AiStudio\Http\TransportException;

/**
 * Basis voor providers met de OpenAI chat-completions API (vaste https-host).
 */
abstract class OpenAiCompatibleProvider extends AbstractProvider
{
    /** Basis-URL zonder slash aan het eind, bv. https://api.openai.com/v1 */
    abstract protected function baseUrl(): string;

    /** Naam van de token-limietparameter in de request-body. */
    protected function tokenParam(): string
    {
        return 'max_tokens';
    }

    public function stream(array $messages, array $options): iterable
    {
        $this->requireKey();

        $payload = [
            'model' => $this->resolveModel($options),
            'messages' => $this->normalize($messages),
            'stream' => true,
        ];
        if (isset($options['max_tokens'])) {
            $payload[$this->tokenParam()] = $this->maxTokens($options);
        }

        $request = new HttpRequest(
            'POST',
            $this->baseUrl() . '/chat/completions',
            [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
            ],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            120,
        );

        return $this->streamOpenAiSse($request);
    }

    public function validateKey(string $key): bool
    {
        if ($key === '' || preg_match('/^[\x21-\x7E]{8,512}$/', $key) !== 1) {
            return false;
        }
        try {
            $response = $this->http->send(new HttpRequest(
                'GET',
                $this->baseUrl() . '/models',
                ['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'],
                null,
                15,
            ));
            return $response->isSuccess();
        } catch (TransportException) {
            return false;
        }
    }
}
