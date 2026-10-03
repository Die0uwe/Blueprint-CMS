<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Auth\OAuth;

/**
 * Het aan deze provider-identiteit gekoppelde account is gedeactiveerd of
 * verwijderd. De login wordt geweigerd; er wordt bewust GEEN nieuw account
 * aangemaakt (anders omzeilt een geblokkeerde gebruiker zijn blokkade).
 */
final class OAuthAccountDisabledException extends \RuntimeException
{
}
