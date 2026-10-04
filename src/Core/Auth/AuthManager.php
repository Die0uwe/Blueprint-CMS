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

namespace CommunityFusion\Core\Auth;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Auth\RBAC\RBACManager;
use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\OAuth\OAuthAccountDisabledException;

/**
 * Authenticatie Manager
 * Beheert login, logout, sessie, en de huidige gebruiker.
 */
final class AuthManager
{
    private ?array $currentUser = null;
    private ?LoginThrottle $throttle = null;

    /** Geldige bcrypt-hash van een willekeurige tekst: voor de timing-gelijke controle bij onbekende gebruikers. */
    private const DUMMY_HASH = '$2y$12$HjjWd6EM6nX1guEWFCltn.IurhN0tiEpTI3UwdSPUcWFwwbwufZZy';

    public function __construct(
        private readonly Connection   $db,
        private readonly RBACManager  $rbac,
        private readonly JWTManager   $jwt,
        private readonly AuditLogger  $audit,
    ) {
        if (session_status() === PHP_SESSION_NONE) {
            $this->startSecureSession();
        }

        // Laad gebruiker uit sessie als die bestaat
        if (isset($_SESSION['user_id'])) {
            $this->currentUser = $this->findUserById((int) $_SESSION['user_id']);
        }

        // Na een voltooide wachtwoordreset zijn alle sessies van die gebruiker
        // die ouder zijn dan de reset ongeldig (PasswordResetService::sessionRevoked()).
        $revoked = $this->currentUser !== null && PasswordResetService::sessionRevoked(
            $this->db,
            (int) $this->currentUser['id'],
            (int) ($_SESSION['login_time'] ?? 0)
        );
        if ($revoked) {
            $this->currentUser = null;
            unset($_SESSION['user_id'], $_SESSION['login_time']);
        }
    }

    /**
     * Log een gebruiker in met username/email + wachtwoord.
     *
     * $startSession = false slaat de cookie-sessie sectie van login() over —
     * nodig voor Api\V1\AuthController::login(), dat een stateless JWT-token
     * hoort uit te geven. Zonder dit riep de "stateless" login-endpoint hier
     * gewoon session_regenerate_id() + $_SESSION['user_id'] aan zoals de
     * normale weblogin, wat een klassieke login-CSRF opende: een simpele
     * cross-site <form method=POST> (application/x-www-form-urlencoded,
     * geen CORS-preflight nodig) naar /api/v1/auth/login met de
     * inloggegevens van de AANVALLER logde het slachtoffer-sessiecookie
     * stilletjes in op het account van de aanvaller. Gevonden tijdens de
     * S13-inventarisatiepas.
     */
    public function attempt(string $identifier, string $password, bool $startSession = true): bool
    {
        // Rem op wachtwoord-raden (5 mislukte pogingen per IP per 15 min). Een
        // geblokkeerde poging controleert het wachtwoord niet eens.
        if ($this->isLoginBlocked()) {
            $this->audit->log('auth.login_blocked', null, null, ['identifier' => mb_substr($identifier, 0, 100)]);
            return false;
        }

        $user = $this->db->fetchOne(
            "SELECT * FROM cf_users WHERE (username = ? OR email = ?) AND is_active = 1 AND deleted_at IS NULL",
            [$identifier, $identifier]
        );

        // Ook voor een onbekende gebruiker een wachtwoordcontrole doen (en de
        // mislukking tellen): anders antwoordt "bestaat niet" merkbaar sneller
        // en verraadt het welke accounts er zijn, en telt het niet mee voor de rem.
        $hash = $user['password_hash'] ?? self::DUMMY_HASH;
        $ok   = password_verify($password, $hash);

        if ($user === null || !$ok) {
            $this->logFailedAttempt($identifier);
            return false;
        }

        $this->login($user, $startSession);
        return true;
    }

    /** Is dit IP-adres tijdelijk geblokkeerd voor inloggen? Controllers tonen dan een 429. */
    public function isLoginBlocked(): bool
    {
        return $this->throttle()->isBlocked();
    }

    public function loginRetryAfter(): int
    {
        return $this->throttle()->retryAfter();
    }

    private function throttle(): LoginThrottle
    {
        return $this->throttle ??= new LoginThrottle($this->db);
    }

