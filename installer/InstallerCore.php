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

    /** Schrijf de uiteindelijke config/config.php */
    public static function writeConfig(array $data): void
    {
        $key       = self::generateAppKey();
        $jwtSecret = self::generateAppKey();
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
        'driver' => 'mail',
        'from'   => ['address' => {$data['mail_php']}, 'name' => {$data['site_name_php']}],
    ],
    'oauth' => [
        'discord' => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => ''],
        'twitch'  => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => ''],
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
        // Splits op statements en voer elk uit
        $statements = array_filter(
            array_map('trim', explode(';', $schema)),
            fn($s) => strlen($s) > 10
        );
        foreach ($statements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                // Negeer "table already exists" fouten
                if ($e->getCode() !== '42S01') throw $e;
            }
        }
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: InstallerCore.php | Role: Core | Version: 1.0.0              ║
// ║  Created: 2026-06-06 | Status: New                                   ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ        ║
// ╚══════════════════════════════════════════════════════════════════════╝
