<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Cli\Commands;

use CommunityFusion\Core\Marketplace\PackageManager;
use CommunityFusion\Core\Marketplace\PackageException;

/**
 * Module Install Command
 *
 * `module:install` stond al sinds v1.0.0 in de `console.php`-help, maar deze
 * class bestond niet (zie MigrateCommand-docblock voor dezelfde geschiedenis).
 * Hergebruikt `PackageManager` — dezelfde service als `/admin/marketplace`
 * (`MarketplaceController::install()`) — i.p.v. install-logica te dupliceren.
 *
 * Bewust GEEN `AuthManager::authorize('marketplace.install')`-check: die
 * permissie hoort bij een ingelogde admin-sessie over HTTP, en die context
 * bestaat hier niet. Wie dit commando kan draaien heeft al shell-toegang tot
 * de server — dat is hier de vertrouwensgrens, niet RBAC.
 *
 * Gebruik:
 *   php cli/console.php module:install <slug>
 *   php cli/console.php module:install <slug> --url=https://.../package.zip
 */
final class ModuleInstallCommand
{
    public function handle(array $argv): void
    {
        $slug = $argv[2] ?? '';
        if ($slug === '' || str_starts_with($slug, '--')) {
            echo "Gebruik: php cli/console.php module:install <slug> [--url=<download-url>]\n";
            exit(1);
        }

        $downloadUrl = $this->getOption($argv, 'url', '');

        if (!file_exists(CF_ROOT . '/config/config.php')) {
            echo "❌ Geen config/config.php gevonden — draai eerst de installer.\n";
            exit(1);
        }

        // Zelfde boot()-aanroep als MigrateCommand — zie die docblock.
        $app = require CF_ROOT . '/src/Core/Application.php';

        try {
            $app->boot();
        } catch (\PDOException $e) {
            echo "❌ Kan geen verbinding maken met de database: {$e->getMessage()}\n";
            exit(1);
        }

        /** @var PackageManager $packages */
        $packages = $app->make(PackageManager::class);

        try {
            if ($downloadUrl === '') {
                $pkg = $packages->getCatalog(search: $slug, limit: 1)[0] ?? null;
                $downloadUrl = $pkg['download_url'] ?? '';
            }

            if ($downloadUrl === '') {
                echo "❌ Geen download-URL gevonden voor '{$slug}' in de marketplace-catalogus.\n";
                echo "   Geef er zelf één op: --url=https://.../package.zip\n";
                exit(1);
            }

            echo "📦 Installeren van '{$slug}'...\n";
            $result = $packages->install($slug, $downloadUrl);

            echo "✅ '{$result->name}' v{$result->version} ({$result->type}) geïnstalleerd naar {$result->installPath}\n";
            if ($result->message !== '') {
                echo "   {$result->message}\n";
            }
        } catch (PackageException $e) {
            echo "❌ Installatie mislukt: {$e->getMessage()}\n";
            exit(1);
        } catch (\Throwable $e) {
            echo "❌ Onverwachte fout: {$e->getMessage()}\n";
            exit(1);
        }
    }

    private function getOption(array $argv, string $name, string $default): string
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, "--{$name}=")) {
                return substr($arg, strlen("--{$name}="));
            }
        }
        return $default;
    }
}
