<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Provider;

interface ProviderInterface
{
    /** Vaste, URL-veilige identifier, bv. "openai". */
    public function slug(): string;

    /** Leesbare naam voor de UI. */
    public function label(): string;

    /**
     * Stream het antwoord als tekstbrokken (alleen de nieuwe delta's).
     *
     * @param list<array{role: string, content: string}> $messages rollen: system|user|assistant
     * @param array<string, mixed>                       $options  o.a. model (string), max_tokens (int)
     * @return iterable<string>
     * @throws ProviderException
     */
    public function stream(array $messages, array $options): iterable;

    /**
     * Controleer een (nog niet opgeslagen) key. true = door de provider
     * geaccepteerd. Elke fout (ook netwerk) geeft false; er wordt niets gelogd.
     */
    public function validateKey(string $key): bool;
}
