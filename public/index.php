<?php

declare(strict_types=1);

/**
 * Community Fusion CMS — Front Controller
 * Enige publieke entry point. Alle requests komen hier binnen.
 */

define('CF_VERSION', '1.30.0');
define('CF_ROOT',    dirname(__DIR__));
define('CF_PUBLIC',  __DIR__);
define('CF_START',   microtime(true));

// Composer autoloader.
//
// v1.26.1: dit was voorheen een kale require_once zonder check — als
// `composer install` nooit is uitgevoerd (heel gebruikelijk zodra iemand
// de repo als ZIP downloadt of uploadt naar shared hosting zonder
// SSH-toegang) crashte PHP hier meteen op regel 1 van élke request, óók
// vóór de installer-dispatch hieronder ooit bereikt werd. Met
// display_errors=Off (de veilige standaard op bijna elke productie-host)
// levert dat een volledig LEEG wit scherm op: geen foutmelding, geen
// installer, geen enkele aanwijzing wat er mis is — precies het "de map
// is leeg, er is geen verwijzing naar de installer"-symptoom. Met
// display_errors=On lekt het in plaats daarvan het volledige serverpad.
// Beide zijn onacceptabel voor een CMS dat zichzelf adverteert als
// "installeren zonder programmeerkennis". Nu: een expliciete check mét
// een duidelijke, Nederlandstalige uitlegpagina die precies zegt wat te
// doen, in beide gangbare situaties (SSH beschikbaar / alleen FTP).
if (!file_exists(CF_ROOT . '/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="nl"><head><meta charset="utf-8">
    <title>Installatie onvolledig — Blueprint CMS</title>
    <style>
        body{font-family:system-ui,sans-serif;background:#12141a;color:#e6e8ec;
             max-width:680px;margin:60px auto;padding:0 20px;line-height:1.6}
        h1{color:#f0b429;font-size:1.4rem}
        code,pre{background:#1d2027;color:#7ee787;padding:2px 6px;border-radius:4px;
                 display:inline-block}
        pre{display:block;padding:14px;overflow-x:auto}
        .box{background:#1a1d24;border:1px solid #2b2f3a;border-radius:8px;
             padding:18px 22px;margin:18px 0}
    </style></head><body>
    <h1>⚠️ Composer-dependencies ontbreken</h1>
    <p>Blueprint CMS kan niet starten omdat <code>vendor/autoload.php</code> niet
    bestaat. Dit gebeurt wanneer <code>composer install</code> nog niet is
    uitgevoerd in de projectmap — bijvoorbeeld na het uploaden van een kale
    GitHub-download naar shared hosting.</p>
    <div class="box">
        <strong>Heb je SSH/terminal-toegang tot je server?</strong>
        <pre>cd (projectmap)
        composer install --no-dev --optimize-autoloader</pre>
        <p>Herlaad daarna deze pagina — de installer start dan automatisch.</p>
    </div>
    <div class="box">
        <strong>Alleen FTP/bestandsbeheer (geen terminal)?</strong>
        <p>Composer draait dan niet op de server zelf. Draai
        <code>composer install --no-dev --optimize-autoloader</code> lokaal op je
        eigen computer (in dezelfde projectmap, vóór het uploaden) en upload de
        hele projectmap — inclusief de dan aangemaakte <code>vendor/</code>-map —
        in één keer naar je hosting.</p>
    </div>
    <p style="opacity:.7;font-size:.9em">Zie README.md §Installatie voor de volledige
    instructies.</p>
    </body></html>
    HTML;
    exit;
}
require_once CF_ROOT . '/vendor/autoload.php';

// Laad environment variabelen
if (file_exists(CF_ROOT . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(CF_ROOT);
    $dotenv->load();
}

// Achter Cloudflare/nginx: echte bezoekers-IP en https herstellen (alleen als de
// verbinding van een vertrouwde proxy komt — zie TrustedProxy). Moet vóór alles
// draaien dat $_SERVER['REMOTE_ADDR'] / ['HTTPS'] leest (sessie, inlog-rem, logs).
\CommunityFusion\Core\Http\TrustedProxy::apply(
    $_SERVER,
    array_filter(array_map('trim', explode(',', (string) ($_ENV['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES') ?: ''))))
);

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
