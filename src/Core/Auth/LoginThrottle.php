<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Auth;

use CommunityFusion\Core\Database\Connection;

/**
 * LoginThrottle — rem op wachtwoord-raden: maximaal MAX mislukte inlogpogingen
 * per IP-adres binnen WINDOW seconden (specificatie: 5 per 15 minuten).
 *
 * Er is bewust geen nieuwe tabel: elke mislukte poging staat al als
 * `auth.login_failed` in cf_audit_log (met IP en tijdstip). Geblokkeerde pogingen
 * worden als `auth.login_blocked` gelogd en tellen NIET mee, zodat een aanvaller
 * die blijft hameren het venster niet eindeloos verlengt.
 *
 * Bewust alléén per IP, niet per account: een limiet per accountnaam laat een
 * aanvaller elke beheerder buitensluiten door er 15 keer een fout wachtwoord
 * op te proberen. Verspreide aanvallen vanaf veel IP's vangt dit dus niet af;
 * daarvoor zijn sterke wachtwoorden en (later) 2FA nodig.
 *
 * Tijd wordt volledig door de database bepaald (NOW()/UNIX_TIMESTAMP), zodat een
 * verschil tussen PHP- en databasetijdzone het venster niet verschuift.
 */
final class LoginThrottle
{
    public const MAX    = 5;
    public const WINDOW = 900;

    public function __construct(
        private readonly Connection $db,
        private readonly int $max = self::MAX,
        private readonly int $window = self::WINDOW,
    ) {}

    public function isBlocked(?string $ip = null): bool
    {
        $ip ??= $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip === null || $ip === '') {
            return false;
        }
        try {
            $row = $this->db->fetchOne(
                "SELECT COUNT(*) AS c FROM cf_audit_log
                  WHERE action = 'auth.login_failed' AND ip_address = ?
                    AND UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(created_at) <= ?",
                [$ip, $this->window]
            );
            return (int) ($row['c'] ?? 0) >= $this->max;
        } catch (\Throwable) {
            return false; // auditlog onbereikbaar: inloggen blijft mogelijk (fail-open)
        }
    }

    public function retryAfter(): int
    {
        return $this->window;
    }
}
