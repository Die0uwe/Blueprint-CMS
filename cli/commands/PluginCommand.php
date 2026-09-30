<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Cli\Commands;

use CommunityFusion\Core\Marketplace\PackageException;
use CommunityFusion\Core\Plugin\PluginManager;

/**
 * Plugin-beheer vanaf de command line. Wie dit kan draaien heeft shell-toegang: dat is hier de
 * vertrouwensgrens (zoals bij module:install), dus geen RBAC- en geen ALLOW_PLUGIN_UPLOAD-check.
 *
 *   php cli/console.php plugin:list
 *   php cli/console.php plugin:install <bestand.zip>
 *   php cli/console.php plugin:activate <slug>
 *   php cli/console.php plugin:deactivate <slug>
 *   php cli/console.php plugin:migrate <slug>
 */
final class PluginCommand
{
    public function handle(array $argv): void
    {
        $cmd = (string)($argv[1] ?? '');
        $arg = (string)($argv[2] ?? '');
        if (!file_exists(CF_ROOT . '/config/config.php')) {
            echo "❌ Geen config/config.php gevonden — draai eerst de installer.\n";
            exit(1);
        }
        $app = require CF_ROOT . '/src/Core/Application.php';
        try {
            $app->boot();
        } catch (\PDOException $e) {
            echo "❌ Geen databaseverbinding: {$e->getMessage()}\n";
            exit(1);
        }
        /** @var PluginManager $pm */
        $pm = $app->make(PluginManager::class);
        $actor = ['id' => null, 'username' => 'cli'];

        try {
            switch ($cmd) {
                case 'plugin:list':
                    foreach ($pm->discover() as $p) {
                        printf("%-28s %-10s %-9s%s\n", $p['slug'], $p['version'], $p['status'], $p['needs_migration'] ? '  (migratie open)' : '');
                        foreach ($p['errors'] as $err) {
                            echo "    ⚠ {$err}\n";
                        }
                    }
                    break;
                case 'plugin:install':
                    if ($arg === '' || !is_file($arg)) {
                        echo "Gebruik: php cli/console.php plugin:install <bestand.zip>\n";
                        exit(1);
                    }
                    $slug = $pm->installFromUpload($arg, basename($arg), $actor);
                    echo "✅ '{$slug}' geïnstalleerd (nog niet actief). Activeer met: plugin:activate {$slug}\n";
                    break;
                case 'plugin:activate':
                    $pm->activate($this->slug($arg), $actor);
                    echo "✅ '{$arg}' geactiveerd.\n";
                    break;
                case 'plugin:deactivate':
                    $pm->deactivate($this->slug($arg), $actor);
                    echo "✅ '{$arg}' gedeactiveerd.\n";
                    break;
                case 'plugin:migrate':
                    $done = $pm->migrate($this->slug($arg));
                    echo $done === [] ? "Geen openstaande migraties.\n" : 'Migraties uitgevoerd: ' . implode(', ', $done) . "\n";
                    break;
                default:
                    echo "Onbekend plugin-commando.\n";
                    exit(1);
            }
        } catch (PackageException $e) {
            echo "❌ {$e->getMessage()}\n";
            exit(1);
        }
    }

    private function slug(string $s): string
    {
        if ($s === '') {
            echo "Geef een plugin-naam (slug) op.\n";
            exit(1);
        }
        return $s;
    }
}
