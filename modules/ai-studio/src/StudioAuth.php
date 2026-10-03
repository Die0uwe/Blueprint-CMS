<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio;

/**
 * Wat AI Studio nodig heeft van de ingelogde gebruiker. Een eigen kleine
 * interface omdat Core\Auth\AuthManager final is (en dus niet te vervangen in tests).
 */
interface StudioAuth
{
    public function id(): ?int;

    public function username(): ?string;

    public function can(string $permission): bool;

    /**
     * @throws \CommunityFusion\Core\HttpException 403 zonder permissie
     */
    public function authorize(string $permission): void;
}
