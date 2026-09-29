<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\Users;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;

/**
 * OAuthController — Centrale dispatcher voor OAuth callbacks.
 *
 * Routes:
 *   GET /auth/discord/callback → discordCallback()
 *   GET /auth/twitch/callback  → twitchCallback()
 *
 * Delegeert naar de module-specifieke OAuth controllers.
 * Modules registreren hun eigen routes via HookManager, maar dit
 * is de core fallback als modules niet geladen zijn.
 */
final class OAuthController
{
    public function __construct(
        private readonly AuthManager  $auth,
        private readonly Connection   $db,
        private readonly CacheManager $cache,
    ) {}

    /**
     * Discord OAuth callback.
     * Delegeert naar DiscordOAuthController als Discord module actief is,
     * anders geeft foutmelding.
     */
    public function discordCallback(Request $request): Response
    {
        $code  = $request->query('code', '');
        $state = $request->query('state', '');
        $error = $request->query('error', '');

        if (!empty($error)) {
            return Response::redirect('/?error=discord_' . urlencode($error));
        }

        if (empty($code)) {
            return Response::redirect('/?error=discord_no_code');
        }

        // Probeer Discord module controller te laden
        $discordController = $this->loadModuleController(
            'CommunityFusion\\Modules\\Discord\\DiscordOAuthController'
        );

        if ($discordController) {
            return $discordController->callback($request);
        }

        // Discord module niet geïnstalleerd
        return Response::redirect('/?error=discord_module_not_installed');
    }

    /**
     * Twitch OAuth callback.
     */
    public function twitchCallback(Request $request): Response
    {
        $code  = $request->query('code', '');
        $state = $request->query('state', '');
        $error = $request->query('error', '');

        if (!empty($error)) {
            return Response::redirect('/?error=twitch_' . urlencode($error));
        }

        if (empty($code)) {
            return Response::redirect('/?error=twitch_no_code');
        }

        $twitchController = $this->loadModuleController(
            'CommunityFusion\\Modules\\Twitch\\TwitchOAuthController'
        );

        if ($twitchController) {
            return $twitchController->callback($request);
        }

        return Response::redirect('/?error=twitch_module_not_installed');
    }

    /**
     * Google OAuth callback. Golf 10.
     */
    public function googleCallback(Request $request): Response
    {
        $code  = $request->query('code', '');
        $error = $request->query('error', '');

        if (!empty($error)) {
            return Response::redirect('/?error=google_' . urlencode($error));
        }
        if (empty($code)) {
            return Response::redirect('/?error=google_no_code');
        }

        $googleController = $this->loadModuleController(
            'CommunityFusion\\Modules\\Google\\GoogleOAuthController'
        );

        if ($googleController) {
            return $googleController->callback($request);
        }

        return Response::redirect('/?error=google_module_not_installed');
    }

    /**
     * Battle.net OAuth callback. Golf 10.
     */
    public function battlenetCallback(Request $request): Response
    {
        $code  = $request->query('code', '');
        $error = $request->query('error', '');

        if (!empty($error)) {
            return Response::redirect('/?error=battlenet_' . urlencode($error));
        }
        if (empty($code)) {
            return Response::redirect('/?error=battlenet_no_code');
        }

        $battlenetController = $this->loadModuleController(
            'CommunityFusion\\Modules\\BattleNet\\BattleNetOAuthController'
        );

        if ($battlenetController) {
            return $battlenetController->callback($request);
        }

        return Response::redirect('/?error=battlenet_module_not_installed');
    }

    /**
     * Probeer een module controller te instantiëren via autoloader.
     */
    private function loadModuleController(string $class): ?object
    {
        if (!class_exists($class)) return null;

        try {
            return new $class($this->auth, $this->db, $this->cache);
        } catch (\Throwable) {
            return null;
        }
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: OAuthController.php | Role: Core | Version: 1.0.0           ║
// ║  Created: 2026-06-06 | Status: New (fix for missing file)           ║
// ╚══════════════════════════════════════════════════════════════════════╝
