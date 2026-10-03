<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio;

/**
 * Haalt API-keys uit tekst voordat die naar een gebruiker, log of audit gaat.
 * Twee lagen: de exacte bekende geheimen, en herkenbare key-patronen
 * (voor het geval een provider een andere/deels getoonde key terugecho't).
 */
final class SecretRedactor
{
    public const MASK = '[verborgen]';

    /**
     * @param list<string> $secrets
     */
    public static function redact(string $text, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if (strlen($secret) >= 6) {
                $text = str_replace($secret, self::MASK, $text);
            }
        }

        $patterns = [
            '/\bsk-[A-Za-z0-9_\-]{8,}/',          // OpenAI / DeepSeek / Anthropic (sk-ant-...)
            '/\bAIza[0-9A-Za-z_\-]{20,}/',        // Google
            '/\bBearer\s+[A-Za-z0-9._\-]{12,}/i', // Authorization-headers
            '/\b(?:x-api-key|x-goog-api-key|api[_-]?key)\s*[:=]\s*\S+/i',
        ];
        return (string) preg_replace($patterns, self::MASK, $text);
    }
}
