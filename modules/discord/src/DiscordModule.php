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
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Queue\QueueManager;

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
        $registry->register(new DiscordWidgetBlock($this->getConfig()));
        $registry->register(new DiscordOnlineBlock($db, $cache, $this->getConfig()));
        $registry->register(new DiscordStatusBlock(new DiscordStore($db), $cache));

        // Hook: synchroniseer Discord rollen bij login
        $hooks->addAction('user.login', function(array $user) use ($app, $db) {
            $this->scheduleRoleSync((int) $user['id'], $db, $app);
        });

        // Hook: nieuw nieuwsartikel → melding in het Discord-kanaal (webhook). Alleen actief als de module aan staat.
        DiscordNewsAnnouncer::register($hooks, $db);

        // Menu-item in het admin-menu (alleen voor wie discord.admin heeft)
        $hooks->addFilter('admin.menu', function($items) use ($app) {
            $items = is_array($items) ? $items : [];
            try {
                if ($app->make(AuthManager::class)->can('discord.admin')) {
                    $items[] = ['key' => 'discord', 'href' => '/admin/discord', 'label' => 'Discord', 'icon' => '🎮'];
                }
            } catch (\Throwable) {
                // geen menu-item is beter dan een kapotte admin
            }
            return $items;
        });

        // Registreer OAuth routes (worden door Router opgepakt via hook)
        $hooks->addAction('router.routes', fn($router) => self::registerRoutes($router));
    }

    /**
     * Routes van de module. Publiek → OAuth; beheer → /admin/discord met exact dezelfde middleware als
     * Router::registerCoreRoutes() ($perm): AuthMiddleware + PermissionMiddleware:discord.admin.
     * Moet via de 'router.routes'-hook lopen zodat ze vóór de /admin/{path}-catch-all staan.
     */
    public static function registerRoutes(object $router): void
    {
        // Registreer OAuth routes (worden door Router opgepakt via hook)
        $router->get('/auth/discord',          'CommunityFusion\Modules\Discord\DiscordOAuthController@redirect');
        $router->get('/auth/discord/login',    'CommunityFusion\Modules\Discord\DiscordOAuthController@loginRedirect');
        $router->get('/auth/discord/callback', 'CommunityFusion\Modules\Discord\DiscordOAuthController@callback');
        $router->post('/auth/discord/disconnect', 'CommunityFusion\Modules\Discord\DiscordOAuthController@disconnect');

        // Beheer: /admin/discord (AuthMiddleware + PermissionMiddleware:discord.admin, zoals Router::$perm())
        $ctl  = 'CommunityFusion\Modules\Discord\DiscordAdminController';
        $perm = ['CommunityFusion\Api\Middleware\AuthMiddleware', 'CommunityFusion\Api\Middleware\PermissionMiddleware:discord.admin'];
        $router->get('/admin/discord',                                   "{$ctl}@status",              $perm);
        $router->post('/admin/discord/test',                             "{$ctl}@testConnection",      $perm);
        $router->get('/admin/discord/widget',                            "{$ctl}@widget",              $perm);
        $router->post('/admin/discord/widget',                           "{$ctl}@widgetUpdate",        $perm);
        $router->get('/admin/discord/meldingen',                         "{$ctl}@notifications",       $perm);
        $router->post('/admin/discord/meldingen/opslaan',                "{$ctl}@saveNotifications",   $perm);
        $router->post('/admin/discord/meldingen/verwijderen',            "{$ctl}@deleteWebhook",       $perm);
        $router->post('/admin/discord/meldingen/test',                   "{$ctl}@testWebhook",         $perm);
        $router->get('/admin/discord/rollen',                            "{$ctl}@roles",               $perm);
        $router->post('/admin/discord/rollen/toevoegen',                 "{$ctl}@addRole",             $perm);
        $router->post('/admin/discord/rollen/{id:[0-9]+}/bewerk',        "{$ctl}@updateRole",          $perm);
        $router->post('/admin/discord/rollen/{id:[0-9]+}/verwijderen',   "{$ctl}@deleteRole",          $perm);
    }

    public function install(): void
    {
        // Extra DB-tabellen voor Discord module
        $db = $this->app->make(Connection::class);

        DiscordStore::ensureSchema($db);
    }

    public function uninstall(): void {}

    public function getBlocks(): array
    {
        return ['discord-widget', 'discord-online', 'discord-status'];
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

    /**
     * Zet een sync-job in de queue 'discord-sync' (alleen als de gebruiker Discord gekoppeld heeft en
     * server + bot-token zijn ingesteld; anders zou elke login een nutteloze job maken).
     *
     * QueueManager::push() bewaart de job als serialize(Job) en de worker doet unserialize(): daarom
     * is dit een DiscordRoleSyncJob die alleen het gebruikers-ID (int) serialiseert — geen JSON-string
     * die de worker niet kan lezen. Verwerken: php cli/console.php queue:work --queue=discord-sync
     */
    private function scheduleRoleSync(int $userId, Connection $db, Application $app): void
    {
        try {
            $store = new DiscordStore($db);
            if ($userId < 1 || !DiscordApi::isSnowflake($store->guildId()) || $store->botToken() === '') {
                return;
            }
            $linked = $db->fetchOne("SELECT 1 x FROM cf_user_oauth WHERE user_id = ? AND provider = 'discord'", [$userId]);
            if ($linked === null) {
                return;
            }
            $app->make(QueueManager::class)->push(new DiscordRoleSyncJob($userId));
        } catch (\Throwable) {
            // Queue niet beschikbaar — negeren
        }
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: DiscordModule.php | Role: Core | Version: 1.0.0              ║
// ║  Created: 2026-06-06 | Status: New                                  ║
// ╚══════════════════════════════════════════════════════════════════════╝
