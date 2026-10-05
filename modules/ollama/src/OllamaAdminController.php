<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\Ollama;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Security\CsrfProtection;

final class OllamaAdminController
{
    public function __construct(
        private readonly Connection   $db,
        private readonly CacheManager $cache,
    ) {}

    public function index(Request $request): Response
    {
        $settings  = OllamaConfig::load($this->db);
        $keySet    = ($settings['open_webui_key'] ?? '') !== '';
        unset($settings['open_webui_key']);            // nooit naar de view

        $client    = OllamaConfig::client(OllamaConfig::load($this->db));
        $available = $client->isAvailable();
        $models    = $available ? $client->listModels() : [];
        $viaWebUi  = $client->usesOpenWebUi();

        ob_start();
        include __DIR__ . '/../templates/admin.php';
        return Response::html(ob_get_clean());
    }

    public function save(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $max = ['host' => 300, 'default_model' => 100, 'system_prompt' => 1000, 'open_webui_url' => 300, 'guild_name' => 100];
        foreach ($max as $key => $len) {
            OllamaConfig::set($this->db, $key, mb_substr(trim((string) $request->input($key, '')), 0, $len));
        }
        // Alleen http(s)-adressen; iets anders opslaan heeft geen zin en is een risico.
        foreach (['host', 'open_webui_url'] as $k) {
            $v = (string) ($this->db->fetchOne("SELECT `value` FROM cf_settings WHERE `group`='ollama' AND `key`=?", [$k])['value'] ?? '');
            if ($v !== '' && preg_match('#^https?://[^\s<>"\']+$#i', $v) !== 1) {
                OllamaConfig::set($this->db, $k, '');
            }
        }
        OllamaConfig::set($this->db, 'timeout', (string) max(5, min(300, (int) $request->input('timeout', 30))));
        OllamaConfig::set($this->db, 'num_ctx', (string) max(0, min(131072, (int) $request->input('num_ctx', 0))));
        $ka = trim((string) $request->input('keep_alive', ''));
        OllamaConfig::set($this->db, 'keep_alive', preg_match('/^(-1|\d+[smh]?)$/', $ka) === 1 ? $ka : '');

        // Open WebUI-sleutel: leeg = ongewijzigd, vinkje = wissen
        $key = trim((string) $request->input('open_webui_key', ''));
        if ((string) $request->input('clear_open_webui_key', '') === '1') {
            OllamaConfig::set($this->db, 'open_webui_key', '', 'encrypted');
        } elseif ($key !== '') {
            OllamaConfig::set($this->db, 'open_webui_key', $key, 'encrypted');
        }

        $this->cache->delete('ollama.config');
        return Response::redirect('/admin/ollama?saved=1');
    }

    /** POST /admin/ollama/test — verbindingstest met uitleg (JSON). */
    public function test(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $model = trim((string) $request->input('model', ''));
        if ($model !== '' && preg_match('/^[A-Za-z0-9._:\/-]{1,100}$/', $model) !== 1) {
            return Response::json(['ok' => false, 'steps' => [['ok' => false, 'label' => 'Modelnaam', 'hint' => 'Ongeldige modelnaam.']], 'reply' => '', 'ms' => 0, 'models' => []], 422);
        }
        $client = OllamaConfig::client(OllamaConfig::load($this->db));
        return Response::json($client->diagnose($model !== '' ? $model : null));
    }
}
