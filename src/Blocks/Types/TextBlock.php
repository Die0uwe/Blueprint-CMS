<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Blocks\Types;
use CommunityFusion\Blocks\AbstractBlock;

final class TextBlock extends AbstractBlock
{
    public function getSlug(): string { return 'text'; }
    public function getName(): string { return 'Tekst Blok'; }

    public function getConfigSchema(): array
    {
        return [
            'content' => ['type' => 'richtext', 'label' => 'Inhoud', 'required' => true],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        // Editor-HTML wordt gesanitized; oude platte tekst wordt geëscaped (regeleinden blijven).
        $content = \CommunityFusion\Core\Security\ContentSanitizer::renderRich((string) ($config['content'] ?? ''));
        return "<div class=\"cf-block-text\">{$content}</div>";
    }

    public function getCacheTtl(): int { return 3600; }
}
