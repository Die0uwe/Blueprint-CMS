<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Kick;

use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Core\Cache\CacheManager;

/**
 * Kick Live Status Block — naar het bewezen TwitchLiveBlock-patroon.
 */
final class KickLiveBlock extends AbstractBlock
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly array        $moduleConfig = [],
    ) {}

    public function getSlug(): string { return 'kick-live'; }
    public function getName(): string { return 'Kick Live Status'; }

    public function getConfigSchema(): array
    {
        return [
            'channel'     => ['type' => 'string',  'label' => 'Kick kanaalnaam (slug)'],
            'show_viewer' => ['type' => 'boolean', 'label' => 'Kijkers tonen',  'default' => true],
            'show_game'   => ['type' => 'boolean', 'label' => 'Categorie tonen', 'default' => true],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $channel = $config['channel'] ?: ($this->moduleConfig['channel_slug'] ?? '');
        if (empty($channel)) {
            return '<p style="color:var(--muted);font-size:.85rem;">⚠️ Geen Kick kanaal ingesteld.</p>';
        }

        $cacheKey = "kick.live." . strtolower($channel);
        $data     = $this->cache->remember($cacheKey, 90, function () use ($channel) {
            return (new KickApi())->getChannel($channel);
        });

        $channelUrl  = "https://kick.com/" . rawurlencode($channel);
        $channelSafe = htmlspecialchars($channel);

        if ($data === null || empty($data['live'])) {
            // OFFLINE (of kanaal niet gevonden/API onbereikbaar — zelfde
            // nette weergave, geen onderscheid nodig voor de bezoeker)
            return <<<HTML
            <div class="cf-kick-block cf-kick-offline">
                <div class="cf-kick-header">
                    <span class="cf-kick-logo">🟢</span>
                    <div>
                        <a href="{$channelUrl}" target="_blank" rel="noopener" class="cf-kick-channel">{$channelSafe}</a>
                        <div class="cf-kick-status offline">⚫ Offline</div>
                    </div>
                </div>
            </div>
            HTML;
        }

        // LIVE
        $live      = $data['live'];
        $title     = htmlspecialchars($live['title'] ?? '');
        $category  = htmlspecialchars($live['category'] ?? '');
        $viewers   = number_format((int) ($live['viewerCount'] ?? 0));
        $thumbSafe = htmlspecialchars($live['thumbnail'] ?? '', ENT_QUOTES);

        $viewerHtml = ($config['show_viewer'] ?? true)
            ? "<span class='cf-kick-viewers'>👁️ {$viewers}</span>"
            : '';
        $gameHtml = ($config['show_game'] ?? true) && $category
            ? "<div class='cf-kick-game'>🎮 {$category}</div>"
            : '';
        $thumbHtml = $thumbSafe !== ''
            ? "<img src=\"{$thumbSafe}\" alt=\"{$channelSafe} stream\" class=\"cf-kick-thumb\" loading=\"lazy\">"
            : '';

        return <<<HTML
        <div class="cf-kick-block cf-kick-live">
            <a href="{$channelUrl}" target="_blank" rel="noopener">
                <div class="cf-kick-thumb-wrap">
                    {$thumbHtml}
                    <span class="cf-kick-live-badge">🔴 LIVE</span>
                </div>
            </a>
            <div class="cf-kick-info">
                <div class="cf-kick-header">
                    <span class="cf-kick-logo">🟢</span>
                    <div>
                        <a href="{$channelUrl}" target="_blank" rel="noopener" class="cf-kick-channel">{$channelSafe}</a>
                        <div class="cf-kick-status live">🔴 LIVE {$viewerHtml}</div>
                    </div>
                </div>
                <div class="cf-kick-title">{$title}</div>
                {$gameHtml}
            </div>
        </div>
        HTML;
    }

    public function getCacheTtl(): int { return 90; }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: KickLiveBlock.php | Role: Block | Version: 1.0.0             ║
// ║  Created: 2026-09-29 | Status: New — S10 (Kick-integratie)          ║
// ╚══════════════════════════════════════════════════════════════════════╝
