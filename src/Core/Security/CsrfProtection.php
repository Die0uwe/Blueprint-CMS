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
namespace CommunityFusion\Core\Security;

use CommunityFusion\Core\HttpException;

final class CsrfProtection
{
    public static function getToken(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }
    public static function verify(string $token): bool
    {
        return hash_equals($_SESSION['_csrf_token'] ?? '', $token);
    }
    public static function field(): string
    {
        $token = htmlspecialchars(self::getToken(), ENT_QUOTES);
        return "<input type=\"hidden\" name=\"_csrf_token\" value=\"{$token}\">";
    }
    public static function validateRequest(): void
    {
        $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!self::verify($token)) {
            // Was \RuntimeException(..., 403) — zelfde bug als AuthManager::authorize()
            // had vóór HttpException bestond (zie dat bestand): Application::handleException()
            // herkent alleen `instanceof HttpException`, dus een kale RuntimeException kwam
            // altijd als generieke 500 naar buiten, ongeacht de meegegeven code. Elke CSRF-
            // afwijzing in de hele app (elk formulier met CsrfProtection::validateRequest())
            // gaf hierdoor een 500 i.p.v. een nette 403.
            throw new HttpException('Ongeldige CSRF token.', 403);
        }
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : CsrfProtection.php                                   ║
// ║  Role         : Core                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-06-06                                           ║
// ║  Last Updated : 2026-06-06  03:00                                    ║
// ║  Status       : New                                                  ║
// ║  Notes        : CSRF token generatie + validatie                     ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
