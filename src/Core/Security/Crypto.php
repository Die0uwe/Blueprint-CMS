<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Security;

/**
 * Crypto — gedeelde AES-256-GCM encryptie op basis van APP_KEY.
 *
 * Golf 10 (OAuth-providers): dit was vóór deze golf gedupliceerde,
 * private logica in OAuthClient::encrypt()/decrypt() (voor
 * cf_user_oauth-tokens). SettingsRepository had een 'encrypted'
 * kolomtype in het schema staan dat NOOIT werd gebruikt — set() sloeg
 * altijd platte tekst op en get() deed nooit een decrypt-poging. Voor
 * een OAuth client_secret of bot-token die een beheerder straks via
 * het nieuwe /admin/marketplace/package/{slug}/instellingen-scherm
 * invoert, is platte tekst in cf_settings onacceptabel — dat is
 * precies zo gevoelig als de tokens die al wél versleuteld worden.
 * Deze klasse is de ene bron van waarheid voor beide plekken.
 */
final class Crypto
{
    public static function encrypt(string $value): string
    {
        $key = self::appKey();
        $iv  = random_bytes(12);
        $tag = '';
        $enc = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($enc === false) {
            throw new \RuntimeException('Encryptie mislukt.');
        }
        return base64_encode($iv . $tag . $enc);
    }

    public static function decrypt(string $encrypted): string
    {
        $key = self::appKey();
        $raw = base64_decode($encrypted, true);
        if ($raw === false || strlen($raw) < 28) {
            return ''; // Geen geldige encrypted payload (bv. nog platte oude waarde)
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $enc = substr($raw, 28);
        $out = openssl_decrypt($enc, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $out === false ? '' : $out;
    }

    private static function appKey(): string
    {
        $key = $_ENV['APP_KEY'] ?? '';
        if (str_starts_with($key, 'base64:')) {
            return base64_decode(substr($key, 7));
        }
        return str_pad($key, 32, "\0");
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: Crypto.php | Role: Core | Version: 1.0.0                     ║
// ║  Created: 2026-09-29 | Status: New — Golf 10 (OAuth providers)      ║
// ╚══════════════════════════════════════════════════════════════════════╝
