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
        $fbKeySet  = ($settings['fallback_key'] ?? '') !== '';
        unset($settings['open_webui_key'], $settings['fallback_key']);   // nooit naar de view

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

        $max = ['default_model' => 100, 'system_prompt' => 1000, 'guild_name' => 100];
        foreach ($max as $key => $len) {
            OllamaConfig::set($this->db, $key, mb_substr(trim((string) $request->input($key, '')), 0, $len));
        }
        // Adressen: alleen http(s), geen cloud-metadata/link-local; ongeldig = oude waarde blijft staan.
        foreach (['host', 'open_webui_url'] as $k) {
            $v = rtrim(trim((string) $request->input($k, '')), '/');
            if ($v === '') {
                if ($k === 'open_webui_url') { OllamaConfig::set($this->db, $k, ''); }   // Open WebUI uitzetten mag
                continue;
            }
            if (preg_match('#^https?://[^\s<>"\']+$#i', $v) === 1 && preg_match('#^https?://(169\.254\.|\[?fe80:|metadata\.)#i', $v) !== 1) {
                OllamaConfig::set($this->db, $k, mb_substr($v, 0, 300));
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

        // Reserve-AI (DeepSeek in de cloud): alleen https; sleutel net als hierboven.
        $fu = rtrim(trim((string) $request->input('fallback_url', '')), '/');
        if ($fu === '' || preg_match('#^https://[^\s<>"\']+$#i', $fu) === 1) {
            OllamaConfig::set($this->db, 'fallback_url', mb_substr($fu, 0, 300));
        }
        $fm = trim((string) $request->input('fallback_model', ''));
        if ($fm === '' || preg_match('/^[A-Za-z0-9._:\/-]{1,100}$/', $fm) === 1) {
            OllamaConfig::set($this->db, 'fallback_model', $fm);
        }
        $fk = trim((string) $request->input('fallback_key', ''));
        if ((string) $request->input('clear_fallback_key', '') === '1') {
            OllamaConfig::set($this->db, 'fallback_key', '', 'encrypted');
        } elseif ($fk !== '') {
            OllamaConfig::set($this->db, 'fallback_key', $fk, 'encrypted');
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
        $res = $client->diagnose($model !== '' ? $model : null);
        $fb  = $client->diagnoseFallback();
        $res['steps'][] = $fb;
        $res['ok'] = $res['ok'] || ($client->hasFallback() && $fb['ok']);
        return Response::json($res);
    }
}
