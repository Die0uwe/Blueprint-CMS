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
 *   module:install      Installeer een module
 *   ai-studio:migrate   Migreer en activeer de AI Studio-module (bestaande installaties)
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
    $command === 'module:install'  => (new CommunityFusion\Cli\Commands\ModuleInstallCommand())->handle($argv),
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
  module:install <slug>                        Installeer een module
  ai-studio:migrate                            Migreer + activeer Blueprint AI Studio

HELP;
}
