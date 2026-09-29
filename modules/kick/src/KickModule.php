<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Kick;

use CommunityFusion\Core\Application;
use CommunityFusion\Core\Module\ModuleInterface;
use CommunityFusion\Core\Block\BlockRegistry;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;

final class KickModule implements ModuleInterface
{
    private Application $app;

    public function getSlug(): string { return 'kick'; }

    public function boot(Application $app): void
    {
        $this->app = $app;
        $db        = $app->make(Connection::class);
        $cache     = $app->make(CacheManager::class);
        $registry  = $app->make(BlockRegistry::class);

        $config = $this->getConfig($db);

        $registry->register(new KickLiveBlock($cache, $config));
        $registry->register(new KickStreamBlock($config));

        // Geen OAuth-routes: net als YouTube gebruikt deze module alleen
        // publieke, ongeauthenticeerde kanaalgegevens (zie KickApi.php voor
        // waarom) — geen gebruikers-login-flow zoals Discord/Twitch/Google/
        // Battle.net uit Golf 10.
    }

    public function install(): void {}
    public function uninstall(): void {}
    public function getBlocks(): array
    {
        return ['kick-live', 'kick-stream'];
    }

    private function getConfig(Connection $db): array
    {
        try {
            $rows = $db->fetchAll("SELECT `key`, `value` FROM cf_settings WHERE `group` = 'kick'");
            $cfg  = [];
            foreach ($rows as $r) $cfg[$r['key']] = $r['value'];
            return $cfg;
        } catch (\Throwable) {
            return [];
        }
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: KickModule.php | Role: Core | Version: 1.0.0                 ║
// ║  Created: 2026-09-29 | Status: New — S10 (Kick-integratie)          ║
// ╚══════════════════════════════════════════════════════════════════════╝
