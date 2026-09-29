<?php

declare(strict_types=1);

/**
 * Community Fusion CMS — Front Controller
 * Enige publieke entry point. Alle requests komen hier binnen.
 */

define('CF_VERSION', '1.0.0');
define('CF_ROOT',    dirname(__DIR__));
define('CF_PUBLIC',  __DIR__);
define('CF_START',   microtime(true));

// Composer autoloader
require_once CF_ROOT . '/vendor/autoload.php';

// Laad environment variabelen
if (file_exists(CF_ROOT . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(CF_ROOT);
    $dotenv->load();
}

// Controleer of installer nog gedraaid moet worden.
//
// Voorheen stuurde dit een HTTP-redirect naar /installer/ — dat werkt
// alleen als installer/ zelf via een eigen URL bereikbaar is. README.md
// §2.1 documenteert public/ nadrukkelijk als het enige document root
// (en public/.htaccess gaat daar ook van uit); installer/ staat bewust
// ERNAAST, net als config/ en storage/, zodat die nooit direct als
// bestand opvraagbaar zijn. Op een correct geconfigureerde server
// (DocumentRoot = public/) bestaat er voor Apache/nginx dus helemaal
// geen "/installer/" — de redirect liet bezoekers op een kale 404
// landen en de installer leek daardoor "niet te starten" bij een echte
// serverupload (in de PHP-ingebouwde-server-testomgeving viel dit niet
// op omdat daar met DocumentRoot = projectroot werd getest).
//
// Oplossing: geen redirect, maar de echte installer/index.php meteen
// vanaf hier includen. Dat bestand blijft fysiek op zijn eigen plek
// staan (CF_ROOT/installer/), dus zijn eigen dirname(__DIR__)-paden
// (config.php wegschrijven, schema.sql inlezen, storage/-checks, …)
// blijven kloppen ongeacht vanaf welke document root dit front
// controller-bestand is aangeroepen. De installer-forms posten zonder
// action-attribuut (dus terug naar de huidige URL) en de "?step=N"-
// redirects zijn relatief, dus dit is voor de browser volledig
// transparant — de adresbalk verandert niet en hoeft ook niet te
// veranderen.
if (!file_exists(CF_ROOT . '/config/config.php') && is_dir(CF_ROOT . '/installer')) {
    require CF_ROOT . '/installer/index.php';
    exit;
}

// Bootstrap de applicatie
$app = require_once CF_ROOT . '/src/Core/Application.php';
$app->run();
