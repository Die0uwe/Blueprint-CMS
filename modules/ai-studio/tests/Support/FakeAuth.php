<?php

// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Tests\Support;

use CommunityFusion\Core\HttpException;
use CommunityFusion\Modules\AiStudio\StudioAuth;

final class FakeAuth implements StudioAuth
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(
        public ?int $userId = 1,
        public array $permissions = ['aistudio.use', 'aistudio.admin'],
        public string $name = 'tester',
    ) {
    }

    public function id(): ?int
    {
        return $this->userId;
    }

    public function username(): ?string
    {
        return $this->name;
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function authorize(string $permission): void
    {
        if (!$this->can($permission)) {
            throw new HttpException("Toegang geweigerd: '{$permission}' vereist.", 403);
        }
    }
}
