<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Security;

/**
 * SafeRedirect — laat alleen redirects binnen de eigen site toe.
 *
 * Een ?redirect=-parameter komt van de bezoeker en mag nooit naar een andere
 * host wijzen (open redirect / phishing). Toegestaan is uitsluitend een pad
 * dat met precies één "/" begint.
 */
final class SafeRedirect
{
    public static function target(mixed $value, string $default = '/'): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 2000) {
            return $default;
        }
        // Moet met "/" beginnen, maar niet met "//" (protocol-relatief) of "/\" (browsers
        // behandelen "\" als "/"), en mag geen stuurtekens of schema bevatten.
        if ($value[0] !== '/' || (isset($value[1]) && ($value[1] === '/' || $value[1] === '\\'))) {
            return $default;
        }
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $value) === 1) {
            return $default;
        }
        return $value;
    }
}
