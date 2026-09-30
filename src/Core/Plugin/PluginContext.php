<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Plugin;

use CommunityFusion\Blocks\BlockInterface;
use CommunityFusion\Core\Block\BlockRegistry;
use CommunityFusion\Core\Hook\HookManager;

/**
 * PluginContext — de smalle, gedocumenteerde ingang van een plugin naar de core.
 * Gebruik dit in plaats van globals; een plugin mag de extensiepunten van de core
 * gebruiken (hooks, blocks, routes, settings) maar niets in src/Core/ wijzigen.
 */
final class PluginContext
{
    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $settings
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $dir,
        public readonly array $manifest,
        public readonly HookManager $hooks,
        private readonly BlockRegistry $blocks,
        private readonly array $settings,
    ) {}

    /** Instelling van deze plugin (of $default). */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /** Registreer een block-type van deze plugin. */
    public function registerBlock(BlockInterface $block): void
    {
        $this->blocks->register($block);
    }

    /** Registreer routes: de callback krijgt de Router. Loopt via de bestaande hook router.routes. */
    public function routes(callable $register): void
    {
        $this->hooks->addAction('router.routes', $register);
    }

    /** Naam van de eigen tabellen-prefix, bv. cf_plg_mijn_plugin_ */
    public function tablePrefix(): string
    {
        return PluginSqlGuard::tablePrefix($this->slug);
    }
}
