<?php
declare(strict_types=1);
// Step 5 — Modules selecteren + Config schrijven + Afronden

// Alleen slugs accepteren die ook echt als modules/*/module.json bestaan —
// nooit de ruwe POST-waarden ongefilterd vertrouwen. Kernmodules (Users/
// News/Pages/Settings/Forum/Blog/Downloads/Contact) staan hier bewust NIET
// bij: die zijn hardcoded actief via Router::registerCoreRoutes() en hebben
// geen cf_modules-vlag nodig.
$availableOptionalModules = [];
foreach (glob(CF_ROOT . '/modules/*/module.json') ?: [] as $manifestPath) {
    $manifest = json_decode(file_get_contents($manifestPath), true);
    if (is_array($manifest) && !empty($manifest['slug'])) {
        $availableOptionalModules[$manifest['slug']] = $manifest;
    }
}

$requestedModules = array_map('strval', $_POST['modules'] ?? []);
$selectedModules   = array_values(array_intersect($requestedModules, array_keys($availableOptionalModules)));

$db   = InstallerCore::getData('db');
$site = InstallerCore::getData('site');

// Schrijf config bestand
InstallerCore::writeConfig([
    'date'            => date('Y-m-d H:i:s'),
    'site_name_php'   => var_export($site['siteName'], true),
    'site_url_php'    => var_export($site['siteUrl'], true),
    'timezone_php'    => var_export($site['timezone'], true),
    'locale_php'      => var_export($site['locale'], true),
    'mail_php'        => var_export($site['mail'] ?: 'noreply@example.com', true),
    'db_host_php'     => var_export($db['host'], true),
    'db_port_php'     => (int) $db['port'],
    'db_name_php'     => var_export($db['name'], true),
    'db_user_php'     => var_export($db['user'], true),
    'db_pass_php'     => var_export($db['pass'], true),
]);

// Sla site settings op in DB
try {
    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $settings = [
        ['core', 'site_name', $site['siteName']],
        ['core', 'site_url',  $site['siteUrl']],
        ['core', 'default_locale', $site['locale']],
        ['core', 'timezone', $site['timezone']],
        // Standaard-favicon: zonder deze rij toont een verse site een leeg
        // <link rel="icon" href="">, want site_icon werd voorheen alleen
        // gezet zodra een beheerder er zelf een uploadde via
        // /admin/settings. Het bijgeleverde logo staat sowieso al in
        // public/assets/img/, dus die meteen als startwaarde meegeven kost
        // niets en de beheerder kan 'm later gewoon overschrijven.
        ['core', 'site_icon', '/assets/img/favicon-32.png'],
    ];

    $stmt = $pdo->prepare("INSERT INTO cf_settings (`group`,`key`,`value`) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    foreach ($settings as $s) $stmt->execute($s);

    // Geselecteerde optionele modules activeren in cf_modules. Dit is de
    // tabel die Application::loadModules() elke request uitleest (WHERE
    // is_enabled = 1) — vóór deze fix keek Step5.php wél naar $_POST['modules']
    // maar deed er daarna helemaal niets mee: de selectie in de installer-UI
    // had geen enkel effect. Alleen de basisregistratie (naam/versie/auteur/
    // omschrijving uit module.json) — géén install() hier: de installer laadt
    // bewust geen Composer-autoloader / framework-klassen (zie InstallerCore.php),
    // dus module-specifieke extra tabellen (bv. cf_discord_role_mapping) worden
    // pas aangemaakt zodra een beheerder de module later in de Marketplace
    // opnieuw activeert — de module zelf degradeert intussen netjes (try/catch)
    // als die tabellen nog ontbreken.
    $moduleStmt = $pdo->prepare(
        "INSERT INTO cf_modules (slug, name, version, author, description, is_core, is_enabled)
         VALUES (?, ?, ?, ?, ?, 0, 1)
         ON DUPLICATE KEY UPDATE is_enabled = 1, version = VALUES(version)"
    );
    foreach ($selectedModules as $slug) {
        $manifest = $availableOptionalModules[$slug];
        $moduleStmt->execute([
            $slug,
            $manifest['name'] ?? $slug,
            $manifest['version'] ?? '1.0.0',
            $manifest['author'] ?? '',
            $manifest['description'] ?? '',
        ]);
    }

    // Hernoem installer map
    if (is_dir(CF_ROOT . '/installer') && !is_dir(CF_ROOT . '/installer.done')) {
        // Schrijf een lock-bestand i.p.v. hernoemen (veiliger)
        file_put_contents(CF_ROOT . '/installer/.installed', date('c'));
    }

} catch (PDOException $e) {
    return ['Fout bij opslaan instellingen: ' . $e->getMessage()];
}

session_destroy();
return true;
