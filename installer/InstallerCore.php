<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later — see LICENSE for details
// ============================================================================

declare(strict_types=1);

/**
 * Installer Core
 * Beheert de installatiestatus, stap-navigatie en sessie.
 */
final class InstallerCore
{
    public const STEPS = [
        1 => ['id' => 'server',   'label' => 'Server Check',     'icon' => '🔍'],
        2 => ['id' => 'database', 'label' => 'Database',         'icon' => '🗄️'],
        3 => ['id' => 'site',     'label' => 'Site Instellingen','icon' => '🌐'],
        4 => ['id' => 'admin',    'label' => 'Admin Account',    'icon' => '👤'],
        5 => ['id' => 'modules',  'label' => 'Modules & Finish', 'icon' => '🧩'],
    ];

    public static function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['installer'])) {
            $_SESSION['installer'] = ['step' => 1, 'data' => []];
        }
    }

    public static function getCurrentStep(): int
    {
        return (int) ($_SESSION['installer']['step'] ?? 1);
    }

    public static function setStep(int $step): void
    {
        $_SESSION['installer']['step'] = max(1, min(5, $step));
    }

    public static function saveData(string $key, mixed $value): void
    {
        $_SESSION['installer']['data'][$key] = $value;
    }

    public static function getData(string $key, mixed $default = null): mixed
    {
        return $_SESSION['installer']['data'][$key] ?? $default;
    }

    public static function isCompleted(): bool
    {
        return file_exists(dirname(__DIR__) . '/config/config.php');
    }

    public static function generateAppKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /**
     * Lees één waarde uit .env zonder Dotenv/Composer — de installer laadt
     * bewust geen framework-klassen (zie de rest van dit bestand), maar
     * KEY=VALUE-regels parsen is triviaal en dependency-vrij. Gebruikt om
     * MAIL_HOST e.a. over te nemen als iemand vóór installatie al een echt
     * .env-bestand met SMTP-gegevens heeft klaargezet (zie .env.example).
     * Geeft '' terug als .env ontbreekt of de key niet gezet is.
     */
    private static function readEnvValue(string $key): string
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            $path  = dirname(__DIR__) . '/.env';
            if (is_file($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                    [$k, $v] = array_map('trim', explode('=', $line, 2));
                    $cache[$k] = trim($v, "\"'");
                }
            }
        }
        return $cache[$key] ?? '';
    }

    /** Schrijf de uiteindelijke config/config.php */
    public static function writeConfig(array $data): void
    {
        $key       = self::generateAppKey();
        $jwtSecret = self::generateAppKey();

        // SMTP is (nog) geen installer-UI-veld (zie Step3) — wordt overgenomen
        // uit .env als dat vóór installatie al is ingevuld, anders blijft
        // driver 'mail' (PHP's ingebouwde mail()), precies zoals voorheen.
        $mailHost = self::readEnvValue('MAIL_HOST');
        $mailDriver     = $mailHost !== '' ? 'smtp' : 'mail';
        $mailPort       = self::readEnvValue('MAIL_PORT') ?: '587';
        $mailUser       = self::readEnvValue('MAIL_USER');
        $mailPass       = self::readEnvValue('MAIL_PASS');
        $mailEncryption = self::readEnvValue('MAIL_ENCRYPTION') ?: 'tls';
        $mailDriverPhp     = var_export($mailDriver, true);
        $mailHostPhp       = var_export($mailHost, true);
        $mailPortPhp       = var_export((int) $mailPort, true);
        $mailUserPhp       = var_export($mailUser, true);
        $mailPassPhp       = var_export($mailPass, true);
        $mailEncryptionPhp = var_export($mailEncryption, true);

        $config = <<<PHP
<?php
// ============================================================================
// Blueprint CMS — Gegenereerd door installer op {$data['date']}
// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later
// NOOIT handmatig aanpassen zonder backup.
// ============================================================================

declare(strict_types=1);

return [
    'app' => [
        'name'     => {$data['site_name_php']},
        'url'      => {$data['site_url_php']},
        'version'  => '1.0.0',
        'env'      => 'production',
        'debug'    => false,
        'timezone' => {$data['timezone_php']},
        'locale'   => {$data['locale_php']},
        'theme'    => 'default',
        'key'      => '{$key}',
    ],
    // Losse sleutel voor JWT (HS256) — bewust NIET dezelfde als 'app.key',
    // die gebruikt wordt voor AES-256-GCM OAuth-tokenversleuteling.
    // Sleutelscheiding: een lek in de ene context is niet bruikbaar in de andere.
    'jwt' => [
        'secret' => '{$jwtSecret}',
        'ttl'    => 3600,
    ],
    'database' => [
        'driver'    => 'mysql',
        'host'      => {$data['db_host_php']},
        'port'      => {$data['db_port_php']},
        'name'      => {$data['db_name_php']},
        'user'      => {$data['db_user_php']},
        'password'  => {$data['db_pass_php']},
        'prefix'    => 'cf_',
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ],
    'cache' => [
        'driver' => 'file',
        'path'   => __DIR__ . '/../storage/cache',
        'ttl'    => 3600,
    ],
    'session' => [
        'lifetime' => 7200,
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ],
    'mail' => [
        'driver'     => {$mailDriverPhp},
        'host'       => {$mailHostPhp},
        'port'       => {$mailPortPhp},
        'username'   => {$mailUserPhp},
        'password'   => {$mailPassPhp},
        'encryption' => {$mailEncryptionPhp},
        'from'       => ['address' => {$data['mail_php']}, 'name' => {$data['site_name_php']}],
    ],
    // Golf 10: dit 'oauth'-blok is NIET de bron van waarheid — geen enkele
    // OAuthController leest ooit uit config.php. De echte instellingen staan
    // in cf_settings (group = providerslug) en worden ingevuld via
    // /admin/marketplace/package/{slug}/instellingen (zie
    // ModuleSettingsController). Dit blok blijft staan als document van welke
    // providers ondersteund worden, niet als functionele configuratie.
    'oauth' => [
        'github'    => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => ''],
        'discord'   => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => ''],
        'twitch'    => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => ''],
        'google'    => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => ''],
        'battlenet' => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => '', 'region' => 'eu'],
    ],
    'storage' => [
        // Buiten webroot — zie UploadManager. NIET public/uploads/.
        'path'      => __DIR__ . '/../storage/uploads',
        'max_bytes' => 5 * 1024 * 1024,
    ],
];
PHP;
        file_put_contents(dirname(__DIR__) . '/config/config.php', $config);
    }

    /** Importeer het database schema */
    public static function importSchema(\PDO $pdo): void
    {
        $schema = file_get_contents(dirname(__DIR__) . '/src/Core/Database/schema.sql');

        // Splits op statements en voer elk uit. `-- commentaar`-regels worden
        // EERST verwijderd — zonder dit brak elke `;` binnen zo'n regel de
        // hele import in tweeën met een syntaxfout (bv. een JSON-voorbeeld of
        // gewone tekst met een puntkomma erin, zie CHANGELOG v1.15.0). Een
        // regel-comment strippen mag hier zonder een echte SQL-parser: dit
        // schema-bestand gebruikt nergens een letterlijke `--` binnen een
        // stringwaarde (de enige tekstvelden zijn Nederlandse omschrijvingen
        // zonder dat teken), dus een simpele regex per regel is voldoende.
        $schema = preg_replace('/^\s*--.*$/m', '', $schema);

        $statements = array_filter(
            array_map('trim', explode(';', $schema)),
            fn($s) => strlen($s) > 10
        );
        foreach ($statements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                // Negeer "table already exists" (42S01) én "duplicate entry"
                // bij het opnieuw inserten van de seed-rijen (MySQL-errorcode
                // 1062, SQLSTATE 23000) — beide horen bij een idempotente
                // her-import (bv. `php cli/console.php migrate` een tweede
                // keer draaien, Wave 2), niet bij een echte fout. Wave 2
                // ontdekte dit gat: alleen 42S01 negeren volstond voor de
                // installer zelf (die maar één keer draait), maar migrate
                // faalde op de tweede run met "Duplicate entry 'super_admin'
                // for key 'uq_name'" — de CREATE TABLE-statements werden wel
                // overgeslagen, de RBAC/settings-seed-INSERT's niet. SQLSTATE
                // 23000 dekt ook FK-violations; daarom expliciet op de
                // MySQL-errorcode (1062) checken i.p.v. alleen de bredere
                // SQLSTATE-klasse, zodat een échte integriteitsfout elders
                // niet per ongeluk stil wordt geslikt.
                $mysqlErrorCode = $e->errorInfo[1] ?? null;
                if ($e->getCode() !== '42S01' && $mysqlErrorCode !== 1062) {
                    throw $e;
                }
            }
        }
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: InstallerCore.php | Role: Core | Version: 1.0.0              ║
// ║  Created: 2026-06-06 | Status: New                                   ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ        ║
// ╚══════════════════════════════════════════════════════════════════════╝
