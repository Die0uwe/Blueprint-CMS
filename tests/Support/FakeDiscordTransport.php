<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Support;

use CommunityFusion\Modules\Discord\DiscordTransport;
use CommunityFusion\Modules\Discord\DiscordTransportException;

/**
 * Nep-transport: geen netwerk. Antwoorden komen uit een wachtrij (queue()) of uit een router
 * (route('GET /users/@me', …)); elk verzoek wordt bewaard in $requests.
 */
final class FakeDiscordTransport implements DiscordTransport
{
    /** @var list<array{method:string,url:string,headers:array<int,string>,body:?string}> */
    public array $requests = [];
    /** @var list<array{status:int,headers:array<string,string>,body:string}|\Throwable> */
    private array $queue = [];
    /** @var array<string,array{status:int,headers:array<string,string>,body:string}> */
    private array $routes = [];

    public function queue(int $status, array|string $body = [], array $headers = []): self
    {
        $this->queue[] = ['status' => $status, 'headers' => $headers, 'body' => is_string($body) ? $body : json_encode($body)];
        return $this;
    }

    public function queueException(\Throwable $e): self
    {
        $this->queue[] = $e;
        return $this;
    }

    /** $key = "GET /users/@me" (pad zonder host/versie en zonder query). */
    public function route(string $key, int $status, array|string $body = [], array $headers = []): self
    {
        $this->routes[$key] = ['status' => $status, 'headers' => $headers, 'body' => is_string($body) ? $body : json_encode($body)];
        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = preg_replace('#^/api/v\d+#', '', $path);
        $key  = "{$method} {$path}";
        if (isset($this->routes[$key])) {
            return $this->routes[$key];
        }
        if ($this->queue === []) {
            throw new DiscordTransportException("FakeDiscordTransport: geen antwoord voor {$key}");
        }
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }

    public function last(): array
    {
        return $this->requests[array_key_last($this->requests)];
    }

    public function lastJson(): array
    {
        return json_decode((string) $this->last()['body'], true) ?? [];
    }

    public function hasHeader(int $i, string $line): bool
    {
        return in_array($line, $this->requests[$i]['headers'], true);
    }
}
