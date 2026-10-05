<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\Ollama;

use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Security\Crypto;

/**
 * Leest/schrijft de instellingen van de Ollama-module (cf_settings, groep 'ollama')
 * op één plek. Geheimen (open_webui_key) staan versleuteld in de database en worden
 * hier ontsleuteld; ze komen nooit in een cache of in een formulier terug.
 */
final class OllamaConfig
{
    public const GROUP = 'ollama';
    /** Aanbevolen DeepSeek-modellen (Ollama-tags) voor in de modelkeuze. */
    public const DEEPSEEK_SUGGESTIONS = [
        'deepseek-r1:1.5b', 'deepseek-r1:7b', 'deepseek-r1:8b', 'deepseek-r1:14b', 'deepseek-r1:32b', 'deepseek-r1:70b',
        'deepseek-coder-v2:16b',
    ];

    /** @return array<string, string> */
    public static function load(Connection $db): array
    {
        try {
            $cfg = [];
            foreach ($db->fetchAll("SELECT `key`, `value`, `type` FROM cf_settings WHERE `group` = 'ollama'") as $r) {
                $v = (string) ($r['value'] ?? '');
                if (($r['type'] ?? '') === 'encrypted' && $v !== '') {
                    $v = Crypto::decrypt($v);
                }
                $cfg[(string) $r['key']] = $v;
            }
            return $cfg;
        } catch (\Throwable) {
            return [];
        }
    }

    public static function client(array $cfg, ?CacheManager $cache = null): OllamaClient
    {
        return new OllamaClient(
            host:         $cfg['host']           ?? 'http://localhost:11434',
            model:        $cfg['default_model']  ?? 'llama3.2',
            timeout:      max(5, min(300, (int) ($cfg['timeout'] ?? 30))),
            cache:        $cache,
            openWebUiUrl: $cfg['open_webui_url'] ?? '',
            openWebUiKey: $cfg['open_webui_key'] ?? '',
            numCtx:       max(0, min(131072, (int) ($cfg['num_ctx'] ?? 0))),
            keepAlive:    preg_match('/^(-1|\d+[smh]?)$/', (string) ($cfg['keep_alive'] ?? '')) === 1 ? (string) $cfg['keep_alive'] : '',
        );
    }

    public static function set(Connection $db, string $key, string $value, string $type = 'string'): void
    {
        $stored = ($type === 'encrypted' && $value !== '') ? Crypto::encrypt($value) : $value;
        $row = $db->fetchOne('SELECT id FROM cf_settings WHERE `group` = ? AND `key` = ?', [self::GROUP, $key]);
        if ($row !== null) {
            $db->execute('UPDATE cf_settings SET `value` = ?, `type` = ? WHERE `group` = ? AND `key` = ?', [$stored, $type, self::GROUP, $key]);
            return;
        }
        $db->execute('INSERT INTO cf_settings (`group`, `key`, `value`, `type`) VALUES (?, ?, ?, ?)', [self::GROUP, $key, $stored, $type]);
    }
}
