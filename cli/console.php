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

// `migrate` en `module:install` staan al sinds v1.0.0 in de help-tekst en de
// docblock hierboven, maar CommunityFusion\Cli\Commands\MigrateCommand en
// ModuleInstallCommand bestaan niet — cli/commands/ bevat alleen
// QueueWorkerCommand.php en CacheClearCommand.php. Zonder deze check gaf dit
// een kale "Class not found"-fatal error i.p.v. een bruikbare melding.
// Schema importeren kan tot die commando's gebouwd zijn via de installer
// (stap 5) of handmatig: `mysql db < src/Core/Database/schema.sql`.
// Module-activatie kan via de installer (stap 5) of /admin/marketplace.
$notImplemented = [
    'migrate'        => 'mysql <db> < src/Core/Database/schema.sql',
    'module:install' => '/admin/marketplace (of de installer, stap 5)',
];

match (true) {
    $command === 'queue:work'  => (new CommunityFusion\Cli\Commands\QueueWorkerCommand())->handle($argv),
    $command === 'cache:clear' => (new CommunityFusion\Cli\Commands\CacheClearCommand())->handle($argv),
    isset($notImplemented[$command]) => printf(
        "'%s' is nog niet gebouwd (staat gepland, zie CHANGELOG). Gebruik ondertussen: %s\n",
        $command,
        $notImplemented[$command]
    ),
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

HELP;
}
