<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Users;

use CommunityFusion\Core\Auth\PasswordResetService;
use CommunityFusion\Core\Mail\Mailer;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Template\ThemeManager;

/**
 * Wachtwoord vergeten en herstellen.
 *
 *   GET  /wachtwoord-vergeten              formulier (e-mailadres of gebruikersnaam)
 *   POST /wachtwoord-vergeten              verstuurt de herstelmail
 *   GET  /wachtwoord-herstellen/{token}    formulier voor het nieuwe wachtwoord
 *   POST /wachtwoord-herstellen/{token}    zet het nieuwe wachtwoord
 *
 * Beveiligingskeuzes (details in PasswordResetService):
 *  - De aanvraag antwoordt altijd hetzelfde, ongeacht of het account bestaat.
 *  - De link in de mail wordt gebouwd uit de ingestelde APP_URL, nooit uit de
 *    Host-header van het verzoek (host-header-injectie).
 *  - De mail wordt pas verstuurd nadat het antwoord de bezoeker bereikt heeft
 *    (fastcgi_finish_request), zodat de antwoordtijd niet verraadt of een
 *    account bestaat.
 *  - De herstelpagina's krijgen Referrer-Policy: no-referrer en no-store, zodat
 *    het token niet via een link of cache lekt.
 */
final class PasswordResetController
{
    /** Antwoordtijdkoppen voor pagina's waar een token in de URL staat. */
    private const SENSITIVE_HEADERS = [
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control'   => 'no-store',
    ];

    public function __construct(
        private readonly PasswordResetService $service,
        private readonly Mailer $mailer,
        private readonly ThemeManager $theme,
    ) {
    }

    public function forgotForm(Request $request): Response
    {
        return $this->renderForgot([]);
    }

    public function forgot(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $identifier = trim((string) $request->input('identifier', ''));
        if ($identifier === '') {
            return $this->renderForgot(['error' => 'Vul je e-mailadres of gebruikersnaam in.'], 422);
        }

        $result = $this->service->createToken($identifier, $this->clientIp());
        if ($result !== null) {
            $this->sendAfterResponse($result);
        }

        // Altijd dezelfde bevestiging.
        return $this->renderForgot(['sent' => true]);
    }

    public function resetForm(Request $request): Response
    {
        $token = (string) $request->param('token', '');
        if ($this->service->findValid($token) === null) {
            return $this->renderInvalid();
        }
        return $this->renderReset($token, []);
    }

    public function reset(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $token    = (string) $request->param('token', '');
        $password = (string) $request->input('password', '');
        $confirm  = (string) $request->input('password_confirm', '');

        if ($this->service->findValid($token) === null) {
            return $this->renderInvalid();
        }

        $errors = PasswordResetService::validatePassword($password, $confirm);
        if ($errors !== []) {
            return $this->renderReset($token, ['errors' => $errors], 422);
        }

        if (!$this->service->reset($token, $password)) {
            return $this->renderInvalid(); // intussen verlopen of al gebruikt
        }

        return Response::redirect('/login?reset=1');
    }

    // ── intern ───────────────────────────────────────────────────────────

    /**
     * @param array{token: string, user: array{id: int, username: string, email: string}, ttl_minutes: int} $result
     */
    private function sendAfterResponse(array $result): void
    {
        $base = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        if ($base === '') {
            error_log('PasswordReset: APP_URL (config app.url) is niet ingesteld; er is geen herstelmail verstuurd.');
            return;
        }

        $link    = $base . '/wachtwoord-herstellen/' . $result['token'];
        $subject = 'Wachtwoord opnieuw instellen';
        $body    = "Hallo {$result['user']['username']},\n\n"
            . "Er is gevraagd om het wachtwoord van je account opnieuw in te stellen.\n"
            . "Klik op de link hieronder (of plak hem in je browser) om een nieuw wachtwoord te kiezen:\n\n"
            . "{$link}\n\n"
            . "De link is {$result['ttl_minutes']} minuten geldig en kan één keer gebruikt worden.\n\n"
            . "Heb jij dit niet aangevraagd? Dan hoef je niets te doen: je wachtwoord blijft zoals het was.\n";
        $to = $result['user']['email'];

        $send = function () use ($to, $subject, $body): void {
            // Eerst het antwoord aan de bezoeker afmaken (php-fpm); de mailtijd
            // is dan niet meer zichtbaar voor de bezoeker.
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $this->mailer->send($to, $subject, $body);
        };

        if (PHP_SAPI === 'cli') {
            $send(); // geen response-cyclus in de CLI/tests
            return;
        }
        register_shutdown_function($send);
    }

    /**
     * Bewust REMOTE_ADDR en niet Request::ip(): dat vertrouwt de
     * X-Forwarded-For-header blind, waarmee een aanvaller de IP-limiet kan
     * omzeilen (nieuw "IP" per verzoek) of de audit-kolom kan vullen met
     * rommel. Achter een reverse proxy zonder doorgegeven REMOTE_ADDR geldt de
     * limiet voor alle bezoekers samen; stel de proxy dan zo in dat PHP het
     * echte adres als REMOTE_ADDR ziet.
     */
    private function clientIp(): string
    {
        $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        return $ip === false ? '0.0.0.0' : $ip;
    }

    /** @param array<string, mixed> $vars */
    private function renderForgot(array $vars, int $status = 200): Response
    {
        $html = $this->theme->render('auth/forgot.twig', $vars + ['page_title' => 'Wachtwoord vergeten']);
        return Response::html($html, $status);
    }

    /** @param array<string, mixed> $vars */
    private function renderReset(string $token, array $vars, int $status = 200): Response
    {
        $html = $this->theme->render('auth/reset.twig', $vars + [
            'page_title' => 'Nieuw wachtwoord',
            'token'      => $token,
        ]);
        return $this->sensitive(Response::html($html, $status));
    }

    private function renderInvalid(): Response
    {
        $html = $this->theme->render('auth/reset_invalid.twig', ['page_title' => 'Link ongeldig']);
        return $this->sensitive(Response::html($html, 410));
    }

    private function sensitive(Response $response): Response
    {
        foreach (self::SENSITIVE_HEADERS as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}
