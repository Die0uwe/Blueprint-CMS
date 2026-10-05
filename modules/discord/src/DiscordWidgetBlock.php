<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Core\Cache\CacheManager;

/**
 * Discord Widget Block
 *
 * Zonder kamer-ID: de officiële Discord widget (iframe) met alle spraakkamers.
 * Mét kamer-ID: een compacte kaart met alleen die ene kamer en wie er nu in zit.
 * De officiële widget kan niet op één kamer gefilterd worden, daarom tekent het blok
 * die kaart zelf op basis van de publieke widget.json.
 */
final class DiscordWidgetBlock extends AbstractBlock
{
    public function __construct(
        private readonly array         $moduleConfig = [],
        private readonly ?CacheManager $cache = null,
    ) {}

    public function getSlug(): string { return 'discord-widget'; }
    public function getName(): string { return 'Discord Widget'; }

    public function getConfigSchema(): array
    {
        return [
            'server_id'  => ['type' => 'string',  'label' => 'Server ID (overschrijft module instelling)', 'required' => false],
            'channel_id' => [
                'type'    => 'string',
                'label'   => 'Kamer-ID (leeg = alle kamers)',
                'pattern' => '\d{15,25}',
                'help'    => 'Toon alleen deze spraakkamer, met wie erin zit. Zet in Discord onder Instellingen → Geavanceerd '
                           . 'de Ontwikkelaarsmodus aan, klik met rechts op de kamer en kies "Kanaal-ID kopiëren". '
                           . 'Alleen kamers die voor iedereen zichtbaar zijn staan in de widget.',
            ],
            'invite_url' => [
                'type'  => 'string',
                'label' => 'Invite-link voor de Join-knop (alleen bij een gekozen kamer)',
                'help'  => 'Leeg = de invite-link uit de Discord-widgetinstellingen.',
            ],
            'theme'      => ['type' => 'select',  'label' => 'Thema', 'options' => ['dark', 'light'], 'default' => 'dark'],
            'width'      => ['type' => 'integer', 'label' => 'Breedte (px)', 'default' => 350],
            'height'     => ['type' => 'integer', 'label' => 'Hoogte (px)',  'default' => 500],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $rawServerId = trim((string) (($config['server_id'] ?? '') ?: ($this->moduleConfig['guild_id'] ?? '')));

        if ($rawServerId === '') {
            return '<p style="color:var(--muted);font-size:.85rem;">⚠️ Discord Server ID niet ingesteld. Configureer de Discord module.</p>';
        }

        $channelId = trim((string) ($config['channel_id'] ?? ''));
        if ($channelId !== '') {
            return $this->renderRoom($rawServerId, $channelId, $config);
        }

        $serverId = htmlspecialchars($rawServerId, ENT_QUOTES);
        $theme    = ($config['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
        $width    = max(200, min(1000, (int) ($config['width']  ?? 350)));
        $height   = max(200, min(1000, (int) ($config['height'] ?? 500)));

        return <<<HTML
        <div class="cf-discord-widget">
            <iframe
                src="https://discord.com/widget?id={$serverId}&theme={$theme}"
                width="{$width}"
                height="{$height}"
                allowtransparency="true"
                frameborder="0"
                sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts"
                loading="lazy"
                style="border-radius:8px;max-width:100%;">
            </iframe>
        </div>
        HTML;
    }

    /** Eén spraakkamer: naam, aantal aanwezigen, hun namen en een Join-knop. */
    private function renderRoom(string $serverId, string $channelId, array $config): string
    {
        $note = static fn(string $text): string => '<p style="color:var(--muted);font-size:.85rem;">' . $text . '</p>';

        if (preg_match('/^\d{15,25}$/', $channelId) !== 1) {
            return $note('⚠️ Het kamer-ID moet uit alleen cijfers bestaan (15–25 tekens).');
        }
        if ($this->cache === null) {
            return $note('🔌 Discord widget niet beschikbaar.');
        }

        $data = DiscordWidgetApi::fetch($this->cache, $serverId);
        if ($data === null) {
            return $note('🔌 Discord widget niet beschikbaar. Zorg dat de widget ingeschakeld is in de server-instellingen.');
        }

        $room = null;
        foreach ((array) ($data['channels'] ?? []) as $channel) {
            if ((string) ($channel['id'] ?? '') === $channelId) {
                $room = $channel;
                break;
            }
        }
        if ($room === null) {
            return $note('⚠️ Kamer niet gevonden. Alleen spraakkamers die voor iedereen zichtbaar zijn staan in de Discord-widget; controleer ook het kamer-ID.');
        }

        $inRoom = array_values(array_filter(
            (array) ($data['members'] ?? []),
            static fn($m): bool => is_array($m) && (string) ($m['channel_id'] ?? '') === $channelId
        ));
        $count     = count($inRoom);
        $roomName  = htmlspecialchars((string) ($room['name'] ?? 'Kamer'), ENT_QUOTES, 'UTF-8');
        $guildName = htmlspecialchars((string) ($data['name'] ?? 'Discord Server'), ENT_QUOTES, 'UTF-8');
        // Totaal online volgens Discord: laat zien of de widget de bezoeker überhaupt ziet.
        $online    = isset($data['presence_count']) && is_numeric($data['presence_count']) ? (int) $data['presence_count'] : null;
        $onlineTxt = $online !== null ? " · {$online} online" : '';

        $membersHtml = '';
        foreach (array_slice($inRoom, 0, 25) as $member) {
            $avatar = (string) ($member['avatar_url'] ?? '');
            $avatar = str_starts_with($avatar, 'https://') ? htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8') : '';
            $name   = htmlspecialchars((string) ($member['username'] ?? 'Onbekend'), ENT_QUOTES, 'UTF-8');
            $img    = $avatar !== '' ? "<img src=\"{$avatar}\" alt=\"{$name}\" class=\"cf-discord-avatar\" loading=\"lazy\">" : '';
            $membersHtml .= "<div class=\"cf-discord-member\"><div class=\"cf-discord-avatar-wrap\">{$img}</div>"
                          . "<span class=\"cf-discord-username\">{$name}</span></div>";
        }
        if ($membersHtml === '') {
            $membersHtml = '<p style="color:var(--muted);font-size:.8rem;margin:.4rem 0 0;">Er zit nu niemand in deze kamer.</p>';
        }

        $invite = trim((string) ($config['invite_url'] ?? ''));
        if ($invite === '' || !str_starts_with($invite, 'https://')) {
            $invite = (string) ($data['instant_invite'] ?? '');
        }
        $inviteBtn = str_starts_with($invite, 'https://')
            ? '<a href="' . htmlspecialchars($invite, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" class="cf-btn" '
              . 'style="width:100%;justify-content:center;margin-top:.8rem;font-size:.8rem;">🎮 Server joinen</a>'
            : '';

        return <<<HTML
        <div class="cf-discord-online cf-discord-room">
            <div class="cf-discord-header">
                <span class="cf-discord-logo">🔊</span>
                <div>
                    <div class="cf-discord-guild">{$roomName}</div>
                    <div class="cf-discord-count"><span class="cf-discord-dot"></span> {$count} in de kamer{$onlineTxt} · {$guildName}</div>
                </div>
            </div>
            <div class="cf-discord-members">{$membersHtml}</div>
            {$inviteBtn}
        </div>
        HTML;
    }

    public function getCacheTtl(): int { return 0; } // Widget laadt zichzelf via iframe; widget.json is al 60s gecachet
}
