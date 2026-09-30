<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Plugin;

use CommunityFusion\Core\Marketplace\ManifestValidator;

/** Kleine helper: slug-controle met PluginManager-foutmelding. */
final class ManifestSlug
{
    public static function assert(string $slug): string
    {
        return ManifestValidator::assertSlug($slug);
    }
}
