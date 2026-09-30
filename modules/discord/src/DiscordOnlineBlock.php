<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;

/**
 * Discord Online Members Block
 * Toont online leden via de Guild Widget API.
 * Vereist dat de widget ingeschakeld is in de Discord server.
 */
final class DiscordOnlineBlock extends AbstractBlock
{
    public function __construct(
        private readonly Connection   $db,
        private readonly CacheManager $cache,
        private readonly array        $moduleConfig = [],
    ) {}

    public function getSlug(): string { return 'discord-online'; }
    public function getName(): string { return 'Discord Online Leden'; }

    public function getConfigSchema(): array
    {
        return [
            'server_id'   => ['type' => 'string',  'label' => 'Server ID (optioneel, overschrijft module)'],
            'max_members' => ['type' => 'integer', 'label' => 'Max leden tonen', 'default' => 10, 'min' => 1, 'max' => 25],
            'show_invite'  => ['type' => 'boolean', 'label' => 'Join-knop tonen', 'default' => true],
            'invite_url'   => ['type' => 'url',     'label' => 'Discord invite URL'],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $serverId = trim((string) (($config['server_id'] ?? '') ?: ($this->moduleConfig['guild_id'] ?? '')));

        if ($serverId === '' || !preg_match('/^\d{15,25}$/', $serverId)) {
            return '<p style="color:var(--muted);font-size:.85rem;">⚠️ Discord Server ID ontbreekt of is ongeldig. Vul het in via de blok-instellingen (⚙️).</p>';
        }

        // Cache de widget data
        // Alleen succesvolle antwoorden cachen, zodat een net ingeschakelde widget meteen werkt.
        $key  = "discord.widget.{$serverId}";
        $data = $this->cache->get($key);
        if (!is_array($data)) {
            $ch = curl_init("https://discord.com/api/guilds/{$serverId}/widget.json");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $data = $code === 200 ? json_decode((string) $body, true) : null;
            if (is_array($data)) {
                $this->cache->set($key, $data, 60);
            } else {
                $why = match (true) {
                    $code === 403 => 'De widget staat uit: zet in Discord Serverinstellingen → Widget "Server-widget inschakelen" aan en kies een uitnodigingskanaal.',
                    $code === 404 => 'Server niet gevonden: controleer het Server ID.',
                    $code === 429 => 'Discord geeft tijdelijk te veel verzoeken terug; probeer het zo opnieuw.',
                    default       => 'Discord is nu niet bereikbaar.',
                };
                return '<p style="color:var(--muted);font-size:.85rem;">🔌 ' . htmlspecialchars($why, ENT_QUOTES) . '</p>';
            }
        }

        $members    = $data['members'] ?? [];
        $maxMembers = max(1, min(25, (int) ($config['max_members'] ?? 10)));
        $members    = array_slice($members, 0, $maxMembers);
        $guildName  = htmlspecialchars($data['name'] ?? 'Discord Server');
        $online     = count($data['members'] ?? []);
        $inviteUrl  = htmlspecialchars((($config['invite_url'] ?? '') ?: ($data['instant_invite'] ?? '#')), ENT_QUOTES);
        $showInvite = (bool) ($config['show_invite'] ?? true);

        $membersHtml = '';
        foreach ($members as $member) {
            $avatar   = htmlspecialchars($member['avatar_url'] ?? '', ENT_QUOTES);
            $username = htmlspecialchars($member['username'] ?? 'Onbekend');
            $status   = $member['status'] ?? 'online';
            $statusColor = match($status) {
                'online'   => '#10b981',
                'idle'     => '#f59e0b',
                'dnd'      => '#ef4444',
                default    => '#64748b',
            };
            $membersHtml .= <<<HTML
            <div class="cf-discord-member">
                <div class="cf-discord-avatar-wrap">
                    <img src="{$avatar}" alt="{$username}" class="cf-discord-avatar" loading="lazy">
                    <span class="cf-discord-status" style="background:{$statusColor}"></span>
                </div>
                <span class="cf-discord-username">{$username}</span>
            </div>
            HTML;
        }

        $inviteBtn = $showInvite && $inviteUrl !== '#'
            ? "<a href=\"{$inviteUrl}\" target=\"_blank\" rel=\"noopener\" class=\"cf-btn\" style=\"width:100%;justify-content:center;margin-top:.8rem;font-size:.8rem;\">🎮 Server Joinen</a>"
            : '';

        return <<<HTML
        <div class="cf-discord-online">
            <div class="cf-discord-header">
                <span class="cf-discord-logo">🎮</span>
                <div>
                    <div class="cf-discord-guild">{$guildName}</div>
                    <div class="cf-discord-count"><span class="cf-discord-dot"></span> {$online} online</div>
                </div>
            </div>
            <div class="cf-discord-members">{$membersHtml}</div>
            {$inviteBtn}
        </div>
        HTML;
    }

    public function getCacheTtl(): int { return 0; } // de widget-data wordt zelf 60 s gecachet (alleen bij succes)
}
