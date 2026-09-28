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

namespace CommunityFusion\Api\Middleware;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;

/**
 * Rol/permissie-gate bovenop AuthMiddleware (die alleen "ingelogd?" checkt).
 *
 * Wave 0/1 gap-analyse: /admin-routes waren login-gated maar niet
 * role-gated — elk lid dat kon inloggen kon bij /admin. Router::registerCoreRoutes()
 * gebruikte overal alleen `$auth` (AuthMiddleware), nooit een permissie-check.
 *
 * Gebruik: hang deze middleware achter AuthMiddleware in de route-array met
 * een "ClassName:permissie.naam"-string, bv.:
 *   [...$auth, 'CommunityFusion\Api\Middleware\PermissionMiddleware:settings.edit']
 * Router::buildPipeline() parseert het deel na ':' en geeft het door als
 * derde argument aan handle().
 */
final class PermissionMiddleware
{
    public function __construct(
        private readonly AuthManager $auth,
    ) {}

    public function handle(Request $request, callable $next, string $permission = ''): Response
    {
        // Geen permissie geconfigureerd op deze route — niets te controleren.
        // (Zou een configuratiefout zijn; faalt open i.p.v. elke ongeconfigureerde
        // route te blokkeren, maar AuthMiddleware heeft al op "ingelogd" gecheckt.)
        if ($permission === '') {
            return $next($request);
        }

        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=' . urlencode($request->getPath()));
        }

        if (!$this->auth->can($permission)) {
            if ($request->isJson() || $request->isAjax()) {
                return Response::json(['error' => "Toegang geweigerd: '{$permission}' vereist."], 403);
            }
            return Response::html(
                '<h1>403 — Geen toegang</h1><p>Je account heeft de permissie <code>' .
                htmlspecialchars($permission) . '</code> niet.</p>',
                403
            );
        }

        return $next($request);
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : PermissionMiddleware.php                             ║
// ║  Role         : Core                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-09-28                                           ║
// ║  Status       : New — Wave 2 (/admin permission-gating)              ║
// ║  Notes        : Rol/permissie-check bovenop AuthMiddleware           ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
