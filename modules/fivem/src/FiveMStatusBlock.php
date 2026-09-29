<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\FiveM;
use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Core\Cache\CacheManager;

/**
 * FiveM Server Status Block
 * Gebruikt de cfx.re/status + server endpoint API.
 */
final class FiveMStatusBlock extends AbstractBlock
{
    public function __construct(private readonly CacheManager $cache) {}
    public function getSlug(): string { return 'fivem-status'; }
    public function getName(): string { return 'FiveM Server Status'; }

    public function getConfigSchema(): array
    {
        return [
            'server_ip'   => ['type' => 'string',  'label' => 'Server IP:Poort', 'required' => true, 'placeholder' => '1.2.3.4:30120'],
            'server_name' => ['type' => 'string',  'label' => 'Server naam'],
            'max_players' => ['type' => 'integer', 'label' => 'Max spelers', 'default' => 32],
            'show_players'=> ['type' => 'boolean', 'label' => 'Spelersnamen tonen', 'default' => true],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $serverIp = $config['server_ip'] ?? '';
        if (empty($serverIp)) return '<p class="cf-block-empty">⚙️ Configureer server IP:poort.</p>';

        $serverName = htmlspecialchars($config['server_name'] ?: $serverIp);
        $maxPlayers = (int)($config['max_players'] ?? 32);
        $cacheKey   = 'fivem.status.' . md5($serverIp);

        $data = $this->cache->remember($cacheKey, 45, function() use ($serverIp) {
            $url = "http://{$serverIp}/info.json";
            $ch  = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4,
                                    CURLOPT_CONNECTTIMEOUT => 3]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200) return null;
            $info = json_decode($body, true);

            // Haal spelers op. Zelfde bug als FiveMController::index(): een
            // gefaalde curl_exec() geeft `false` terug, en json_decode(false)
            // knalt onder strict_types=1 met een TypeError i.p.v. dat de
            // `?? []` hierna nog kan redden. Gevonden tijdens de
            // S13-inventarisatiepas.
            $ph = curl_init("http://{$serverIp}/players.json");
            curl_setopt_array($ph, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4]);
            $rawPlayers = curl_exec($ph);
            curl_close($ph);
            $players = is_string($rawPlayers) ? (json_decode($rawPlayers, true) ?? []) : [];

            return ['info' => $info, 'players' => $players];
        });

        if (!$data) {
            return <<<HTML
            <div class="cf-fivem-block cf-fivem-offline">
                <div class="cf-fivem-header">
                    <span style="font-size:1.4rem;">🚗</span>
                    <div>
                        <div class="cf-fivem-name">{$serverName}</div>
                        <div class="cf-fivem-status offline">⚫ Offline of onbereikbaar</div>
                    </div>
                </div>
            </div>
            HTML;
        }

        $players       = $data['players'] ?? [];
        $playerCount   = count($players);
        $serverVersion = htmlspecialchars($data['info']['server'] ?? 'FXServer');
        $pct           = $maxPlayers > 0 ? round(($playerCount / $maxPlayers) * 100) : 0;
        $barColor      = $pct > 80 ? '#ef4444' : ($pct > 50 ? '#f59e0b' : '#10b981');

        $playersHtml = '';
        if (($config['show_players'] ?? true) && !empty($players)) {
            $names = array_slice($players, 0, 8);
            $playersHtml = '<div class="cf-fivem-players">';
            foreach ($names as $p) {
                $pname = htmlspecialchars($p['name'] ?? 'Anon');
                $ping  = (int)($p['ping'] ?? 0);
                $playersHtml .= "<span class='cf-fivem-player'>{$pname} <span class='cf-fivem-ping'>{$ping}ms</span></span>";
            }
            if (count($players) > 8) $playersHtml .= '<span class="cf-mc-player">+' . (count($players) - 8) . ' meer</span>';
            $playersHtml .= '</div>';
        }

        return <<<HTML
        <div class="cf-fivem-block cf-fivem-online">
            <div class="cf-fivem-header">
                <span style="font-size:1.4rem;">🚗</span>
                <div style="flex:1">
                    <div class="cf-fivem-name">{$serverName}</div>
                    <div class="cf-fivem-status online">🟢 Online</div>
                </div>
                <div class="cf-mc-players-count">
                    <span class="cf-mc-count">{$playerCount}</span>/{$maxPlayers}
                </div>
            </div>
            <div style="margin:.6rem 0;height:4px;background:var(--border);border-radius:2px;">
                <div style="width:{$pct}%;height:100%;background:{$barColor};border-radius:2px;transition:width .3s;"></div>
            </div>
            {$playersHtml}
        </div>
        HTML;
    }

    public function getCacheTtl(): int { return 45; }
}
