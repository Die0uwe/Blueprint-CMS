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

namespace CommunityFusion\Modules\Settings;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Security\Crypto;

final class SettingsRepository
{
    private array $loaded = [];

    public function __construct(
        private readonly Connection   $db,
        private readonly CacheManager $cache,
    ) {}

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        $all = $this->getGroup($group);
        return $all[$key] ?? $default;
    }

    public function getGroup(string $group): array
    {
        if (isset($this->loaded[$group])) return $this->loaded[$group];

        $this->loaded[$group] = $this->cache->remember("settings.{$group}", 3600, function() use ($group) {
            $rows   = $this->db->fetchAll(
                "SELECT `key`, `value`, `type` FROM cf_settings WHERE `group` = ?",
                [$group]
            );
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $this->cast($row['value'], $row['type']);
            }
            return $result;
        });

        return $this->loaded[$group];
    }

    /**
     * Golf 10: $type kan nu meegegeven worden. Bij 'encrypted' wordt $value
     * vóór opslag versleuteld met Crypto (AES-256-GCM / APP_KEY) — vóór deze
     * golf stond hier alleen (string) $value, ongeacht het kolomtype, dus
     * een OAuth client_secret zou in platte tekst in cf_settings hebben
     * gestaan. Bestaande aanroepen zonder $type blijven ongewijzigd werken
     * (blijft NULL in de kolom vervangen door de vorige DEFAULT 'string').
     */
    public function set(string $group, string $key, mixed $value, ?string $type = null): void
    {
        $stored = ($type === 'encrypted' && $value !== '')
            ? Crypto::encrypt((string) $value)
            : (string) $value;

        if ($type !== null) {
            $this->db->execute(
                "INSERT INTO cf_settings (`group`, `key`, `value`, `type`) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`), updated_at = NOW()",
                [$group, $key, $stored, $type]
            );
        } else {
            $this->db->execute(
                "INSERT INTO cf_settings (`group`, `key`, `value`) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()",
                [$group, $key, $stored]
            );
        }
        $this->cache->delete("settings.{$group}");
        unset($this->loaded[$group]);
    }

    /**
     * Is er al een niet-lege waarde opgeslagen voor deze sleutel? Gebruikt
     * door het instellingenformulier om een 'encrypted' veld leeg te laten
     * (placeholder "•••• — laat leeg om te behouden") i.p.v. de ontsleutelde
     * waarde terug naar de browser te sturen.
     */
    public function has(string $group, string $key): bool
    {
        $value = $this->get($group, $key, '');
        return $value !== '' && $value !== null;
    }

    private function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int'       => (int) $value,
            'bool'      => $value === '1' || $value === 'true',
            'json'      => json_decode($value ?? '{}', true),
            'encrypted' => $value !== '' && $value !== null ? Crypto::decrypt($value) : '',
            default     => $value,
        };
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : SettingsRepository.php                               ║
// ║  Role         : Data                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-06-06                                           ║
// ║  Last Updated : 2026-06-06  03:00                                    ║
// ║  Status       : New                                                  ║
// ║  Notes        : Site-instellingen met type casting                   ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
