<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Kick;

use CommunityFusion\Blocks\AbstractBlock;

/**
 * Kick Stream Embed Block — embed de Kick player direct op de pagina.
 *
 * Kick's officiële embed-formaat is simpeler dan Twitch's (geen verplichte
 * `parent`-domeinwhitelist, geen aparte chat-embed-URL beschikbaar):
 * `https://player.kick.com/{kanaalnaam}`.
 */
final class KickStreamBlock extends AbstractBlock
{
    public function __construct(private readonly array $moduleConfig = []) {}

    public function getSlug(): string { return 'kick-stream'; }
    public function getName(): string { return 'Kick Stream Embed'; }

    public function getConfigSchema(): array
    {
        return [
            'channel' => ['type' => 'string',  'label' => 'Kick kanaalnaam (slug)'],
            'height'  => ['type' => 'integer', 'label' => 'Hoogte (px)', 'default' => 360],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $channel = htmlspecialchars(
            $config['channel'] ?: ($this->moduleConfig['channel_slug'] ?? ''),
            ENT_QUOTES
        );

        if (empty($channel)) {
            return '<p style="color:var(--muted);font-size:.85rem;">⚠️ Geen Kick kanaal ingesteld.</p>';
        }

        $height = max(200, min(800, (int) ($config['height'] ?? 360)));

        return <<<HTML
        <div class="cf-kick-embed-wrap">
            <iframe
                src="https://player.kick.com/{$channel}"
                height="{$height}"
                width="100%"
                frameborder="0"
                allowfullscreen
                scrolling="no"
                style="border-radius:8px;">
            </iframe>
        </div>
        HTML;
    }

    public function getCacheTtl(): int { return 0; } // Live embed, nooit cachen
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: KickStreamBlock.php | Role: Block | Version: 1.0.0           ║
// ║  Created: 2026-09-29 | Status: New — S10 (Kick-integratie)          ║
// ╚══════════════════════════════════════════════════════════════════════╝
