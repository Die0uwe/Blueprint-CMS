<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\BattleNet;

use CommunityFusion\Core\Application;
use CommunityFusion\Core\Module\ModuleInterface;
use CommunityFusion\Core\Hook\HookManager;

final class BattleNetModule implements ModuleInterface
{
    public function getSlug(): string { return 'battlenet'; }

    public function boot(Application $app): void
    {
        $hooks = $app->make(HookManager::class);

        // Registreer OAuth routes (worden door Router opgepakt via hook)
        $hooks->addAction('router.routes', function ($router) {
            $router->get('/auth/battlenet',             'CommunityFusion\Modules\BattleNet\BattleNetOAuthController@redirect');
            $router->get('/auth/battlenet/login',       'CommunityFusion\Modules\BattleNet\BattleNetOAuthController@loginRedirect');
            $router->get('/auth/battlenet/callback',    'CommunityFusion\Modules\BattleNet\BattleNetOAuthController@callback');
            $router->post('/auth/battlenet/disconnect', 'CommunityFusion\Modules\BattleNet\BattleNetOAuthController@disconnect');
        });
    }

    public function install(): void {}
    public function uninstall(): void {}
    public function getBlocks(): array { return []; }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: BattleNetModule.php | Role: Core | Version: 1.0.0            ║
// ║  Created: 2026-09-29 | Status: New — Golf 10 (OAuth providers)      ║
// ╚══════════════════════════════════════════════════════════════════════╝
