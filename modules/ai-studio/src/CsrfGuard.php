<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Security\CsrfProtection;

/**
 * CSRF-controle op basis van de bestaande Core\Security\CsrfProtection
 * (sessietoken, hash_equals). Het token mag in het formulierveld
 * `_csrf_token` of in de header `X-CSRF-Token` zitten; NOOIT in de
 * query-string (zie docs/security-notes.md: URL's belanden in logs/Referer).
 */
final class CsrfGuard
{
    public static function valid(Request $request): bool
    {
        // Een token in de query-string wordt bewust geweigerd (ook als hij klopt).
        if ($request->query('_csrf_token') !== null) {
            return false;
        }
        $token = $request->input('_csrf_token');
        if (!is_string($token) || $token === '') {
            $token = $request->header('X-CSRF-Token') ?? $request->header('x-csrf-token') ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        }
        if (!is_string($token) || $token === '') {
            return false;
        }
        return CsrfProtection::verify($token);
    }
}
