<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Auth;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Database\Connection;

/**
 * Wachtwoord vergeten / herstellen.
 *
 * Eigenschappen:
 *  - Het token is 256 bit willekeurig (random_bytes); in de database staat
 *    alleen de SHA-256-hash. Een database-lek geeft dus geen bruikbare links.
 *  - Eenmalig (used_at) en 60 minuten geldig. Een nieuwe aanvraag maakt eerdere
 *    onbenutte tokens van die gebruiker ongeldig.
 *  - Geen accountonthulling: createToken() geeft voor onbekende, uitgeschakelde
 *    of geblokkeerde gebruikers gewoon null; de controller toont altijd
 *    dezelfde melding.
 *  - Limieten: per IP en per gebruiker per uur (zie constanten). De IP-teller
 *    gebruikt cf_audit_log, zodat ook aanvragen voor onbekende accounts tellen.
 *  - Na een geslaagde reset worden alle andere onbenutte tokens verwijderd en
 *    verliezen bestaande sessies hun geldigheid (sessionRevoked()).
 *
 * Alle tijdsvergelijkingen gebeuren in SQL (NOW()), zodat PHP- en
 * database-tijdzone elkaar niet kunnen tegenspreken.
 */
final class PasswordResetService
{
    public const TTL_SECONDS            = 3600;
    public const MAX_PER_USER_PER_HOUR  = 3;
    public const MAX_PER_IP_PER_HOUR    = 5;
    public const MIN_PASSWORD_LENGTH    = 8;
    public const MAX_PASSWORD_LENGTH    = 1024; // argon2id is duur; voorkom absurd lange invoer

    public const AUDIT_REQUESTED = 'auth.password_reset_requested';
    public const AUDIT_COMPLETED = 'auth.password_reset_completed';

    public function __construct(
        private readonly Connection $db,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Maak een reset-token voor de gebruiker met dit e-mailadres of deze
     * gebruikersnaam.
     *
     * @return array{token: string, user: array{id: int, username: string, email: string}, ttl_minutes: int}|null
     *         null als er (om welke reden ook) geen mail verstuurd moet worden.
     */
    public function createToken(string $identifier, string $ip): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '' || strlen($identifier) > 255) {
            return null;
        }

        if ($this->countRecentRequestsFromIp($ip) >= self::MAX_PER_IP_PER_HOUR) {
            return null;
        }

        $user = $this->db->fetchOne(
            'SELECT id, username, email FROM cf_users
              WHERE (email = ? OR username = ?) AND is_active = 1 AND deleted_at IS NULL
              LIMIT 1',
            [$identifier, $identifier]
        );

        // Elke aanvraag (ook voor onbekende accounts) telt mee voor de IP-limiet.
        $this->audit->log(
            self::AUDIT_REQUESTED,
            $user !== null ? (int) $user['id'] : null,
            $user !== null ? (string) $user['username'] : null,
            ['found' => $user !== null],
            $ip
        );

        if ($user === null || !self::isMailable((string) $user['email'])) {
            return null;
        }

