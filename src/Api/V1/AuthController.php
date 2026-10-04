<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Api\V1;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Auth\JWTManager;

final class AuthController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly JWTManager  $jwt,
    ) {}

    /**
     * POST /api/v1/auth/login
     * Body: { "identifier": "user", "password": "pass" }
     * Returns: { "token": "JWT...", "user": {...} }
     */
    public function login(Request $request): Response
    {
        $identifier = trim($request->input('identifier', ''));
        $password   = $request->input('password', '');

        if (empty($identifier) || empty($password)) {
            return Response::json(['error' => 'identifier en password zijn verplicht.'], 422);
        }

        if ($this->auth->isLoginBlocked()) {
            return Response::json(['error' => 'Te veel mislukte pogingen. Probeer het later opnieuw.'], 429)
                ->withHeader('Retry-After', (string) $this->auth->loginRetryAfter());
        }

        // startSession=false: dit endpoint geeft een stateless JWT-token uit
        // en mag de browser-sessiecookie niet aanraken — zie de uitleg bij
        // AuthManager::attempt()/login().
        if (!$this->auth->attempt($identifier, $password, startSession: false)) {
            return Response::json(['error' => 'Ongeldige inloggegevens.'], 401);
        }

        $user  = $this->auth->user();
        $token = $this->jwt->generate([
            'sub'      => $user['id'],
            'username' => $user['username'],
        ]);

        return Response::json([
            'token' => $token,
            'user'  => [
                'id'           => $user['id'],
                'username'     => $user['username'],
                'email'        => $user['email'],
                'display_name' => $user['display_name'],
                'avatar_url'   => $user['avatar_url'],
            ],
        ]);
    }

    /**
     * GET /api/v1/auth/me — huidige ingelogde user
     */
    public function me(Request $request): Response
    {
        $user = $this->auth->user();
        if (!$user) return Response::json(['error' => 'Niet ingelogd.'], 401);

        return Response::json([
            'id'           => $user['id'],
            'username'     => $user['username'],
            'display_name' => $user['display_name'],
            'avatar_url'   => $user['avatar_url'],
            'locale'       => $user['locale'],
        ]);
    }
}
