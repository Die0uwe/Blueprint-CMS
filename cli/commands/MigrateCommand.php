<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Cli\Commands;

use CommunityFusion\Core\Database\Connection;

/**
 * Migrate Command
 *
 * `migrate` stond al sinds v1.0.0 in de `console.php`-help en de docblock
 * bovenaan, maar deze class bestond niet — `cli/commands/` had alleen
 * QueueWorkerCommand en CacheClearCommand (zie CHANGELOG v1.9.0). Er is geen
 * per-versie migratiesysteem (geen `migrations/0001_xxx.php` e.d.) — het
 * project heeft één doorlopend `schema.sql`, dus dit commando doet wat de
 * installer (stap 5) ook al doet: `InstallerCore::importSchema()` hergebruiken
 * i.p.v. de SQL-split-en-uitvoer-logica dupliceren. Dat betekent ook: dit is
 * idempotent (bestaande tabellen worden overgeslagen, zie importSchema()),
 * geen destructieve DROP TABLE's.
 *
 * Gebruik: php cli/console.php migrate
 */
final class MigrateCommand
{
    public function handle(array $argv): void
    {
        if (!file_exists(CF_ROOT . '/config/config.php')) {
            echo "❌ Geen config/config.php gevonden — draai eerst de installer (public/installer/)\n";
            echo "   of maak config/config.php handmatig aan (zie config/config.php in de SD).\n";
            exit(1);
        }

        echo "🗄️  Database schema importeren...\n";

        // Bootstrap de applicatie. boot() was tot Wave 2 private (zie
        // Application::boot() docblock) — zonder deze aanroep registreert
        // niets de Connection-singleton en gooit make() hieronder altijd een
        // "Kan parameter niet resolven"-fout i.p.v. een echte verbindingsfout.
        $app = require CF_ROOT . '/src/Core/Application.php';

        try {
            $app->boot();
        } catch (\PDOException $e) {
            echo "❌ Kan geen verbinding maken met de database: {$e->getMessage()}\n";
            echo "   Controleer config/config.php → 'database' (host/poort/gebruiker/wachtwoord).\n";
            exit(1);
        }

        /** @var Connection $db */
        $db  = $app->make(Connection::class);
        $pdo = $db->getPdo();

        // InstallerCore is bewust een niet-namespaced, niet-PSR-4 class (de
        // installer laadt zelf geen Composer/framework-klassen) — handmatige
        // require, zoals de installer dat zelf ook doet.
        if (!class_exists('InstallerCore', false)) {
            require_once CF_ROOT . '/installer/InstallerCore.php';
        }

        try {
            \InstallerCore::importSchema($pdo);
        } catch (\PDOException $e) {
            echo "❌ Schema-import mislukt: {$e->getMessage()}\n";
            exit(1);
        }

        $tableCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"
        )->fetchColumn();

        echo "✅ Schema geïmporteerd — {$tableCount} tabellen aanwezig in de database.\n";
    }
}
