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

namespace CommunityFusion\Core;

/**
 * Exception die een specifieke HTTP-statuscode draagt naar
 * Application::handleException().
 *
 * Dit bestand bestond nooit, terwijl Application::handleException() het al
 * sinds Sprint 1 gebruikte (`$e instanceof HttpException`) en
 * AuthManager::authorize() een gewone \RuntimeException(..., 403) gooide in
 * de veronderstelling dat er zoiets als een HTTP-statuscode-exception
 * bestond. `instanceof` tegen een niet-bestaande class faalt in PHP niet
 * hard (het evalueert gewoon naar false), dus dit crashte nooit — het
 * betekende alleen dat ELKE authorize()-afwijzing als een generieke 500
 * "Er is een fout opgetreden" naar buiten kwam in plaats van een 403, zowel
 * hier als in elke toekomstige plek die op dit patroon zou vertrouwen
 * (bv. Marketplace\MarketplaceController, dat al wél `authorize()` aanriep).
 */
class HttpException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode = 403)
    {
        parent::__construct($message, $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : HttpException.php                                    ║
// ║  Role         : Core                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-09-28                                           ║
// ║  Status       : New — Wave 2 (never existed despite being referenced)║
// ║  Notes        : Fixes authorize()/handleException() 403-vs-500 bug   ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
