<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
//
// This work is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This work is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Contact;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Template\ThemeManager;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Mail\Mailer;
use CommunityFusion\Modules\Settings\SettingsRepository;

/**
 * ContactController
 *
 * Publiek formulier (geen login vereist) + een beheer-inbox achter
 * `contact.manage`. Vult velden automatisch als de bezoeker ingelogd is,
 * maar staat anonieme berichten net zo goed toe.
 */
final class ContactController
{
    public function __construct(
        private readonly ContactRepository  $repo,
        private readonly AuthManager        $auth,
        private readonly ThemeManager       $theme,
        private readonly Mailer             $mailer,
        private readonly SettingsRepository $settings,
    ) {}

    /** GET /contact */
    public function form(Request $request): Response
    {
        $user = $this->auth->user();

        $html = $this->theme->render('contact/form.twig', [
            'page_title' => 'Contact',
            'prefill'    => [
                'name'  => $user['display_name'] ?? $user['username'] ?? '',
                'email' => $user['email'] ?? '',
            ],
            'sent' => $request->query('verzonden') !== null,
        ]);

        return Response::html($html);
    }

    /** POST /contact */
    public function store(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $name    = trim((string) $request->input('name', ''));
        $email   = trim((string) $request->input('email', ''));
        $subject = trim((string) $request->input('subject', ''));
        $message = trim((string) $request->input('message', ''));

        // Honeypot: verborgen veld voor bots — een echte bezoeker vult dit nooit in.
        if (trim((string) $request->input('website', '')) !== '') {
            return Response::redirect('/contact?verzonden=1');
        }

        if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::redirect('/contact?error=ongeldig');
        }

        $this->repo->create(
            userId:  $this->auth->id(),
            name:    $name,
            email:   $email,
            subject: $subject,
            message: $message,
            ip:      $request->ip(),
        );

        $this->notifyAdmin($name, $email, $subject, $message);

        return Response::redirect('/contact?verzonden=1');
    }

    /**
     * Wave 1 bouwde dit formulier zonder Mailer (die bestond nog niet) —
     * berichten werden alleen opgeslagen, nooit gemaild. Daarna ging elke
     * melding altijd naar het geconfigureerde afzenderadres zelf, omdat
     * settings.php destijds een statische pagina was zonder key/value-editor
     * en er dus geen plek was om een apart meldingen-adres in te stellen.
     * Sinds v1.18.0 is /admin/settings een echt formulier op de cf_settings-
     * tabel (zie Settings\AdminController::settings()/updateSettings()),
     * dus dat is nu ingehaald: een beheerder kan via de sectie "Contactform-
     * ulier" een eigen 'contact.notify_email' instellen. Leeg (of niet
     * ingesteld) valt nog steeds terug op precies het oude gedrag. Een
     * mislukte mail (geen SMTP bereikbaar, verkeerd wachtwoord, …) mag een
     * bezoeker nooit een 500 opleveren: het bericht staat al veilig in de
     * database/inbox, dus dit is best-effort en faalt stil (Mailer logt
     * zelf via error_log()).
     */
    private function notifyAdmin(string $name, string $email, string $subject, string $message): void
    {
        $to = trim((string) $this->settings->get('contact', 'notify_email', ''));
        if ($to === '') {
            $to = $this->mailer->getFromAddress();
        }
        if ($to === '' || $to === 'noreply@localhost') {
            return; // geen zinnig adres geconfigureerd — niets te versturen
        }

        $this->mailer->send(
            to: $to,
            subject: '[Contact] ' . ($subject !== '' ? $subject : 'Nieuw bericht van ' . $name),
            textBody: "Nieuw contactformulier-bericht:\n\n"
                . "Naam: {$name}\n"
                . "E-mail: {$email}\n"
                . "Onderwerp: " . ($subject !== '' ? $subject : '(geen)') . "\n\n"
                . "Bericht:\n{$message}\n\n"
                . "— Beheer dit bericht via /admin/contact",
            replyTo: $email,
        );
    }

    /** GET /admin/contact */
    public function inbox(Request $request): Response
    {
        $guard = $this->requireManager();
        if ($guard !== null) return $guard;

        $perPage = $this->repo->perPage();
        $page    = $request->page();
        $offset  = ($page - 1) * $perPage;
        $total   = $this->repo->countAll();

        $html = $this->theme->render('contact/inbox.twig', [
            'page_title' => 'Contact — Inbox',
            'messages'   => $this->repo->getInbox($perPage, $offset),
            'unread'     => $this->repo->countUnread(),
            'pagination' => ['current' => $page, 'total' => max(1, (int) ceil($total / $perPage))],
        ]);

        return Response::html($html);
    }

    /** GET /admin/contact/{id} */
    public function show(Request $request): Response
    {
        $guard = $this->requireManager();
        if ($guard !== null) return $guard;

        $message = $this->repo->findById((int) $request->param('id'));
        if ($message === null) {
            return Response::html('<h1>404 — Bericht niet gevonden</h1>', 404);
        }

        if (!$message['is_read']) {
            $this->repo->markRead((int) $message['id']);
        }

        $html = $this->theme->render('contact/detail.twig', [
            'page_title' => 'Bericht van ' . $message['name'],
            'message'    => $message,
        ]);

        return Response::html($html);
    }

    /** POST /admin/contact/{id}/verwijder */
    public function delete(Request $request): Response
    {
        $guard = $this->requireManager();
        if ($guard !== null) return $guard;

        CsrfProtection::validateRequest();

        $this->repo->delete((int) $request->param('id'));

        return Response::redirect('/admin/contact');
    }

    private function requireManager(): ?Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=/admin/contact');
        }
        if (!$this->auth->can('contact.manage')) {
            return Response::html('<h1>403 — Geen toegang tot de contact-inbox</h1>', 403);
        }

        return null;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : ContactController.php                                ║
// ║  Role         : Core                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-09-28                                           ║
// ║  Status       : New — Wave 1 (Contact core-module)                   ║
// ║  Notes        : Honeypot-veld tegen basic bots; contact.manage RBAC  ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
