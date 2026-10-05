<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

use CommunityFusion\Core\Application;
use CommunityFusion\Core\Module\ModuleInterface;
use CommunityFusion\Core\Block\BlockRegistry;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Hook\HookManager;

final class DiscordModule implements ModuleInterface
{
    private Application $app;

    public function getSlug(): string { return 'discord'; }

    public function boot(Application $app): void
    {
        $this->app = $app;
        $hooks     = $app->make(HookManager::class);
        $db        = $app->make(Connection::class);
        $cache     = $app->make(CacheManager::class);
        $registry  = $app->make(BlockRegistry::class);

        // Registreer block types
        $registry->register(new DiscordWidgetBlock($this->getConfig(), $cache));
        $registry->register(new DiscordOnlineBlock($db, $cache, $this->getConfig()));

        // Hook: synchroniseer Discord rollen bij login
        $hooks->addAction('user.login', function(array $user) use ($db, $cache) {
            $this->scheduleRoleSync((int) $user['id'], $db, $cache);
        });

        // Registreer OAuth routes (worden door Router opgepakt via hook)
        $hooks->addAction('router.routes', function($router) {
            $router->get('/auth/discord',          'CommunityFusion\Modules\Discord\DiscordOAuthController@redirect');
            $router->get('/auth/discord/login',    'CommunityFusion\Modules\Discord\DiscordOAuthController@loginRedirect');
            $router->get('/auth/discord/callback', 'CommunityFusion\Modules\Discord\DiscordOAuthController@callback');
            $router->post('/auth/discord/disconnect', 'CommunityFusion\Modules\Discord\DiscordOAuthController@disconnect');
        });
    }

    public function install(): void
    {
        // Extra DB-tabellen voor Discord module
        $db = $this->app->make(Connection::class);

        $db->execute("
            CREATE TABLE IF NOT EXISTS `cf_discord_role_mapping` (
                `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `discord_role_id` VARCHAR(30) NOT NULL COMMENT 'Discord Role ID (snowflake)',
                `cms_role_id`   SMALLINT UNSIGNED NOT NULL,
                `auto_remove`   TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Verwijder CMS-rol als Discord-rol weg is',
                `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_discord_role` (`discord_role_id`),
                CONSTRAINT `fk_drm_cms_role` FOREIGN KEY (`cms_role_id`) REFERENCES `cf_roles`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->execute("
            CREATE TABLE IF NOT EXISTS `cf_discord_sync_log` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`     INT UNSIGNED NOT NULL,
                `action`      VARCHAR(50) NOT NULL,
                `detail`      TEXT NULL,
                `synced_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function uninstall(): void {}

    public function getBlocks(): array
    {
        return ['discord-widget', 'discord-online'];
    }

    private function getConfig(): array
    {
        // Laad module settings uit cf_settings (gecached door SettingsRepository)
        try {
            $db   = $this->app->make(Connection::class);
            $rows = $db->fetchAll("SELECT `key`, `value` FROM cf_settings WHERE `group` = 'discord'");
            $cfg  = [];
            foreach ($rows as $r) $cfg[$r['key']] = $r['value'];
            return $cfg;
        } catch (\Throwable) {
            return [];
        }
    }

    private function scheduleRoleSync(int $userId, Connection $db, CacheManager $cache): void
    {
        // Voeg een sync-job toe aan de queue
        try {
            $db->execute(
                "INSERT INTO cf_queue_jobs (queue, payload, available_at, created_at)
                 VALUES ('discord-sync', ?, NOW(), NOW())",
                [serialize(['user_id' => $userId])]
            );
        } catch (\Throwable) {
            // Queue niet beschikbaar — negeren
        }
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: DiscordModule.php | Role: Core | Version: 1.0.0              ║
// ║  Created: 2026-06-06 | Status: New                                  ║
// ╚══════════════════════════════════════════════════════════════════════╝
