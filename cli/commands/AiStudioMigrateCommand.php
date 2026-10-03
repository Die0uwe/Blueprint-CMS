<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Cli\Commands;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Modules\AiStudio\SchemaMigrator;
use CommunityFusion\Modules\AiStudio\SettingsStore;

/**
 * Draait de (idempotente) migraties van de AI Studio-module en schakelt de
 * module in. Bedoeld voor BESTAANDE installaties: de installer (Step5) zet
 * nieuwe modules alleen aan bij een verse installatie, en `migrate` importeert
 * alleen schema.sql. Veilig om vaker te draaien.
 *
 * Gebruik: php cli/console.php ai-studio:migrate
 */
final class AiStudioMigrateCommand
{
    public function handle(array $argv): void
    {
        if (!file_exists(CF_ROOT . '/config/config.php')) {
            echo "Geen config/config.php gevonden - draai eerst de installer.\n";
            exit(1);
        }

        $app = require CF_ROOT . '/src/Core/Application.php';
        try {
            $app->boot();
        } catch (\PDOException $e) {
            echo "Kan geen verbinding maken met de database.\n";
            exit(1);
        }

        /** @var Connection $db */
        $db = $app->make(Connection::class);
        $migrator = new SchemaMigrator($db->getPdo(), new SettingsStore($db), CF_ROOT . '/modules/ai-studio/migrations');

        try {
            $count = $migrator->run();
        } catch (\Throwable $e) {
            echo 'Migratie mislukt: ' . get_class($e) . "\n";
            exit(1);
        }

        $manifest = json_decode((string) file_get_contents(CF_ROOT . '/modules/ai-studio/module.json'), true);
        $db->execute(
            "INSERT INTO cf_modules (slug, name, version, author, description, is_core, is_enabled)
             VALUES (?, ?, ?, ?, ?, 0, 1)
             ON DUPLICATE KEY UPDATE version = VALUES(version), is_enabled = 1",
            [
                'ai-studio',
                $manifest['name'] ?? 'Blueprint AI Studio',
                $manifest['version'] ?? '1.0.0',
                $manifest['author'] ?? '',
                $manifest['description'] ?? '',
            ]
        );

        echo "AI Studio: {$count} statements uitgevoerd, module ingeschakeld.\n";
        echo "Open /admin/ai-studio/settings om API-keys in te stellen.\n";
    }
}
