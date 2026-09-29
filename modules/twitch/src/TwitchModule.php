<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Twitch;

use CommunityFusion\Core\Application;
use CommunityFusion\Core\Module\ModuleInterface;
use CommunityFusion\Core\Block\BlockRegistry;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Hook\HookManager;

final class TwitchModule implements ModuleInterface
{
    private Application $app;

    public function getSlug(): string { return 'twitch'; }

    public function boot(Application $app): void
    {
        $this->app = $app;
        $db        = $app->make(Connection::class);
        $cache     = $app->make(CacheManager::class);
        $registry  = $app->make(BlockRegistry::class);
        $hooks     = $app->make(HookManager::class);

        $config = $this->getConfig($db);

        // Registreer blocks
        $registry->register(new TwitchLiveBlock($db, $cache, $config));
        $registry->register(new TwitchStreamBlock($config));

        // Twitch OAuth routes — Golf 10: /login toegevoegd (zie
        // TwitchOAuthController voor de link/login-intentie-uitleg) +
        // /disconnect, die al bestond op de controller maar nooit
        // geregistreerd was.
        $hooks->addAction('router.routes', function($router) {
            $router->get('/auth/twitch',             'CommunityFusion\Modules\Twitch\TwitchOAuthController@redirect');
            $router->get('/auth/twitch/login',       'CommunityFusion\Modules\Twitch\TwitchOAuthController@loginRedirect');
            $router->get('/auth/twitch/callback',    'CommunityFusion\Modules\Twitch\TwitchOAuthController@callback');
            $router->post('/auth/twitch/disconnect', 'CommunityFusion\Modules\Twitch\TwitchOAuthController@disconnect');
        });
    }

    public function install(): void {}
    public function uninstall(): void {}
    public function getBlocks(): array { return ['twitch-live', 'twitch-stream']; }

    private function getConfig(Connection $db): array
    {
        try {
            $rows = $db->fetchAll("SELECT `key`, `value` FROM cf_settings WHERE `group` = 'twitch'");
            $cfg  = [];
            foreach ($rows as $r) $cfg[$r['key']] = $r['value'];
            return $cfg;
        } catch (\Throwable) {
            return [];
        }
    }
}
