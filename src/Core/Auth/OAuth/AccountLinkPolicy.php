<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Auth\OAuth;

use CommunityFusion\Core\Database\Connection;

/**
 * AccountLinkPolicy — mag een gebruiker een gekoppeld account ontkoppelen?
 *
 * Regel: alleen als hij daarna nog kan inloggen, dus als hij óf nog een
 * andere koppeling heeft, óf een eigen wachtwoord.
 *
 * `cf_users.password_set` = 0 markeert een account dat via OAuth is aangemaakt en een willekeurige,
 * onbruikbare hash heeft (AuthManager::findOrCreateFromOAuth). Alleen als die kolom nog ontbreekt
 * (migrate niet gedraaid) valt de regel terug op een heuristiek: we herkennen zo'n account aan zijn
 * eerste koppeling: die wordt in dezelfde request als het account aangemaakt
 * (verschil <= OAUTH_CREATED_WINDOW seconden). Een wachtwoord-account dat
 * binnen dat venster koppelt, wordt daardoor conservatief als "geen
 * wachtwoord" behandeld — liever een onnodige blokkade dan een buitengesloten
 * gebruiker.
 */
final class AccountLinkPolicy
{
    public const OAUTH_CREATED_WINDOW = 120;

    /** Zuivere regel, los van de database. */
    public static function decide(bool $isLinked, int $otherLinks, bool $hasOwnPassword): bool
    {
        if (!$isLinked) {
            return false;
        }
        return $otherLinks > 0 || $hasOwnPassword;
    }

    public static function canDisconnect(Connection $db, int $userId, string $provider): bool
    {
        $links = $db->fetchAll(
            "SELECT provider, created_at FROM cf_user_oauth WHERE user_id = ? ORDER BY created_at ASC, id ASC",
            [$userId]
        );

        $isLinked = false;
        $others   = 0;
        foreach ($links as $l) {
            if ($l['provider'] === $provider) {
                $isLinked = true;
            } else {
                $others++;
            }
        }

        try {
            $user = $db->fetchOne("SELECT created_at, password_set FROM cf_users WHERE id = ?", [$userId]);
            $hasPassword = (int)($user['password_set'] ?? 1) === 1;
        } catch (\Throwable) {
            // Kolom password_set ontbreekt (migrate nog niet gedraaid): terugvallen op de heuristiek
            $user = $db->fetchOne("SELECT created_at FROM cf_users WHERE id = ?", [$userId]);
            $hasPassword = self::hasOwnPassword($user['created_at'] ?? null, $links[0]['created_at'] ?? null);
        }

        return self::decide($isLinked, $others, $hasPassword);
    }

    /** Heuristiek, zie de klassedoc. Zonder koppelingen is het wachtwoord de enige manier van inloggen. */
    public static function hasOwnPassword(?string $userCreatedAt, ?string $firstLinkCreatedAt): bool
    {
        if ($firstLinkCreatedAt === null || $userCreatedAt === null) {
            return true;
        }
        $u = strtotime($userCreatedAt);
        $l = strtotime($firstLinkCreatedAt);
        if ($u === false || $l === false) {
            return false; // onleesbaar: conservatief blokkeren
        }
        return ($l - $u) > self::OAUTH_CREATED_WINDOW;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: AccountLinkPolicy.php | Role: Core | Version: 1.0.0          ║
// ╚══════════════════════════════════════════════════════════════════════╝
