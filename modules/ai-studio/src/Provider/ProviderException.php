<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Provider;

use CommunityFusion\Modules\AiStudio\Http\TransportException;
use CommunityFusion\Modules\AiStudio\SecretRedactor;

/**
 * Fout van een provider. Het bericht is altijd veilig om aan de gebruiker te
 * tonen: key-patronen en bekende geheimen zijn eruit gehaald en de lengte is
 * begrensd. Het bevat nooit de ruwe response-body.
 */
final class ProviderException extends \RuntimeException
{
    /**
     * @param list<string> $secrets
     */
    public static function fromTransport(string $label, TransportException $e, array $secrets = []): self
    {
        $detail = $e->status > 0 ? 'HTTP ' . $e->status : $e->getMessage();
        if ($e->status === 401 || $e->status === 403) {
            $detail .= ' (key geweigerd)';
        } elseif ($e->status === 429) {
            $detail .= ' (rate limit of tegoed op)';
        }
        $message = $label . ': ' . $detail;
        return new self(mb_substr(SecretRedactor::redact($message, $secrets), 0, 300));
    }

    /**
     * @param list<string> $secrets
     */
    public static function remote(string $label, string $message, array $secrets = []): self
    {
        $clean = SecretRedactor::redact(strip_tags($message), $secrets);
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $clean);
        return new self(mb_substr($label . ': ' . $clean, 0, 300));
    }
}
