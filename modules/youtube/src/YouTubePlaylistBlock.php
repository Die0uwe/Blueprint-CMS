<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\YouTube;

use CommunityFusion\Blocks\AbstractBlock;

/**
 * YouTube Playlist Embed Block — pure iframe-embed, GEEN API-sleutel nodig
 * (net als Twitch's embed-player in TwitchStreamBlock) — youtube.com/embed
 * accepteert een playlist-ID rechtstreeks zonder authenticatie.
 */
final class YouTubePlaylistBlock extends AbstractBlock
{
    public function __construct(private readonly array $moduleConfig = []) {}

    public function getSlug(): string { return 'youtube-playlist'; }
    public function getName(): string { return 'YouTube Playlist Embed'; }

    public function getConfigSchema(): array
    {
        return [
            'playlist_id' => ['type' => 'string',  'label' => 'Playlist-ID (PL...)'],
            'height'      => ['type' => 'integer', 'label' => 'Hoogte (px)', 'default' => 360],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $playlistId = htmlspecialchars($config['playlist_id'] ?? '', ENT_QUOTES);
        if (empty($playlistId)) {
            return '<p style="color:var(--muted);font-size:.85rem;">⚠️ Geen playlist-ID ingesteld.</p>';
        }

        $height = max(200, min(800, (int) ($config['height'] ?? 360)));

        return <<<HTML
        <div class="cf-youtube-embed-wrap">
            <iframe
                src="https://www.youtube.com/embed/videoseries?list={$playlistId}"
                height="{$height}"
                width="100%"
                frameborder="0"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                allowfullscreen
                style="border-radius:8px;">
            </iframe>
        </div>
        HTML;
    }

    public function getCacheTtl(): int { return 3600; } // Statische embed, playlist-inhoud verandert zelden
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: YouTubePlaylistBlock.php | Role: Core | Version: 1.0.0       ║
// ║  Created: 2026-09-29 | Status: New — Golf 10a                       ║
// ╚══════════════════════════════════════════════════════════════════════╝
