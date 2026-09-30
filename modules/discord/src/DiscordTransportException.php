<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

/** Er kwam helemaal geen HTTP-antwoord (DNS, TLS, timeout, verbinding verbroken). */
final class DiscordTransportException extends \RuntimeException {}
