<?php
// ============================================================================
// Voorbeeldplugin — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Plugins\ExampleHelloWorld;

use CommunityFusion\Blocks\AbstractBlock;

final class HelloBlock extends AbstractBlock
{
    public function getSlug(): string { return 'example-hello'; }
    public function getName(): string { return 'Hallo-blok (voorbeeld)'; }

    public function getConfigSchema(): array
    {
        return ['name' => ['type' => 'string', 'label' => 'Naam', 'default' => 'wereld']];
    }

    public function render(array $config, array $context = []): string
    {
        return '<p>Hallo, ' . htmlspecialchars((string)($config['name'] ?? 'wereld'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '!</p>';
    }
}
