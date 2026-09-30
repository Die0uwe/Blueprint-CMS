<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Security;

use CommunityFusion\Core\Cache\CacheManager;

/**
 * RateLimiter — eenvoudige teller per sleutel en tijdvenster (bijv. per gebruiker per minuut).
 * Bedoeld voor POST-endpoints van ingelogde gebruikers; sleutel dus op gebruikers-id, niet op IP.
 */
final class RateLimiter
{
    public function __construct(private readonly CacheManager $cache) {}

    /** Telt deze aanroep mee; true = limiet overschreden. */
    public function tooMany(string $key, int $limit, int $windowSeconds = 60): bool
    {
        $slot = 'rl.' . md5($key) . '.' . intdiv(time(), max(1, $windowSeconds));
        $count = (int)$this->cache->get($slot, 0) + 1;
        $this->cache->set($slot, $count, $windowSeconds + 5);
        return $count > $limit;
    }
}
