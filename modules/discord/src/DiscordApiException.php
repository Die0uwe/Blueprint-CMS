<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

/**
 * Fout van de Discord-API. getMessage() is een Nederlandse, voor de beheerder bedoelde melding
 * (bevat nooit een token of webhook-URL).
 */
final class DiscordApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $discordMessage = null,
        public readonly ?int $discordCode = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }
}
