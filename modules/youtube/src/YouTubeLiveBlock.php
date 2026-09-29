<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\YouTube;

use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Core\Cache\CacheManager;

/**
 * YouTube Live Status Block — zelfde soort kaart als TwitchLiveBlock, maar
 * met een langere cache-TTL (zie YouTubeApi::getLiveStream() voor waarom:
 * search.list met eventType=live kost 100x zoveel API-quota als de meeste
 * andere YouTube Data API-aanroepen).
 */
final class YouTubeLiveBlock extends AbstractBlock
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly array        $moduleConfig = [],
    ) {}

    public function getSlug(): string { return 'youtube-live'; }
    public function getName(): string { return 'YouTube Live Status'; }

    public function getConfigSchema(): array
    {
        return [
            'channel_id' => ['type' => 'string', 'label' => 'YouTube kanaal-ID (UC...)'],
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
            return '<p style="color:var(--muted);font-size:.85rem;">⚠️ Geen YouTube API-sleutel ingesteld.</p>';
        }

        $cacheKey = "youtube.live.{$channelId}";
        $live     = $this->cache->remember($cacheKey, 300, function() use ($apiKey, $channelId) {
            return (new YouTubeApi($apiKey))->getLiveStream($channelId);
        });

        $channelUrl = 'https://www.youtube.com/channel/' . urlencode($channelId);

        if ($live === null) {
            return <<<HTML
            <div class="cf-youtube-block cf-youtube-offline">
                <span class="cf-youtube-logo">▶️</span>
                <a href="{$channelUrl}" target="_blank" rel="noopener" class="cf-youtube-status offline">⚫ Niet live</a>
            </div>
            HTML;
        }

        $title    = htmlspecialchars($live['title']);
        $thumb    = htmlspecialchars($live['thumbnail'], ENT_QUOTES);
        $watchUrl = 'https://www.youtube.com/watch?v=' . urlencode($live['videoId']);

        return <<<HTML
        <div class="cf-youtube-block cf-youtube-live">
            <a href="{$watchUrl}" target="_blank" rel="noopener" class="cf-youtube-thumb-wrap">
                <img src="{$thumb}" alt="{$title}" class="cf-youtube-thumb" loading="lazy">
                <span class="cf-youtube-live-badge">🔴 LIVE</span>
            </a>
            <div class="cf-youtube-video-title">{$title}</div>
        </div>
        HTML;
    }

    public function getCacheTtl(): int { return 300; }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: YouTubeLiveBlock.php | Role: Core | Version: 1.0.0           ║
// ║  Created: 2026-09-29 | Status: New — Golf 10a                       ║
// ╚══════════════════════════════════════════════════════════════════════╝
