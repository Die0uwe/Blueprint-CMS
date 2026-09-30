<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

/**
 * HTTP-transport voor alle Discord-aanroepen. Een interface zodat DiscordApi,
 * DiscordWebhook en de blokken zonder netwerk getest kunnen worden.
 */
interface DiscordTransport
{
    /**
     * @param array<int,string> $headers Regels als "Naam: waarde".
     * @return array{status:int, headers:array<string,string>, body:string} headers met kleine letters als sleutel
     * @throws DiscordTransportException bij netwerk-/TLS-/timeoutfouten (geen HTTP-antwoord)
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): array;
}
