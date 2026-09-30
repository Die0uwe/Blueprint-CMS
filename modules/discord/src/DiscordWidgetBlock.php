<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

use CommunityFusion\Blocks\AbstractBlock;

/**
 * Discord Widget Block
 * Toont de officiële Discord widget iframe.
 */
final class DiscordWidgetBlock extends AbstractBlock
{
    public function __construct(private readonly array $moduleConfig = []) {}

    public function getSlug(): string { return 'discord-widget'; }
    public function getName(): string { return 'Discord Widget'; }

    public function getConfigSchema(): array
    {
        return [
            'server_id' => ['type' => 'string',  'label' => 'Server ID (leeg = Guild ID uit de Discord-moduleinstellingen)', 'required' => false,
                            'help' => 'Zet in Discord: Serverinstellingen → Widget → "Server-widget inschakelen" aan én kies een uitnodigingskanaal.'],
            'theme'     => ['type' => 'select',  'label' => 'Thema', 'options' => ['dark', 'light'], 'default' => 'dark'],
            'width'     => ['type' => 'integer', 'label' => 'Breedte (px)', 'default' => 350, 'min' => 200, 'max' => 1000],
            'height'    => ['type' => 'integer', 'label' => 'Hoogte (px)',  'default' => 500, 'min' => 200, 'max' => 1000],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $serverId = trim((string) (($config['server_id'] ?? '') ?: ($this->moduleConfig['guild_id'] ?? '')));

        if ($serverId === '') {
            return '<p style="color:var(--muted);font-size:.85rem;">⚠️ Discord Server ID niet ingesteld. Vul het Server ID in via de blok-instellingen (⚙️) of via de Discord-moduleinstellingen.</p>';
        }
        if (!preg_match('/^\d{15,25}$/', $serverId)) {
            return '<p style="color:var(--muted);font-size:.85rem;">⚠️ Ongeldig Discord Server ID (alleen cijfers, 17–19 lang).</p>';
        }

        $theme  = ($config['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
        $width  = max(200, min(1000, (int) ($config['width']  ?? 350)));
        $height = max(200, min(1000, (int) ($config['height'] ?? 500)));

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

    public function getCacheTtl(): int { return 0; } // Widget laadt zichzelf via iframe
}
