<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio;

use Psr\SimpleCache\CacheInterface;

/**
 * Extra begrenzing PER GEBRUIKER, bovenop de IP-gebonden RateLimitMiddleware.
 * Reden: elke chat-aanroep kost betaald API-tegoed; de middleware (60 req/min
 * per IP, gedeeld door de hele site) is daarvoor te grof.
 */
final class UserRateLimiter
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * true = toegestaan (en geteld), false = limiet bereikt.
     */
    public function allow(string $bucket, int $max, int $windowSeconds): bool
    {
        $window = intdiv(time(), max(1, $windowSeconds));
        $key = 'aistudio.rl.' . md5($bucket . '|' . $window);
        $raw = $this->cache->get($key, 0);
        $count = is_int($raw) ? $raw : 0;
        if ($count >= $max) {
            return false;
        }
        $this->cache->set($key, $count + 1, $windowSeconds);
        return true;
    }
}
