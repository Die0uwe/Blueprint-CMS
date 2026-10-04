<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Blocks\Types;
use CommunityFusion\Blocks\AbstractBlock;

/** Advertentie / banner blok */
final class AdBlock extends AbstractBlock
{
    public function getSlug(): string { return 'advertisement'; }
    public function getName(): string { return 'Advertentie / Banner'; }

    public function getConfigSchema(): array
    {
        return [
            'image_url'  => ['type' => 'url',    'label' => 'Afbeelding URL', 'required' => true],
            'link_url'   => ['type' => 'url',    'label' => 'Link URL'],
            'alt_text'   => ['type' => 'string', 'label' => 'Alt tekst'],
            'open_new'   => ['type' => 'boolean','label' => 'Openen in nieuw tabblad', 'default' => true],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        // Alleen http(s):// of eigen /pad: geen javascript:/data:-URL's in href of src.
        $safeUrl = static fn(mixed $u, string $fallback): string =>
            is_string($u) && preg_match('#^(https?://|/(?!/))[^\s<>"\']*$#i', trim($u)) === 1 ? trim($u) : $fallback;
        $img  = htmlspecialchars($safeUrl($config['image_url'] ?? '', ''), ENT_QUOTES);
        $link = htmlspecialchars($safeUrl($config['link_url'] ?? '', '#'), ENT_QUOTES);
        $alt  = htmlspecialchars($config['alt_text']  ?? 'Advertentie', ENT_QUOTES);
        $target = ($config['open_new'] ?? true) ? ' target="_blank" rel="noopener noreferrer sponsored"' : '';

        if (empty($img)) return '<!-- Advertentie: geen afbeelding ingesteld -->';

        return <<<HTML
        <div class="cf-block-ad">
            <a href="{$link}"{$target}>
                <img src="{$img}" alt="{$alt}" loading="lazy" style="width:100%;border-radius:8px;">
            </a>
        </div>
        HTML;
    }

    public function getCacheTtl(): int { return 3600; }
}