    /**
     * Sla de gebruiker op in de sessie (of alleen in-memory voor dit
     * request als $startSession false is — zie attempt() hierboven).
     */
    public function login(array $user, bool $startSession = true): void
    {
        if ($startSession) {
            session_regenerate_id(true); // Voorkom session fixation
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['login_time'] = time();
        }
        $this->currentUser = $user;

        // Update last_login
        $this->db->execute(
            "UPDATE cf_users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?",
            [$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', $user['id']]
        );

        $this->audit->log('auth.login', (int) $user['id'], (string) $user['username']);
    }

    /**
     * Log de huidige gebruiker uit.
     */
    public function logout(): void
    {
        $this->currentUser = null;
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Is de huidige bezoeker ingelogd?
     */
    public function check(): bool
    {
        return $this->currentUser !== null;
    }

    /**
     * Geef de ingelogde gebruiker terug.
     */
    public function user(): ?array
    {
        return $this->currentUser;
    }

    /**
     * Geef het ID van de ingelogde gebruiker terug.
     */
    public function id(): ?int
    {
        return $this->currentUser ? (int) $this->currentUser['id'] : null;
    }

    /**
     * Controleer of de gebruiker een permissie heeft.
     */
    public function can(string $permission): bool
    {
        if (!$this->check()) return false;
        return $this->rbac->userCan((int) $this->currentUser['id'], $permission);
    }

    /**
     * Gooi een exception als de gebruiker geen permissie heeft.
     */
    public function authorize(string $permission): void
    {
        if (!$this->can($permission)) {
            // Was \RuntimeException(..., 403) — but Application::handleException()
            // only recognizes CommunityFusion\Core\HttpException for its status
            // code, a class that never actually existed in this codebase (see
            // HttpException.php). Every authorize() rejection was silently
            // rendered as a generic 500, never the intended 403.
            throw new \CommunityFusion\Core\HttpException("Toegang geweigerd: '{$permission}' vereist.", 403);
        }
    }

    /**
     * Registreer een nieuwe gebruiker met wachtwoord.
     */
    public function register(array $data): int|string
    {
        $hash = password_hash($data['password'], PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 3,
        ]);

        $userId = $this->db->insert('users', [
            'username'      => $data['username'],
            'email'         => $data['email'],
            'password_hash' => $hash,
            'display_name'  => $data['display_name'] ?? $data['username'],
            'locale'        => $data['locale'] ?? 'nl',
            'timezone'      => $data['timezone'] ?? 'Europe/Amsterdam',
        ]);

        $this->assignDefaultRole((int) $userId);

        return $userId;
    }

    /**
     * Zoek een CMS-gebruiker die al aan dit OAuth-account (bv. Discord) is
     * gekoppeld, of maak er automatisch één aan — voor "inloggen/registreren
     * met Discord" zonder dat er eerst een wachtwoord-account moet bestaan.
     *
     * @param string $provider        bv. 'discord'
     * @param string $providerUserId  het externe user-ID bij die provider
     * @param array  $profile         ruwe profieldata van de provider, met
     *                                minimaal 'username' en optioneel 'email',
     *                                'avatar_url', 'email_verified' (bool)
     * @return array                  de cf_users-rij (bestaand of nieuw)
     */
    public function findOrCreateFromOAuth(string $provider, string $providerUserId, array $profile): array
    {
        // Zoek de koppeling ZONDER op is_active te filteren: een gedeactiveerd
        // (geblokkeerd) account mag niet inloggen, maar mag zeker ook geen
        // nieuw account kunnen aanmaken met dezelfde provider-identiteit.
        $linked = $this->db->fetchOne(
            "SELECT u.* FROM cf_user_oauth o
             JOIN cf_users u ON u.id = o.user_id
             WHERE o.provider = ? AND o.provider_user_id = ?",
            [$provider, $providerUserId]
        );

        if ($linked !== null) {
            if ((int) $linked['is_active'] !== 1 || $linked['deleted_at'] !== null) {
                throw new OAuthAccountDisabledException('Dit account is gedeactiveerd.');
            }
            return $linked;
        }

        $username = $this->uniqueUsernameFrom($profile['username'] ?? ($provider . '_' . $providerUserId));
        $email    = $this->uniqueEmailFrom($profile['email'] ?? null, $provider, $providerUserId);

        // OAuth-only account: wachtwoord is onbruikbaar-willekeurig — de
        // gebruiker logt altijd via de provider in. Argon2id zodat een
        // eventuele latere "wachtwoord instellen"-flow er gewoon overheen kan.
        $randomHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 3,
        ]);

