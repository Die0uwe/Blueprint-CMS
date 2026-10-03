<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Security\Crypto;

/**
 * Directe cf_settings-toegang voor de AI Studio-geheimen.
 *
 * Waarom niet via SettingsRepository? Die cachet de ONTSLEUTELDE waarden van
 * een hele groep 1 uur lang in storage/cache (plat bestand). Voor een API-key
 * is dat onacceptabel; hier wordt daarom nooit gecachet. Versleuteling gaat
 * via Core\Security\Crypto (AES-256-GCM). Upsert is bewust portable
 * (SELECT + UPDATE/INSERT, geen ON DUPLICATE KEY) zodat dit ook in tests tegen
 * SQLite draait; de UNIQUE (group,key) vangt een race af.
 */
final class SettingsStore
{
    public const GROUP = 'aistudio';

    public function __construct(private readonly Connection $db)
    {
    }

    public function get(string $group, string $key, string $default = ''): string
    {
        $row = $this->db->fetchOne(
            'SELECT `value`, `type` FROM cf_settings WHERE `group` = ? AND `key` = ?',
            [$group, $key]
        );
        if ($row === null || !is_string($row['value'] ?? null) || $row['value'] === '') {
            return $default;
        }
        if (($row['type'] ?? '') === 'encrypted') {
            return Crypto::decrypt($row['value']);
        }
        return $row['value'];
    }

    public function has(string $group, string $key): bool
    {
        return $this->get($group, $key) !== '';
    }

    /**
     * @param 'string'|'int'|'bool'|'json'|'encrypted' $type
     */
    public function set(string $group, string $key, string $value, string $type = 'string'): void
    {
        $stored = ($type === 'encrypted' && $value !== '') ? Crypto::encrypt($value) : $value;

        $existing = $this->db->fetchOne(
            'SELECT id FROM cf_settings WHERE `group` = ? AND `key` = ?',
            [$group, $key]
        );
        if ($existing !== null) {
            $this->db->execute(
                'UPDATE cf_settings SET `value` = ?, `type` = ? WHERE `group` = ? AND `key` = ?',
                [$stored, $type, $group, $key]
            );
            return;
        }
        $this->db->execute(
            'INSERT INTO cf_settings (`group`, `key`, `value`, `type`) VALUES (?, ?, ?, ?)',
            [$group, $key, $stored, $type]
        );
    }
}
