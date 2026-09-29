<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\FiveM;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;

final class FiveMController
{
    public function __construct(
        private readonly Connection   $db,
        private readonly CacheManager $cache,
    ) {}

    public function index(Request $request): Response
    {
        $settings = [];
        try {
            $rows = $this->db->fetchAll("SELECT `key`,`value` FROM cf_settings WHERE `group`='fivem'");
            foreach ($rows as $r) $settings[$r['key']] = $r['value'];
        } catch (\Throwable) {}

        $serverIp = $settings['server_ip'] ?? '';
        $data     = null;

        if ($serverIp) {
            $cacheKey = 'fivem.status.' . md5($serverIp);
            $data = $this->cache->remember($cacheKey, 45, function() use ($serverIp) {
                // curl_exec() geeft `false` bij een netwerkfout — en een
                // offline server is nu juist het meest voorkomende geval op
                // deze pagina. json_decode(false, ...) knalt onder
                // strict_types=1 met een TypeError i.p.v. netjes null terug
                // te geven, dus zonder de is_string()-check hier gaf een
                // onbereikbare FiveM-server een 500 i.p.v. "Offline" — anders
                // dan MinecraftController, die zijn HTTP-status wél eerst
                // checkt. Gevonden tijdens de S13-inventarisatiepas.
                $ch = curl_init("http://{$serverIp}/info.json");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4]);
                $rawInfo = curl_exec($ch);
                curl_close($ch);
                $info = is_string($rawInfo) ? json_decode($rawInfo, true) : null;

                $ph = curl_init("http://{$serverIp}/players.json");
                curl_setopt_array($ph, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4]);
                $rawPlayers = curl_exec($ph);
                curl_close($ph);
                $players = is_string($rawPlayers) ? (json_decode($rawPlayers, true) ?? []) : [];

                return $info ? ['info' => $info, 'players' => $players] : null;
            });
        }

        $players    = $data['players'] ?? [];
        $maxPlayers = (int)($settings['max_players'] ?? 64);

        ob_start();
        include __DIR__ . '/../templates/index.php';
        return Response::html(ob_get_clean());
    }
}
