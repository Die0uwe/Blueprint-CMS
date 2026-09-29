<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\YouTube;

use CommunityFusion\Core\Application;
use CommunityFusion\Core\Module\ModuleInterface;
use CommunityFusion\Core\Block\BlockRegistry;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Security\Crypto;

final class YouTubeModule implements ModuleInterface
{
    private Application $app;

    public function getSlug(): string { return 'youtube'; }

    public function boot(Application $app): void
    {
        $this->app = $app;
        $db        = $app->make(Connection::class);
        $cache     = $app->make(CacheManager::class);
        $registry  = $app->make(BlockRegistry::class);

        $config = $this->getConfig($db);

        $registry->register(new YouTubeChannelBlock($cache, $config));
        $registry->register(new YouTubeLatestVideosBlock($cache, $config));
        $registry->register(new YouTubeLiveBlock($cache, $config));
        $registry->register(new YouTubePlaylistBlock($config));

        // Geen OAuth-routes: de YouTube Data API werkt met een server-side
        // API-sleutel (zie YouTubeApi.php), geen gebruikers-login-flow zoals
        // Discord/Twitch/Google/Battle.net.
    }

    public function install(): void {}
    public function uninstall(): void {}
    public function getBlocks(): array
    {
        return ['youtube-channel', 'youtube-latest', 'youtube-live', 'youtube-playlist'];
    }

    /**
     * Golf 10a: net als DiscordModule/TwitchModule::getConfig() — maar met
     * de encrypted-aware decrypt uit Golf 10 (Core\Security\Crypto), want
     * 'api_key' staat in module.json als type 'encrypted'.
     */
    private function getConfig(Connection $db): array
    {
        try {
            $rows = $db->fetchAll("SELECT `key`, `value`, `type` FROM cf_settings WHERE `group` = 'youtube'");
            $cfg  = [];
            foreach ($rows as $r) {
                $cfg[$r['key']] = ($r['type'] === 'encrypted' && $r['value'] !== '')
                    ? Crypto::decrypt($r['value'])
                    : $r['value'];
            }
            return $cfg;
        } catch (\Throwable) {
            return [];
        }
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: YouTubeModule.php | Role: Core | Version: 1.0.0              ║
// ║  Created: 2026-09-29 | Status: New — Golf 10a                       ║
// ╚══════════════════════════════════════════════════════════════════════╝
