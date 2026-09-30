<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

use CommunityFusion\Core\Application;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Queue\Job;

/**
 * Queue-job 'discord-sync': synchroniseert de Discord-rollen van één gebruiker.
 *
 * QueueManager::push() bewaart jobs als serialize(Job) en de worker (cli queue:work) doet
 * unserialize() en roept handle() aan. Om daar niets kwetsbaars aan toe te voegen bewaart deze job
 * via __serialize/__unserialize ALLEEN het gebruikers-ID als geheel getal (geen objecten, geen
 * vrije data), en de worker draait met `--queue=discord-sync`.
 */
final class DiscordRoleSyncJob extends Job
{
    public const QUEUE = 'discord-sync';

    public function __construct(public int $userId = 0)
    {
        $this->queue = self::QUEUE;
        $this->tries = 3;
    }

    public function __serialize(): array
    {
        return ['user_id' => $this->userId];
    }

    public function __unserialize(array $data): void
    {
        $this->userId = max(0, (int) ($data['user_id'] ?? 0));
        $this->queue  = self::QUEUE;
        $this->tries  = 3;
        $this->timeout = 60;
        $this->delaySeconds = null;
    }

    public function handle(): void
    {
        $app = Application::getInstance();
        $r = (new DiscordRoleSync($app->make(Connection::class), $app->make(CacheManager::class)))->syncUser($this->userId);
        if (($r['status'] ?? '') === 'error' && !empty($r['retryable'])) {
            throw new \RuntimeException('Discord tijdelijk niet beschikbaar; opnieuw proberen.');
        }
    }
}
