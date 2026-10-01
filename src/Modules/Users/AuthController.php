<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Users;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Auth\OAuth\ProviderRegistry;
use CommunityFusion\Core\Template\ThemeManager;
use CommunityFusion\Core\Security\CsrfProtection;

final class AuthController
{
    public function __construct(
        private readonly AuthManager  $auth,
        private readonly ThemeManager $theme,
        private readonly ProviderRegistry $providers,
    ) {}

    public function loginForm(Request $request): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/');
        }
        $html = $this->theme->render('auth/login.twig', [
            'page_title'      => 'Inloggen',
            // Alleen providers die aan + geconfigureerd zijn (ProviderRegistry).
            'oauth_providers' => $this->providers->available(),
        ]);
        return Response::html($html);
    }

    public function login(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $identifier = $request->input('identifier', '');
        $password   = $request->input('password', '');

        if ($this->auth->attempt($identifier, $password)) {
            $redirect = (string) $request->query('redirect', '/');
            // Alleen lokale paden (geen open redirect naar //evil.com of https://…)
            if (preg_match('#^/(?![/\\\\])[^\r\n]*$#D', $redirect) !== 1) {
                $redirect = '/';
            }
            return Response::redirect($redirect);
        }

        $html = $this->theme->render('auth/login.twig', [
            'page_title'      => 'Inloggen',
            'error'           => 'Ongeldige inloggegevens.',
            'oauth_providers' => $this->providers->available(),
        ]);
        return Response::html($html, 401);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        return Response::redirect('/');
    }

    public function registerForm(Request $request): Response
    {
        $html = $this->theme->render('auth/register.twig', ['page_title' => 'Registreren']);
        return Response::html($html);
    }

    public function register(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $errors = [];
        $data   = $request->all();

        if (strlen($data['username'] ?? '') < 3) $errors[] = 'Gebruikersnaam te kort.';
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) $errors[] = 'Ongeldig e-mailadres.';
        if (strlen($data['password'] ?? '') < 8) $errors[] = 'Wachtwoord minimaal 8 tekens.';
        if (($data['password'] ?? '') !== ($data['password_confirm'] ?? '')) $errors[] = 'Wachtwoorden komen niet overeen.';

        if (!empty($errors)) {
            $html = $this->theme->render('auth/register.twig', [
                'page_title' => 'Registreren',
                'errors'     => $errors,
                'old'        => $data,
            ]);
            return Response::html($html, 422);
        }

        $userId = $this->auth->register($data);
        $this->auth->attempt($data['username'], $data['password']);

        return Response::redirect('/');
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: AuthController.php | Role: Core | Version: 1.0.0             ║
// ║  Created: 2026-06-06 | Status: New                                  ║
// ╚══════════════════════════════════════════════════════════════════════╝
