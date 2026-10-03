<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Provider;

use CommunityFusion\Modules\AiStudio\Http\HttpRequest;
use CommunityFusion\Modules\AiStudio\Http\HttpTransportInterface;
use CommunityFusion\Modules\AiStudio\Http\SsrfException;
use CommunityFusion\Modules\AiStudio\Http\SsrfGuard;
use CommunityFusion\Modules\AiStudio\Http\TransportException;

/**
 * Lokale AI. Hergebruikt de instellingen van de bestaande Ollama-module
 * (cf_settings groep "ollama": host, default_model, timeout, open_webui_url,
 * open_webui_key). Is open_webui_url ingesteld, dan gaat het verkeer via de
 * OpenAI-compatibele Open WebUI-API (/api/chat/completions, bv. achter een
 * Cloudflare Tunnel); anders rechtstreeks naar Ollama (/api/chat, NDJSON).
 * Elke URL gaat eerst door de SsrfGuard en de verbinding wordt aan het
 * gecontroleerde IP vastgepind.
 */
final class OllamaProvider extends AbstractProvider
{
    /**
     * @param array{host?: string, default_model?: string, timeout?: int, open_webui_url?: string} $config
     * @param string $apiKey Bearer-key voor Open WebUI (optioneel)
     */
    public function __construct(
        HttpTransportInterface $http,
        private readonly SsrfGuard $guard,
        private readonly array $config = [],
        string $apiKey = '',
    ) {
        $default = isset($config['default_model']) && $config['default_model'] !== '' ? $config['default_model'] : 'llama3.2';
        parent::__construct($http, $apiKey, $default);
    }

    public function slug(): string
    {
        return 'ollama';
    }

    public function label(): string
    {
        return 'Ollama';
    }

    private function openWebUiUrl(): string
    {
        return rtrim($this->config['open_webui_url'] ?? '', '/');
    }

    private function host(): string
    {
        $host = $this->config['host'] ?? '';
        return rtrim($host !== '' ? $host : 'http://localhost:11434', '/');
    }

    private function timeout(): int
    {
        $t = $this->config['timeout'] ?? 60;
        return max(10, min($t, 300));
    }

    /**
     * @param array<string, string> $headers
     */
    private function build(string $method, string $url, array $headers, ?string $body, int $timeout): HttpRequest
    {
        try {
            $target = $this->guard->check($url);
        } catch (SsrfException $e) {
            throw new ProviderException('Ollama: ' . $e->getMessage());
        }
        return new HttpRequest($method, $target['url'], $headers, $body, $timeout, true, $target['ip']);
    }

    public function stream(array $messages, array $options): iterable
    {
        $payload = [
            'model' => $this->resolveModel($options),
            'messages' => $this->normalize($messages),
            'stream' => true,
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if ($this->openWebUiUrl() !== '') {
            $headers = ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream'];
            if ($this->apiKey !== '') {
                $headers['Authorization'] = 'Bearer ' . $this->apiKey;
            }
            return $this->streamOpenAiSse(
                $this->build('POST', $this->openWebUiUrl() . '/api/chat/completions', $headers, $body, $this->timeout())
            );
        }

        return $this->readNdjson(
            $this->build('POST', $this->host() . '/api/chat', ['Content-Type' => 'application/json'], $body, $this->timeout())
        );
    }

    /**
     * @return \Generator<int, string>
     */
    private function readNdjson(HttpRequest $request): \Generator
    {
        $buffer = '';
        try {
            foreach ($this->http->stream($request) as $chunk) {
                $buffer .= $chunk;
                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $nl));
                    $buffer = substr($buffer, $nl + 1);
                    $result = $this->handleLine($line);
                    if ($result === null) {
                        return;
                    }
                    if ($result !== '') {
                        yield $result;
                    }
                }
            }
            $result = $this->handleLine(trim($buffer));
            if ($result !== null && $result !== '') {
                yield $result;
            }
        } catch (TransportException $e) {
            throw ProviderException::fromTransport($this->label(), $e, $this->secrets());
        }
    }

    /**
     * @return string|null tekst ('' = niets), null = klaar
     */
    private function handleLine(string $line): ?string
    {
        if ($line === '') {
            return '';
        }
        $json = json_decode($line, true);
        if (!is_array($json)) {
            return '';
        }
        if (isset($json['error']) && is_string($json['error'])) {
            throw ProviderException::remote($this->label(), $json['error'], $this->secrets());
        }
        $text = '';
        $message = $json['message'] ?? null;
        if (is_array($message) && isset($message['content']) && is_string($message['content'])) {
            $text = $message['content'];
        }
        if (($json['done'] ?? false) === true) {
            return $text !== '' ? $text : null;
        }
        return $text;
    }

    public function validateKey(string $key): bool
    {
        try {
            if ($this->openWebUiUrl() !== '') {
                $headers = ['Accept' => 'application/json'];
                if ($key !== '') {
                    $headers['Authorization'] = 'Bearer ' . $key;
                }
                $request = $this->build('GET', $this->openWebUiUrl() . '/api/models', $headers, null, 10);
            } else {
                $request = $this->build('GET', $this->host() . '/api/tags', ['Accept' => 'application/json'], null, 10);
            }
            return $this->http->send($request)->isSuccess();
        } catch (ProviderException | TransportException) {
            return false;
        }
    }
}
