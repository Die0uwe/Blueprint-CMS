<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\YouTube;

use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Core\Cache\CacheManager;

/**
 * YouTube Latest Videos Block — grid van de laatste N uploads.
 */
final class YouTubeLatestVideosBlock extends AbstractBlock
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly array        $moduleConfig = [],
    ) {}

    public function getSlug(): string { return 'youtube-latest'; }
    public function getName(): string { return 'YouTube Laatste Video\'s'; }

    public function getConfigSchema(): array
    {
        return [
            'channel_id' => ['type' => 'string',  'label' => 'YouTube kanaal-ID (UC...)'],
            'count'      => ['type' => 'integer', 'label' => 'Aantal video\'s', 'default' => 4],
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

        $count    = max(1, min(12, (int) ($config['count'] ?? 4)));
        $cacheKey = "youtube.latest.{$channelId}.{$count}";
        $videos   = $this->cache->remember($cacheKey, 900, function() use ($apiKey, $channelId, $count) {
            return (new YouTubeApi($apiKey))->getLatestVideos($channelId, $count);
        });

        if (empty($videos)) {
            return '<p style="color:var(--muted);font-size:.85rem;">Geen video\'s gevonden.</p>';
        }

        $items = '';
        foreach ($videos as $v) {
            $title = htmlspecialchars($v['title']);
            $thumb = htmlspecialchars($v['thumbnail'], ENT_QUOTES);
            $url   = htmlspecialchars($v['url'], ENT_QUOTES);
            $items .= <<<HTML
            <a href="{$url}" target="_blank" rel="noopener" class="cf-youtube-video-card">
                <img src="{$thumb}" alt="{$title}" loading="lazy">
                <span class="cf-youtube-video-title">{$title}</span>
            </a>
            HTML;
        }

        return "<div class=\"cf-youtube-block cf-youtube-latest\">{$items}</div>";
    }

    public function getCacheTtl(): int { return 900; }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: YouTubeLatestVideosBlock.php | Role: Core | Version: 1.0.0   ║
// ║  Created: 2026-09-29 | Status: New — Golf 10a                       ║
// ╚══════════════════════════════════════════════════════════════════════╝
