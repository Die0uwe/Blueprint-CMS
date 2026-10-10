<?php

declare(strict_types=1);

/**
 * Community Fusion CMS — CLI Console
 * Gebruik: php cli/console.php <commando> [opties]
 *
 * Beschikbare commando's:
 *   queue:work          Verwerk queue jobs
 *   cache:clear         Verwijder alle cache
 *   migrate             Voer database migraties uit
 *   migrate:status      Toon welke migraties zijn uitgevoerd
 *   module:install      Installeer een module
 *   ai-studio:migrate   Migreer en activeer de AI Studio-module (bestaande installaties)
 *   backup:create|auto|list   Back-ups (zie docs/BACKUP.md)
 */

define('CF_ROOT',   dirname(__DIR__));
define('CF_PUBLIC', CF_ROOT . '/public');
define('CF_START',  microtime(true));

if (php_sapi_name() !== 'cli') {
    exit('Dit script mag alleen via CLI worden uitgevoerd.');
}

require_once CF_ROOT . '/vendor/autoload.php';

if (file_exists(CF_ROOT . '/.env')) {
    (Dotenv\Dotenv::createImmutable(CF_ROOT))->load();
}

$command = $argv[1] ?? 'help';

// `migrate` en `module:install` stonden al sinds v1.0.0 in de help-tekst en
// de docblock hierboven, maar CommunityFusion\Cli\Commands\MigrateCommand en
// ModuleInstallCommand bestonden niet — cli/commands/ bevatte tot Wave 2
// alleen QueueWorkerCommand.php en CacheClearCommand.php (zie CHANGELOG
// v1.9.0 voor de eerdere fallback-melding i.p.v. een kale fatal error).
// Beide zijn nu gebouwd — zie hun eigen docblocks voor details.
match (true) {
    $command === 'queue:work'      => (new CommunityFusion\Cli\Commands\QueueWorkerCommand())->handle($argv),
    $command === 'cache:clear'     => (new CommunityFusion\Cli\Commands\CacheClearCommand())->handle($argv),
    $command === 'migrate'         => (new CommunityFusion\Cli\Commands\MigrateCommand())->handle($argv),
    $command === 'migrate:status'  => (new CommunityFusion\Cli\Commands\MigrateCommand())->status($argv),
    $command === 'module:install'  => (new CommunityFusion\Cli\Commands\ModuleInstallCommand())->handle($argv),
    $command === 'backup:create'   => (new CommunityFusion\Cli\Commands\BackupCommand())->create($argv),
    $command === 'backup:auto'     => (new CommunityFusion\Cli\Commands\BackupCommand())->auto($argv),
    $command === 'backup:list'     => (new CommunityFusion\Cli\Commands\BackupCommand())->list($argv),
    $command === 'ai-studio:migrate' => (new CommunityFusion\Cli\Commands\AiStudioMigrateCommand())->handle($argv),
    default => printHelp(),
};

function printHelp(): void
{
    echo <<<HELP
Community Fusion CMS — CLI Console v1.0

Gebruik: php cli/console.php <commando>

Commando's:
  queue:work [--queue=default] [--sleep=3]    Verwerk queue jobs
  cache:clear                                  Verwijder alle cache
  migrate                                      Voer DB migraties uit
  migrate:status                               Toon migratiestatus
  module:install <slug>                        Installeer een module
  ai-studio:migrate                            Migreer + activeer Blueprint AI Studio
  backup:create [--uploads]                    Maak nu een back-up
  backup:auto [--force]                        Dagelijkse back-up (zet in cron op 05:00)
  backup:list                                  Toon back-ups

HELP;
}
