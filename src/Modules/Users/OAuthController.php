<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\Users;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;

/**
 * OAuthController — vangnet voor OAuth-callbacks van providers waarvan de
 * module NIET is ingeschakeld.
 *
 * Is een provider-module actief, dan registreert die zelf zijn routes
 * (/auth/{provider}/…, via de 'router.routes'-hook) vóór deze vangnetroutes,
 * en wordt de callback daar afgehandeld door OAuthLoginFlow. Een request dat
 * hier toch uitkomt, hoort bij een uitgeschakelde module: de bezoeker krijgt
 * een nette melding op de loginpagina in plaats van een kale 404.
 */
final class OAuthController
{
    public function discordCallback(Request $request): Response   { return $this->notEnabled('discord'); }
    public function twitchCallback(Request $request): Response    { return $this->notEnabled('twitch'); }
    public function googleCallback(Request $request): Response    { return $this->notEnabled('google'); }
    public function battlenetCallback(Request $request): Response { return $this->notEnabled('battlenet'); }
    public function githubCallback(Request $request): Response    { return $this->notEnabled('github'); }

    private function notEnabled(string $provider): Response
    {
        return Response::redirect('/login?oauth_error=not_enabled&provider=' . rawurlencode($provider));
    }
}
