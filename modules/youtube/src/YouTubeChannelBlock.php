<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\YouTube;

use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Core\Cache\CacheManager;

/**
 * YouTube Channel Info Block — kanaaltitel, thumbnail, abonnee-/video-count.
 */
final class YouTubeChannelBlock extends AbstractBlock
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly array        $moduleConfig = [],
    ) {}

    public function getSlug(): string { return 'youtube-channel'; }
    public function getName(): string { return 'YouTube Kanaalinfo'; }

    public function getConfigSchema(): array
    {
        return [
            'channel_id'    => ['type' => 'string',  'label' => 'YouTube kanaal-ID (UC...)'],
            'show_counts'   => ['type' => 'boolean', 'label' => 'Abonnees/video\'s tonen', 'default' => true],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $channelId = $config['channel_id'] ?: ($this->moduleConfig['channel_id'] ?? '');
        if (empty($channelId)) {
            return '<p style="color:var(--muted);font-size:.85rem;">⚠️ Geen YouTube kanaal-ID ingesteld.</p>';
        }

        $apiKey = $this->moduleConfig['api_key'] ?? '';
        if (empty($apiKey)) {
            return '<p style="color:var(--muted);font-size:.85rem;">⚠️ Geen YouTube API-sleutel ingesteld — zie /admin/marketplace/package/youtube/instellingen.</p>';
        }

        $cacheKey = "youtube.channel." . $channelId;
        $channel  = $this->cache->remember($cacheKey, 1800, function() use ($apiKey, $channelId) {
            return (new YouTubeApi($apiKey))->getChannel($channelId);
        });

        if ($channel === null) {
            return '<p style="color:var(--muted);font-size:.85rem;">Kanaal niet gevonden.</p>';
        }

        $title   = htmlspecialchars($channel['title']);
        $thumb   = htmlspecialchars($channel['thumbnail'], ENT_QUOTES);
        $url     = 'https://www.youtube.com/channel/' . urlencode($channelId);
        $subs    = number_format($channel['subscriberCount']);
        $videos  = number_format($channel['videoCount']);

        $countsHtml = ($config['show_counts'] ?? true)
            ? "<div class=\"cf-youtube-counts\">👥 {$subs} abonnees · 🎬 {$videos} video's</div>"
            : '';

        return <<<HTML
        <div class="cf-youtube-block cf-youtube-channel">
            <a href="{$url}" target="_blank" rel="noopener" class="cf-youtube-header">
                <img src="{$thumb}" alt="{$title}" class="cf-youtube-avatar" loading="lazy">
                <div>
                    <div class="cf-youtube-title">▶️ {$title}</div>
                    {$countsHtml}
                </div>
            </a>
        </div>
        HTML;
    }

    public function getCacheTtl(): int { return 1800; }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: YouTubeChannelBlock.php | Role: Core | Version: 1.0.0        ║
// ║  Created: 2026-09-29 | Status: New — Golf 10a                       ║
// ╚══════════════════════════════════════════════════════════════════════╝
