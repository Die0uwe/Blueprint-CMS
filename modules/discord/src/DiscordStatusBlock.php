<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Core\Cache\CacheManager;

/**
 * Discord Status Block — widget-vrij alternatief.
 *
 * Gebruikt de bot-token (GET /guilds/{id}?with_counts=true) voor servernaam, aantal leden en aantal
 * online leden. Dat werkt ook als de server-widget in Discord UIT staat. Vereist een bot-token en
 * dat de bot in de server zit (zie Beheer → Discord → Status). Resultaat 5 min gecachet, alleen bij
 * succes, zodat een net gerepareerde instelling meteen werkt.
 */
final class DiscordStatusBlock extends AbstractBlock
{
    private const TTL = 300;

    public function __construct(
        private readonly DiscordStore  $store,
        private readonly CacheManager  $cache,
        private readonly ?DiscordTransport $transport = null,
    ) {}

    public function getSlug(): string { return 'discord-status'; }
    public function getName(): string { return 'Discord Status (zonder widget)'; }

    public function getConfigSchema(): array
    {
        return [
            'server_id'   => ['type' => 'string', 'label' => 'Server ID (leeg = Guild ID uit de Discord-moduleinstellingen)', 'required' => false,
                              'pattern' => '/^\\d{15,25}$/', 'pattern_msg' => 'Een Discord Server ID bestaat uit 15–25 cijfers.',
                              'help' => 'Werkt met het Bot Token; de server-widget hoeft niet aan te staan. Controleer de verbinding via Beheer → Discord → Status.'],
            'show_online' => ['type' => 'boolean', 'label' => 'Aantal online leden tonen', 'default' => true],
            'invite_url'  => ['type' => 'url', 'label' => 'Uitnodigingslink (optioneel, https://discord.gg/…)'],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $serverId = trim((string) (($config['server_id'] ?? '') ?: $this->store->guildId()));
        if (!DiscordApi::isSnowflake($serverId)) {
            return $this->note('⚠️ Discord Server ID niet (goed) ingesteld.');
        }
        $token = $this->store->botToken();
        if ($token === '') {
            return $this->note('⚠️ Discord-status is nog niet ingesteld (Bot Token ontbreekt).');
        }

        $key  = "discord.status.{$serverId}";
        $data = $this->cache->get($key);
        $errKey = "discord.status.err.{$serverId}";
        if (!is_array($data) || !isset($data['name'])) {
            $failed = $this->cache->get($errKey);
            if (is_string($failed) && $failed !== '') {
                return $this->note('🔌 ' . $failed);   // kort negatief gecachet: geen Discord-call per paginaweergave
            }
            try {
                $g = (new DiscordApi($token, $this->transport))->getGuild($serverId);
            } catch (DiscordApiException $e) {
                error_log('Discord-statusblok: ' . $e->getMessage());
                $why = match ($e->status) {
                    401 => 'Het Discord-bottoken is ongeldig.',
                    403, 404 => 'De bot heeft geen toegang tot deze server (staat hij er wel in?).',
                    429 => 'Discord is tijdelijk druk; probeer het zo opnieuw.',
                    default => 'Discord is nu niet bereikbaar.',
                };
                $this->cache->set($errKey, $why, 45);   // fouten alleen kort cachen (stampede/rate-limit-bescherming)
                return $this->note('🔌 ' . $why);
            }
            $data = [
                'name'    => (string) ($g['name'] ?? 'Discord-server'),
                'members' => isset($g['approximate_member_count']) ? (int) $g['approximate_member_count'] : null,
                'online'  => isset($g['approximate_presence_count']) ? (int) $g['approximate_presence_count'] : null,
            ];
            $this->cache->set($key, $data, self::TTL);
        }

        $e      = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines  = '';
        $data += ['members' => null, 'online' => null];
        if ($data['members'] !== null) {
            $lines .= '<div class="cf-discord-count">👥 ' . number_format((int) $data['members'], 0, ',', '.') . ' leden</div>';
        }
        if (($config['show_online'] ?? true) && $data['online'] !== null) {
            $lines .= '<div class="cf-discord-count"><span class="cf-discord-dot"></span> ' . number_format((int) $data['online'], 0, ',', '.') . ' online</div>';
        }
        $invite = self::safeInvite((string) ($config['invite_url'] ?? ''));
        $btn = $invite !== null
            ? '<a href="' . $e($invite) . '" target="_blank" rel="noopener noreferrer" class="cf-btn" style="width:100%;justify-content:center;margin-top:.8rem;font-size:.8rem;">🎮 Server joinen</a>'
            : '';

        return '<div class="cf-discord-online"><div class="cf-discord-header"><span class="cf-discord-logo">🎮</span><div>'
             . '<div class="cf-discord-guild">' . $e($data['name']) . '</div>' . $lines . '</div></div>' . $btn . '</div>';
    }

    /** Alleen https-uitnodigingslinks naar Discord zelf. */
    public static function safeInvite(string $url): ?string
    {
        $url = trim($url);
        return preg_match('#^https://(discord\.gg|discord\.com/invite|discordapp\.com/invite)/[A-Za-z0-9_-]{2,40}$#D', $url) === 1 ? $url : null;
    }

    private function note(string $msg): string
    {
        return '<p style="color:var(--muted);font-size:.85rem;">' . htmlspecialchars($msg, ENT_QUOTES) . '</p>';
    }

    public function getCacheTtl(): int { return 0; } // de gegevens worden zelf 5 min gecachet (alleen bij succes)
}
