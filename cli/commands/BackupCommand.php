<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Cli\Commands;

use CommunityFusion\Modules\Backup\BackupScheduler;
use CommunityFusion\Modules\Backup\BackupService;

/**
 * php cli/console.php backup:create [--uploads]   handmatige back-up
 * php cli/console.php backup:auto   [--force]     dagelijkse back-up als die aan de beurt is (cron 05:00)
 * php cli/console.php backup:list                 overzicht
 */
final class BackupCommand
{
    private function boot(): array
    {
        if (!file_exists(CF_ROOT . '/config/config.php')) {
            fwrite(STDERR, "❌ Geen config/config.php gevonden — draai eerst de installer.\n");
            exit(1);
        }
        $app = require CF_ROOT . '/src/Core/Application.php';
        $app->boot();
        $c = $app->getContainer();
        return [$c->make(BackupService::class), $c->make(BackupScheduler::class)];
    }

    public function create(array $argv): void
    {
        [$service] = $this->boot();
        try {
            $info = $service->create('manual', in_array('--uploads', $argv, true));
        } catch (\Throwable $e) {
            fwrite(STDERR, '❌ ' . $e->getMessage() . "\n");
            exit(1);
        }
        echo "✅ {$info['name']} (" . round($info['size'] / 1024) . " KB)\n";
    }

    public function auto(array $argv): void
    {
        [, $scheduler] = $this->boot();
        try {
            $info = $scheduler->runIfDue(null, in_array('--force', $argv, true));
        } catch (\Throwable $e) {
            fwrite(STDERR, '❌ ' . $e->getMessage() . "\n");
            exit(1);
        }
        echo $info === null ? "Niets te doen (back-up van vandaag bestaat al, staat uit, of nog niet aan de beurt).\n"
                            : "✅ {$info['name']}\n";
    }

    public function list(array $argv): void
    {
        [$service] = $this->boot();
        foreach ($service->list() as $b) {
            printf("%-46s %-12s %8.1f KB\n", $b['name'], $b['type'], $b['size'] / 1024);
        }
    }
}
