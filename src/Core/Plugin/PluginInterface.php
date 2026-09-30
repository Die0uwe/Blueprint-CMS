<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Plugin;

/**
 * Hoofdklasse van een plugin (plugin.json → "class").
 * boot() draait bij elke request zolang de plugin actief is, ná alle core-modules.
 */
interface PluginInterface
{
    public function boot(PluginContext $context): void;
}