        $recent = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM cf_password_resets
              WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)',
            [$user['id']]
        );
        if ((int) ($recent['c'] ?? 0) >= self::MAX_PER_USER_PER_HOUR) {
            return null;
        }

        // Eerdere onbenutte tokens van deze gebruiker laten we verlopen (niet
        // verwijderen: de rijen tellen mee voor de limiet hierboven), en we
        // ruimen oude rijen op. Gebruikte rijen blijven 30 dagen staan omdat ze
        // sessies kunnen intrekken (sessionRevoked()).
        $this->db->execute(
            'UPDATE cf_password_resets SET expires_at = NOW()
              WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()',
            [$user['id']]
        );
        $this->db->execute(
            'DELETE FROM cf_password_resets WHERE created_at < (NOW() - INTERVAL 1 DAY) AND used_at IS NULL'
        );
        $this->db->execute('DELETE FROM cf_password_resets WHERE used_at < (NOW() - INTERVAL 30 DAY)');

        $token = bin2hex(random_bytes(32));
        $this->db->execute(
            'INSERT INTO cf_password_resets (user_id, token_hash, expires_at, requested_ip)
             VALUES (?, ?, NOW() + INTERVAL ? SECOND, ?)',
            [$user['id'], self::hashToken($token), self::TTL_SECONDS, $ip]
        );

        return [
            'token'       => $token,
            'user'        => [
                'id'       => (int) $user['id'],
                'username' => (string) $user['username'],
                'email'    => (string) $user['email'],
            ],
            'ttl_minutes' => intdiv(self::TTL_SECONDS, 60),
        ];
    }

    /**
     * Is dit token nog bruikbaar? (Bestaat, niet gebruikt, niet verlopen,
     * account actief.)
     *
     * @return array{id: int, user_id: int, username: string}|null
     */
    public function findValid(string $token): ?array
    {
        if (!self::isWellFormedToken($token)) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT r.id, r.user_id, u.username
               FROM cf_password_resets r
               JOIN cf_users u ON u.id = r.user_id
              WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()
                AND u.is_active = 1 AND u.deleted_at IS NULL',
            [self::hashToken($token)]
        );
        return $row === null ? null : [
            'id'       => (int) $row['id'],
            'user_id'  => (int) $row['user_id'],
            'username' => (string) $row['username'],
        ];
    }

    /**
     * Zet een nieuw wachtwoord met een geldig token. Geeft false als het token
     * niet (meer) geldig is. Wachtwoordregels horen bij validatePassword();
     * deze methode controleert ze nog eens als vangnet.
     */
    public function reset(string $token, string $password): bool
    {
        if (!self::isWellFormedToken($token) || self::validatePassword($password, $password) !== []) {
            return false;
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 3,
        ]);

        $username = null;
        $userId   = null;

        $ok = (bool) $this->db->transaction(function () use ($token, $hash, &$username, &$userId): bool {
            // FOR UPDATE: twee gelijktijdige verzoeken met hetzelfde token mogen
            // het niet allebei gebruiken.
            $row = $this->db->fetchOne(
                'SELECT r.id, r.user_id, u.username
                   FROM cf_password_resets r
                   JOIN cf_users u ON u.id = r.user_id
                  WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()
                    AND u.is_active = 1 AND u.deleted_at IS NULL
                  FOR UPDATE',
                [self::hashToken($token)]
            );
            if ($row === null) {
                return false;
            }

            $this->db->execute('UPDATE cf_users SET password_hash = ? WHERE id = ?', [$hash, $row['user_id']]);
            $this->db->execute('UPDATE cf_password_resets SET used_at = NOW() WHERE id = ?', [$row['id']]);
            $this->db->execute(
                'DELETE FROM cf_password_resets WHERE user_id = ? AND used_at IS NULL',
                [$row['user_id']]
            );

            $userId   = (int) $row['user_id'];
            $username = (string) $row['username'];
            return true;
        });

        if ($ok && $userId !== null) {
            $this->audit->log(self::AUDIT_COMPLETED, $userId, $username);
        }
        return $ok;
    }

    /**
     * Is de sessie van deze gebruiker ingetrokken omdat er sinds het inloggen
     * een wachtwoordreset is voltooid? Faalt veilig naar "niet ingetrokken"
     * (bijvoorbeeld als de tabel nog niet gemigreerd is), zodat een ontbrekende
     * migratie de hele site nooit onbereikbaar maakt.
     */
    public static function sessionRevoked(Connection $db, int $userId, int $loginTime): bool
    {
        if ($loginTime <= 0) {
            return false;
        }
        try {
            $row = $db->fetchOne(
                'SELECT 1 AS x FROM cf_password_resets
                  WHERE user_id = ? AND used_at IS NOT NULL AND used_at > FROM_UNIXTIME(?)
                  LIMIT 1',
                [$userId, $loginTime]
            );
            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string> foutmeldingen (leeg = geldig)
     */
    public static function validatePassword(string $password, string $confirm): array
    {
        $errors = [];
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = 'Wachtwoord minimaal ' . self::MIN_PASSWORD_LENGTH . ' tekens.';
        }
        if (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            $errors[] = 'Wachtwoord is te lang.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Wachtwoorden komen niet overeen.';
        }
        return $errors;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormedToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}\z/', $token) === 1;
    }

    /**
     * OAuth-registraties zonder e-mailadres krijgen een placeholder op een
     * niet-bestaand domein (zie AuthManager::uniqueEmailFrom()); daar valt
     * niets heen te mailen.
     */
    private static function isMailable(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && !str_ends_with(strtolower($email), '.invalid');
    }

    private function countRecentRequestsFromIp(string $ip): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM cf_audit_log
              WHERE action = ? AND ip_address = ? AND created_at > (NOW() - INTERVAL 1 HOUR)',
            [self::AUDIT_REQUESTED, $ip]
        );
        return (int) ($row['c'] ?? 0);
    }
}