        $userId = $this->db->insert('users', [
            'username'          => $username,
            'email'             => $email,
            'password_hash'     => $randomHash,
            'display_name'      => $profile['username'] ?? $username,
            'avatar_url'        => $profile['avatar_url'] ?? null,
            'is_verified'       => !empty($profile['email_verified']) ? 1 : 0,
            'email_verified_at' => !empty($profile['email_verified']) ? date('Y-m-d H:i:s') : null,
            'locale'            => 'nl',
            'timezone'          => 'Europe/Amsterdam',
        ]);

        $this->assignDefaultRole((int) $userId);

        return $this->findUserById((int) $userId) ?? throw new \RuntimeException(
            "Nieuw aangemaakte OAuth-gebruiker (id {$userId}) kon niet worden teruggelezen."
        );
    }

    private function uniqueUsernameFrom(string $base): string
    {
        $base = preg_replace('/[^a-zA-Z0-9_.-]/', '', $base) ?: 'lid';
        $base = substr($base, 0, 40) ?: 'lid';

        $candidate = $base;
        $attempt   = 0;
        while ($this->db->fetchOne("SELECT id FROM cf_users WHERE username = ?", [$candidate]) !== null) {
            $attempt++;
            $candidate = substr($base, 0, 40 - 5) . '_' . bin2hex(random_bytes(2));
            if ($attempt > 10) {
                throw new \RuntimeException('Kon geen unieke gebruikersnaam genereren.');
            }
        }

        return $candidate;
    }

    private function uniqueEmailFrom(?string $email, string $provider, string $providerUserId): string
    {
        // Geen (geverifieerd) e-mailadres van de provider ontvangen — cf_users.email
        // is UNIQUE NOT NULL, dus we genereren een placeholder op een non-routable
        // domein. De gebruiker kan dit later via het profiel aanvullen.
        if (empty($email)) {
            return "{$provider}-{$providerUserId}@users.noreply.invalid";
        }

        $existing = $this->db->fetchOne("SELECT id FROM cf_users WHERE email = ?", [$email]);
        if ($existing === null) {
            return $email;
        }

        // E-mailadres is al in gebruik door een ander account: nooit stilzwijgend
        // accounts samenvoegen — genereer een placeholder zodat de nieuwe OAuth-
        // registratie niet faalt op de UNIQUE-constraint.
        return "{$provider}-{$providerUserId}@users.noreply.invalid";
    }

    private function assignDefaultRole(int $userId): void
    {
        $defaultRole = $this->db->fetchOne("SELECT id FROM cf_roles WHERE is_default = 1 LIMIT 1");
        if ($defaultRole === null) return;

        $this->db->execute(
            "INSERT IGNORE INTO cf_user_roles (user_id, role_id) VALUES (?, ?)",
            [$userId, $defaultRole['id']]
        );
    }

    private function findUserById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM cf_users WHERE id = ? AND is_active = 1 AND deleted_at IS NULL",
            [$id]
        );
    }

    /**
     * Tot v1.15.0 schreef dit alleen naar storage/logs/auth.log — een
     * bestand dat nooit ergens door de applicatie werd uitgelezen (geen
     * enkel scherm bestond om het te bekijken). Nu naar cf_audit_log, dat
     * /admin/logs daadwerkelijk toont, náást geslaagde logins en de
     * belangrijkste admin-acties. De mislukte pogingen voeden ook
     * LoginThrottle (rem per IP).
     */
    private function logFailedAttempt(string $identifier): void
    {
        $this->audit->log('auth.login_failed', null, null, ['identifier' => mb_substr($identifier, 0, 100)]);
    }

    private function startSecureSession(): void
    {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
        ini_set('session.use_strict_mode', '1');
        // Lax, niet Strict: bij "inloggen met Google/Discord/GitHub" komt de
        // browser via een redirect van de provider terug op /auth/{provider}/callback.
        // Dat is een cross-site navigatie, en bij SameSite=Strict wordt het
        // sessiecookie dan NIET meegestuurd — de opgeslagen OAuth-state is dan
        // weg en elke OAuth-login faalt op "state mismatch". Lax stuurt het
        // cookie wel mee bij zo'n top-level GET-navigatie (en nooit bij
        // cross-site POST's of subrequests); POST-formulieren blijven beveiligd
        // door het CSRF-token.
        ini_set('session.cookie_samesite', 'Lax');
        session_start();
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : AuthManager.php                                      ║
// ║  Role         : Core                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-06-06                                           ║
// ║  Last Updated : 2026-06-06  03:00                                    ║
// ║  Status       : New                                                  ║
// ║  Notes        : Login, logout, sessie, argon2id                      ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
