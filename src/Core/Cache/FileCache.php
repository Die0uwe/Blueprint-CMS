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
 * File-gebaseerde cache implementatie.
 * Elke cache-entry wordt als een PHP-bestand opgeslagen (snel includeable).
 */
final class FileCache implements CacheInterface
{
    public function __construct(private readonly string $path)
    {
        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->getFilePath($key);
        if (!file_exists($file)) return $default;

        // unserialize() geeft `false` terug bij een corrupt/half-geschreven
        // bestand (bv. door een crash midden in set()). Zonder deze check
        // gaf $data['expires']/$data['value'] hieronder een "trying to
        // access array offset on bool"-warning en leverde stilletjes null
        // op i.p.v. $default — en het kapotte bestand bleef staan, dus de
        // warning bleef bij elke volgende get() terugkomen. Gevonden
        // tijdens de S13-inventarisatiepas.
        $raw  = file_get_contents($file);
        $data = $raw !== false ? @unserialize($raw) : false;
        if (!is_array($data) || !array_key_exists('value', $data) || !array_key_exists('expires', $data)) {
            @unlink($file);
            return $default;
        }

        if ($data['expires'] !== null && $data['expires'] < time()) {
            unlink($file);
            return $default;
        }

        return $data['value'];
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $expires = null;

        if ($ttl instanceof \DateInterval) {
            $expires = (new \DateTime())->add($ttl)->getTimestamp();
        } elseif (is_int($ttl) && $ttl > 0) {
            $expires = time() + $ttl;
        }

        $data = serialize(['value' => $value, 'expires' => $expires]);
        return file_put_contents($this->getFilePath($key), $data, LOCK_EX) !== false;
    }

    public function delete(string $key): bool
    {
        $file = $this->getFilePath($key);
        return file_exists($file) ? unlink($file) : true;
    }

    public function clear(): bool
    {
        foreach (glob($this->path . '/*.cache') ?: [] as $file) {
            unlink($file);
        }
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) return false;
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    private function getFilePath(string $key): string
    {
        return $this->path . '/' . md5($key) . '.cache';
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : FileCache.php                                        ║
// ║  Role         : Core                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-06-06                                           ║
// ║  Last Updated : 2026-06-06  03:00                                    ║
// ║  Status       : New                                                  ║
// ║  Notes        : File-based cache driver                              ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
