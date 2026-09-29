<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\Guild;

use CommunityFusion\Core\Application;
use CommunityFusion\Core\Module\ModuleInterface;
use CommunityFusion\Core\Block\BlockRegistry;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Hook\HookManager;

final class GuildModule implements ModuleInterface
{
    private Application $app;
    public function getSlug(): string { return 'guild-management'; }

    public function boot(Application $app): void
    {
        $this->app = $app;
        $db        = $app->make(Connection::class);
        $registry  = $app->make(BlockRegistry::class);
        $hooks     = $app->make(HookManager::class);

        $registry->register(new GuildMembersBlock($db));
        $registry->register(new GuildInfoBlock($db));
        $registry->register(new GuildRecruitmentBlock($db));

        $hooks->addAction('router.routes', function($router) {
            $auth = ['CommunityFusion\Api\Middleware\AuthMiddleware'];
            // KRITIEK, v1.25.9: deze 3 admin-routes hingen alleen aan $auth
            // (elke ingelogde gebruiker, dus ook een kale 'member') i.p.v.
            // een permissie — module.json declareerde 'guild.manage' al
            // sinds Sprint 5, maar niets zaaide die ooit in cf_permissions
            // (nu wél, zie schema.sql). Zonder deze fix kon elk lid guild-
            // aanmeldingen goed-/afkeuren. Zie CHANGELOG v1.25.9.
            $perm = fn(string $permission) => [...$auth, "CommunityFusion\\Api\\Middleware\\PermissionMiddleware:{$permission}"];
            $router->get('/guild',                    'CommunityFusion\Modules\Guild\GuildController@index');
            $router->get('/guild/apply',              'CommunityFusion\Modules\Guild\GuildController@applyForm');
            $router->post('/guild/apply',             'CommunityFusion\Modules\Guild\GuildController@apply');
            $router->get('/guild/members',            'CommunityFusion\Modules\Guild\GuildController@members');
            $router->get('/guild/roster',             'CommunityFusion\Modules\Guild\GuildController@roster');
            $router->get('/admin/guild',              'CommunityFusion\Modules\Guild\GuildAdminController@index', $perm('guild.manage'));
            $router->post('/admin/guild/applications/{id}/approve', 'CommunityFusion\Modules\Guild\GuildAdminController@approve', $perm('guild.manage'));
            $router->post('/admin/guild/applications/{id}/reject',  'CommunityFusion\Modules\Guild\GuildAdminController@reject', $perm('guild.manage'));
        });
    }

    public function install(): void
    {
        $db = $this->app->make(Connection::class);

        $db->execute("CREATE TABLE IF NOT EXISTS `cf_guild_ranks` (
            `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`        VARCHAR(100) NOT NULL,
            `display_name` VARCHAR(100) NOT NULL,
            `color`       VARCHAR(7) NULL COMMENT 'Hex kleur',
            `priority`    SMALLINT NOT NULL DEFAULT 0,
            `permissions` JSON NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->execute("CREATE TABLE IF NOT EXISTS `cf_guild_members` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`     INT UNSIGNED NULL COMMENT 'NULL = extern lid (niet geregistreerd)',
            `rank_id`     SMALLINT UNSIGNED NULL,
            `character_name` VARCHAR(100) NOT NULL,
            `class`       VARCHAR(50) NULL,
            `spec`        VARCHAR(50) NULL,
            `item_level`  SMALLINT UNSIGNED NULL,
            `note`        TEXT NULL,
            `joined_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            CONSTRAINT `fk_gm_user` FOREIGN KEY (`user_id`) REFERENCES `cf_users`(`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_gm_rank` FOREIGN KEY (`rank_id`) REFERENCES `cf_guild_ranks`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->execute("CREATE TABLE IF NOT EXISTS `cf_guild_teams` (
            `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`        VARCHAR(100) NOT NULL,
            `type`        VARCHAR(50) NOT NULL COMMENT 'raid, mythic, pvp, casual',
            `description` TEXT NULL,
            `max_members` TINYINT UNSIGNED NOT NULL DEFAULT 20,
            `schedule`    VARCHAR(200) NULL COMMENT 'bv. Woensdag + Donderdag 20:00-23:00',
            `is_recruiting` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->execute("CREATE TABLE IF NOT EXISTS `cf_guild_applications` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`     INT UNSIGNED NULL,
            `character_name` VARCHAR(100) NOT NULL,
            `class`       VARCHAR(50) NOT NULL,
            `spec`        VARCHAR(50) NOT NULL,
            `item_level`  SMALLINT UNSIGNED NULL,
            `about`       TEXT NOT NULL,
            `experience`  TEXT NULL,
            `team_id`     SMALLINT UNSIGNED NULL,
            `status`      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            `reviewed_by` INT UNSIGNED NULL,
            `review_note` TEXT NULL,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Seed standaard rangen
        $db->execute("INSERT IGNORE INTO `cf_guild_ranks` (name, display_name, color, priority) VALUES
            ('guild_master',  'Guild Master',  '#f59e0b', 100),
            ('officer',       'Officer',       '#a855f7', 80),
            ('raider',        'Raider',        '#10b981', 60),
            ('trial',         'Trial',         '#6366f1', 40),
            ('social',        'Social',        '#64748b', 10)
        ");
    }

    public function uninstall(): void {}
    public function getBlocks(): array { return ['guild-members', 'guild-info', 'guild-recruitment']; }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: GuildModule.php | Role: Core | Version: 1.0.0                ║
// ║  Created: 2026-06-06 | Status: New                                  ║
// ╚══════════════════════════════════════════════════════════════════════╝
