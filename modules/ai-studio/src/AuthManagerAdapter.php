<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio;

use CommunityFusion\Core\Auth\AuthManager;

final class AuthManagerAdapter implements StudioAuth
{
    public function __construct(private readonly AuthManager $auth)
    {
    }

    public function id(): ?int
    {
        return $this->auth->id();
    }

    public function username(): ?string
    {
        $user = $this->auth->user();
        $name = $user['username'] ?? null;
        return is_string($name) ? $name : null;
    }

    public function can(string $permission): bool
    {
        return $this->auth->can($permission);
    }

    public function authorize(string $permission): void
    {
        $this->auth->authorize($permission);
    }
}
