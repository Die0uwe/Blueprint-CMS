<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Users;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Template\ThemeManager;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Security\SafeRedirect;
use CommunityFusion\Core\Auth\OAuth\OAuthLoginFlow;
use CommunityFusion\Core\Auth\OAuth\OAuthProviders;

final class AuthController
{
    public function __construct(
        private readonly AuthManager  $auth,
        private readonly ThemeManager $theme,
        private readonly OAuthProviders $oauth,
    ) {}

    /**
     * Variabelen voor de "Inloggen met …"-knoppen en een eventuele OAuth-foutmelding.
     * De foutcode komt uit de URL en wordt tegen een vaste lijst gecontroleerd; vrije
     * tekst uit de URL komt nooit in de pagina.
     *
     * @return array<string,mixed>
     */
    private function oauthVars(Request $request): array
    {
        $code = (string) $request->query('oauth_error', '');
        $slug = (string) $request->query('provider', '');
        $vars = [
            'oauth_providers' => array_values($this->oauth->usable()),
            'oauth_redirect'  => ($r = SafeRedirect::target($request->query('redirect', ''), '')) !== '' ? $r : null,
        ];
        if (in_array($code, OAuthLoginFlow::ERRORS, true)) {
            $vars['oauth_error']          = $code;
            $vars['oauth_provider_label'] = OAuthProviders::CATALOG[$slug]['label'] ?? '';
        }
        return $vars;
    }

    public function loginForm(Request $request): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/');
        }
        $html = $this->theme->render('auth/login.twig', [
            'page_title' => 'Inloggen',
            // ?reset=1 komt van PasswordResetController na een geslaagde reset.
            'password_reset_done' => $request->query('reset') === '1',
        ] + $this->oauthVars($request));
        return Response::html($html);
    }

    public function login(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $identifier = $request->input('identifier', '');
        $password   = $request->input('password', '');

        if ($this->auth->isLoginBlocked()) {
            $html = $this->theme->render('auth/login.twig', [
                'page_title' => 'Inloggen',
                'error'      => 'Te veel mislukte pogingen. Probeer het over 15 minuten opnieuw.',
            ] + $this->oauthVars($request));
            return Response::html($html, 429)->withHeader('Retry-After', (string) $this->auth->loginRetryAfter());
        }

        if ($this->auth->attempt($identifier, $password)) {
            // Alleen een pad op deze site: een ?redirect=https://… is anders een open redirect.
            return Response::redirect(SafeRedirect::target($request->query('redirect', '/')));
        }

        $html = $this->theme->render('auth/login.twig', [
            'page_title' => 'Inloggen',
            'error'      => 'Ongeldige inloggegevens.',
        ] + $this->oauthVars($request));
        return Response::html($html, 401);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        return Response::redirect('/');
    }

    public function registerForm(Request $request): Response
    {
        $html = $this->theme->render('auth/register.twig', ['page_title' => 'Registreren'] + $this->oauthVars($request));
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
            ] + $this->oauthVars($request));
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
