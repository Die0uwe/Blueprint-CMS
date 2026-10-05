<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
//
// This work is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This work is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
// ============================================================================

declare(strict_types=1);
namespace CommunityFusion\Blocks;

abstract class AbstractBlock implements BlockInterface
{
    /**
     * Naam van het block type — implementeer in concrete class.
     */
    abstract public function getName(): string;
    public function validateConfig(array $config): void {}
    public function getCacheTtl(): int { return 0; }

    /**
     * Mag dit blok aan deze bezoeker getoond worden? Standaard altijd.
     *
     * Wordt per verzoek aangeroepen bij het opbouwen van de layout-zones (dus ná de
     * gedeelde zone-cache), zodat een blok zich kan verbergen zonder dat er een lege
     * wrapper-div overblijft. Voorbeeld: het login-blok is alleen voor gasten.
     *
     * @param array<string, mixed> $config   de blok-configuratie
     * @param bool                 $loggedIn is de bezoeker ingelogd?
     */
    public function isVisibleFor(array $config, bool $loggedIn): bool { return true; }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : AbstractBlock.php                                    ║
// ║  Role         : Core                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-06-06                                           ║
// ║  Last Updated : 2026-06-06  03:00                                    ║
// ║  Status       : New                                                  ║
// ║  Notes        : Abstract base block implementatie                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
