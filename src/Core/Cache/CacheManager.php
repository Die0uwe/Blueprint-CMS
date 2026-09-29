<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
//
// This work is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This work is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 Cache Manager
 * Ondersteunt file-based en Redis cache drivers.
 */
final class CacheManager implements CacheInterface
{
    private CacheInterface $driver;

    public function __construct(array $config)
    {
        $driver = $config['driver'] ?? 'file';

        // 'redis' stond in config.php's voorbeeld-commentaar ("file | redis |
        // memcached") en werd hier ook al gerouteerd, maar RedisCache is
        // nooit gebouwd — een site die dit handmatig instelde (nergens in
        // installer/admin-UI selecteerbaar, dus alleen via directe
        // config.php-edit) kreeg op ELKE request een kale "Class
        // RedisCache not found"-fatal error. Gevonden tijdens de
        // Architectuur-deelaudit van de totale-codebase-audit (v1.25.5+).
        // Duidelijke, actionable exception i.p.v. dat cryptische fatal, tot
        // een echte RedisCache-implementatie er is.
        if ($driver === 'redis') {
            throw new \RuntimeException(
                "Cache-driver 'redis' is nog niet geïmplementeerd in Blueprint CMS — " .
                "zet config/config.php's cache.driver terug op 'file' (de standaard)."
            );
        }

        $this->driver = new FileCache($config['path'] ?? CF_ROOT . '/storage/cache');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->driver->get($key, $default);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        return $this->driver->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->driver->delete($key);
    }

    public function clear(): bool
    {
        return $this->driver->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->driver->getMultiple($keys, $default);
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        return $this->driver->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->driver->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->driver->has($key);
    }

    /**
     * Cache-aside helper: haal op of bereken en sla op.
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = $this->get($key);
        if ($value !== null) return $value;

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    /**
     * Vergeet een cache-sleutel (alias voor delete).
     */
    public function forget(string $key): bool
    {
        return $this->delete($key);
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : CacheManager.php                                     ║
// ║  Role         : Core                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-06-06                                           ║
// ║  Last Updated : 2026-06-06  03:00                                    ║
// ║  Status       : New                                                  ║
// ║  Notes        : PSR-16 cache facade                                  ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
