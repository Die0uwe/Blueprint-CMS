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
        $serverId = $config['server_id'] ?: ($this->moduleConfig['guild_id'] ?? '');

        if (empty($serverId)) {
            return '<p style="color:var(--muted);font-size:.85rem;">⚠️ Discord Server ID niet ingesteld.</p>';
        }

        // Widget-data (1 minuut gecachet, gedeeld met het Discord Widget-blok)
        $data = DiscordWidgetApi::fetch($this->cache, (string) $serverId);

        if (empty($data)) {
            return '<p style="color:var(--muted);font-size:.85rem;">🔌 Discord widget niet beschikbaar. Zorg dat de widget ingeschakeld is in de server-instellingen.</p>';
        }

        $members    = $data['members'] ?? [];
        $maxMembers = max(1, min(25, (int) ($config['max_members'] ?? 10)));
        $members    = array_slice($members, 0, $maxMembers);
        $guildName  = htmlspecialchars($data['name'] ?? 'Discord Server');
        $online     = count($data['members'] ?? []);
        $inviteUrl  = htmlspecialchars($config['invite_url'] ?: ($data['instant_invite'] ?? '#'), ENT_QUOTES);
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

    public function getCacheTtl(): int { return 60; } // 1 minuut
}
