<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Http;

/**
 * Onveranderlijke beschrijving van één uitgaande HTTP-aanroep.
 */
final class HttpRequest
{
    /**
     * @param array<string, string> $headers
     * @param string|null           $pinnedIp IP waaraan de verbinding vastgepind wordt (DNS-rebinding-bescherming)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly int $timeoutSeconds = 60,
        public readonly bool $allowHttp = false,
        public readonly ?string $pinnedIp = null,
    ) {
    }
}
