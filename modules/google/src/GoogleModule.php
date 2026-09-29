<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Google;

use CommunityFusion\Core\Application;
use CommunityFusion\Core\Module\ModuleInterface;
use CommunityFusion\Core\Hook\HookManager;

final class GoogleModule implements ModuleInterface
{
    public function getSlug(): string { return 'google'; }

    public function boot(Application $app): void
    {
        $hooks = $app->make(HookManager::class);

        // Registreer OAuth routes (worden door Router opgepakt via hook)
        $hooks->addAction('router.routes', function($router) {
            $router->get('/auth/google',             'CommunityFusion\Modules\Google\GoogleOAuthController@redirect');
            $router->get('/auth/google/login',       'CommunityFusion\Modules\Google\GoogleOAuthController@loginRedirect');
            $router->get('/auth/google/callback',    'CommunityFusion\Modules\Google\GoogleOAuthController@callback');
            $router->post('/auth/google/disconnect', 'CommunityFusion\Modules\Google\GoogleOAuthController@disconnect');
        });
    }

    public function install(): void {}

    public function uninstall(): void {}

    public function getBlocks(): array
    {
        return [];
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: GoogleModule.php | Role: Core | Version: 1.0.0               ║
// ║  Created: 2026-09-29 | Status: New — Golf 10 (OAuth providers)      ║
// ║  Notes: Google login module — geen blocks, geen extra DB-tabellen   ║
// ╚══════════════════════════════════════════════════════════════════════╝
