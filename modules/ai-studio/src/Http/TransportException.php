<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Http;

/**
 * Netwerkfout of niet-2xx antwoord. $status is 0 bij een netwerkfout.
 * $body is het (afgekapte) foutantwoord van de remote en kan dus content van
 * buiten bevatten: nooit ongefilterd tonen (zie SecretRedactor).
 */
final class TransportException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $body = '',
    ) {
        parent::__construct($message);
    }
}
