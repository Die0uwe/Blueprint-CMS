<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Api\Middleware;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Cache\CacheManager;

/**
 * Rate Limit Middleware
 * Beschermt API endpoints tegen misbruik.
 * Standaard: 60 requests per minuut per IP.
 */
final class RateLimitMiddleware
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly int          $maxRequests = 60,
        private readonly int          $windowSeconds = 60,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $ip       = $request->ip();
        $key      = 'ratelimit.' . md5($ip . '|' . $request->getPath() . '|' . date('YmdHi')); // nieuw venster elke minuut
        $count    = (int)($this->cache->get($key) ?? 0);

        if ($count >= $this->maxRequests) {
            return Response::json([
                'error'      => 'Te veel requests. Probeer het over een minuut opnieuw.',
                'retry_after' => $this->windowSeconds,
            ], 429)->withHeader('Retry-After', (string)$this->windowSeconds)
                   ->withHeader('X-RateLimit-Limit', (string)$this->maxRequests)
                   ->withHeader('X-RateLimit-Remaining', '0');
        }

        $this->cache->set($key, $count + 1, $this->windowSeconds);

        $response = $next($request);
        return $response
            ->withHeader('X-RateLimit-Limit', (string)$this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string)max(0, $this->maxRequests - $count - 1));
    }
}
