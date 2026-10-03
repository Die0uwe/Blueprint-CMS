<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\GitHub;

use CommunityFusion\Core\Application;
use CommunityFusion\Core\Hook\HookManager;
use CommunityFusion\Core\Module\ModuleInterface;

final class GitHubModule implements ModuleInterface
{
    public function getSlug(): string { return 'github'; }

    public function boot(Application $app): void
    {
        $hooks = $app->make(HookManager::class);

        $hooks->addAction('router.routes', function ($router) {
            $router->get('/auth/github',             'CommunityFusion\Modules\GitHub\GitHubOAuthController@redirect');
            $router->get('/auth/github/login',       'CommunityFusion\Modules\GitHub\GitHubOAuthController@loginRedirect');
            $router->get('/auth/github/callback',    'CommunityFusion\Modules\GitHub\GitHubOAuthController@callback');
            $router->post('/auth/github/disconnect', 'CommunityFusion\Modules\GitHub\GitHubOAuthController@disconnect');
        });
    }

    public function install(): void {}

    public function uninstall(): void {}

    public function getBlocks(): array
    {
        return [];
    }
}
