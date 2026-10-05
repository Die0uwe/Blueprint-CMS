<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Users;

use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Auth\OAuth\OAuthProviders;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Mail\Mailer;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Template\ThemeManager;

/**
 * AccountController — extra e-mailadressen (max. 3, hoofdadres kiezen) en
 * accounts samenvoegen. De regels staan in AccountService; dit is de dunne
 * HTTP-laag. Alle POST-routes valideren CSRF.
 */
final class AccountController
{
    public function __construct(
        private readonly AuthManager    $auth,
        private readonly Connection     $db,
        private readonly ThemeManager   $theme,
        private readonly AccountService $service,
        private readonly OAuthProviders $oauth,
        private readonly Mailer         $mailer,
    ) {}

    // ─── E-mailadressen ──────────────────────────────────────────────────

    public function addEmail(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $res = $this->service->addEmail((int) $this->auth->id(), (string) $request->input('email', ''));
        if (isset($res['error'])) {
            return Response::redirect('/profiel?email_error=' . $res['error'] . '#emails');
        }
        $base = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        if ($base !== '') {
            $this->mail($res['email'], 'Bevestig je e-mailadres',
                "Hallo,\n\nBevestig dit e-mailadres voor je account via onderstaande link (24 uur geldig):\n\n"
                . "{$base}/profiel/email/bevestig/{$res['token']}\n\nWas jij dit niet? Negeer deze mail.\n");
        } else {
            error_log('AccountController: APP_URL niet ingesteld; geen bevestigingsmail verstuurd.');
        }
        return Response::redirect('/profiel?email=added#emails');
    }

    public function verifyEmail(Request $request): Response
    {
        $ok = $this->service->verifyEmail((string) $request->param('token', ''));
        return Response::redirect($ok !== null ? '/profiel?email=verified#emails' : '/profiel?email_error=token#emails');
    }

    public function primaryEmail(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $ok = $this->service->setPrimary((int) $this->auth->id(), (int) $request->input('id', 0));
        return Response::redirect($ok ? '/profiel?email=primary#emails' : '/profiel?email_error=unverified#emails');
    }

    public function removeEmail(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $this->service->removeEmail((int) $this->auth->id(), (int) $request->input('id', 0));
        return Response::redirect('/profiel?email=removed#emails');
    }

    // ─── Samenvoegen ─────────────────────────────────────────────────────

    public function mergeForm(Request $request): Response
    {
        $me    = (int) $this->auth->id();
        $drop  = $this->proofId($me);
        $error = (string) ($_SESSION['merge_error'] ?? '');
        unset($_SESSION['merge_error']);

        $usable = $this->oauth->usable();
        $providers = [];
        foreach (OAuthProviders::CATALOG as $slug => $meta) {
            if (isset($usable[$slug])) {
                $providers[] = ['slug' => $slug] + $meta;
            }
        }

        $oauthErr = (string) $request->query('oauth_error', '');
        if ($error === '' && in_array($oauthErr, ['merge_unknown', 'merge_same', 'cancelled', 'state', 'failed'], true)) {
            $error = $oauthErr;
        }

        return Response::html($this->theme->render('users/merge.twig', [
            'page_title' => 'Accounts samenvoegen',
            'user'       => $this->auth->user(),
            'preview'    => $drop > 0 ? $this->service->preview($drop) : null,
            'mine'       => $this->service->preview($me),
            'providers'  => $providers,
            'error'      => $error,
            'done'       => null,
        ]));
    }

    /** POST — bewijs via gebruikersnaam/e-mail + wachtwoord van het andere account. */
    public function mergeProof(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $me    = (int) $this->auth->id();
        $ident = trim((string) $request->input('ident', ''));
        $pass  = (string) $request->input('password', '');
        unset($_SESSION['merge_proof']);

        if ($ident === '' || $pass === '') {
            return $this->mergeError('empty');
        }
        if ($this->auth->isLoginBlocked()) {
            return $this->mergeError('throttled');
        }
        // startSession=false: alleen controleren (met dezelfde rem als inloggen), geen nieuwe sessie.
        if (!$this->auth->attempt($ident, $pass, false)) {
            return $this->mergeError('credentials');
        }
        $row = $this->db->fetchOne("SELECT id FROM cf_users WHERE username = ? OR email = ?", [$ident, $ident]);
        $drop = (int) ($row['id'] ?? 0);
        $err  = $drop > 0 ? $this->service->mergeCheck($me, $drop) : 'missing';
        if ($err !== '') {
            return $this->mergeError($err);
        }
        $_SESSION['merge_proof'] = ['keep' => $me, 'drop' => $drop, 'at' => time()];
        return Response::redirect('/profiel/samenvoegen');
    }

    public function mergeConfirm(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $me   = (int) $this->auth->id();
        $drop = $this->proofId($me);
        if ($drop === 0) {
            return $this->mergeError('expired');
        }
        if (strtoupper(trim((string) $request->input('confirm', ''))) !== 'SAMENVOEGEN') {
            return $this->mergeError('confirm');
        }
        unset($_SESSION['merge_proof']); // eenmalig
        try {
            $out = $this->service->merge($me, $drop);
        } catch (\RuntimeException $e) {
            return $this->mergeError(str_replace('merge_', '', $e->getMessage()));
        }
        foreach ($out['mail'] as $to) {
            $this->mail($to, 'Accounts samengevoegd',
                "Het account '{$out['dropped']}' is samengevoegd met '{$out['kept']}'. Inloggen kan voortaan met '{$out['kept']}'.\n"
                . "Was jij dit niet? Gebruik direct 'Wachtwoord vergeten' en neem contact op met de beheerder.\n");
        }
        $_SESSION['merge_done'] = $out;
        return Response::redirect('/profiel/samenvoegen/klaar');
    }

    public function mergeDone(Request $request): Response
    {
        $done = $_SESSION['merge_done'] ?? null;
        unset($_SESSION['merge_done']);
        if (!is_array($done)) {
            return Response::redirect('/profiel');
        }
        return Response::html($this->theme->render('users/merge.twig', [
            'page_title' => 'Accounts samengevoegd',
            'user'       => $this->auth->user(),
            'done'       => $done,
            'providers'  => [],
            'preview'    => null,
            'mine'       => null,
            'error'      => '',
        ]));
    }

    public function mergeCancel(Request $request): Response
    {
        CsrfProtection::validateRequest();
        unset($_SESSION['merge_proof']);
        return Response::redirect('/profiel');
    }

    // ─── intern ──────────────────────────────────────────────────────────

    /** Geldig bewijs voor DEZE ingelogde gebruiker (10 min)? Geeft het id van het andere account of 0. */
    private function proofId(int $me): int
    {
        $p = $_SESSION['merge_proof'] ?? null;
        if (!is_array($p) || (int) ($p['keep'] ?? 0) !== $me || (int) ($p['at'] ?? 0) < time() - AccountService::PROOF_SECONDS) {
            unset($_SESSION['merge_proof']);
            return 0;
        }
        return (int) $p['drop'];
    }

    private function mergeError(string $code): Response
    {
        $_SESSION['merge_error'] = $code;
        return Response::redirect('/profiel/samenvoegen');
    }

    private function mail(string $to, string $subject, string $body): void
    {
        $send = function () use ($to, $subject, $body): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $this->mailer->send($to, $subject, $body);
        };
        if (PHP_SAPI === 'cli') {
            $send();
            return;
        }
        register_shutdown_function($send);
    }
}
