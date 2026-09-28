<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
//
// This work is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This work is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Hook\HookManager;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Template\ThemeManager;

/**
 * Community Fusion CMS — Application Bootstrap
 *
 * Centrale klasse die alle services initialiseert en de request afhandelt.
 */
final class Application
{
    private static ?self $instance = null;
    private Container $container;
    private HookManager $hooks;
    private bool $booted = false;

    private function __construct()
    {
        $this->container = new Container();
        $this->hooks     = new HookManager();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Bootstrap de applicatie en verwerk de HTTP request.
     */
    public function run(): void
    {
        $this->boot();
        $this->handleRequest();
    }

    /**
     * Registreer alle core services in de DI container.
     *
     * Was private (Wave 2 gap-fix). `run()` roept dit intern aan voor een
     * normale HTTP-request, maar de bestaande CLI-commando's (o.a.
     * QueueWorkerCommand, al sinds Sprint 1) bootstrappen zo: `$app = require
     * .../Application.php; $app->make(Connection::class);` — zonder ooit
     * boot() aan te roepen. Omdat boot() private was, registreerde niets de
     * Connection-singleton, en Container::autoResolve() kan Connection niet
     * automatisch bouwen (de constructor neemt `array $config`, een builtin
     * type zonder default — dat gooit altijd "Kan parameter niet resolven").
     * Elke bestaande en nieuwe CLI-command die de container gebruikt roept nu
     * expliciet `$app->boot()` aan vóór de eerste `$app->make(...)`.
     */
    public function boot(): void
    {
        if ($this->booted) return;

        // Laad config
        $config = require CF_ROOT . '/config/config.php';
        $this->container->singleton('config', fn() => $config);

        // config/config.php is de bron van waarheid voor APP_KEY en JWT_SECRET
        // (door de installer gegenereerd als twee losse, willekeurige sleutels).
        // We synchroniseren ze naar $_ENV zodat code die nog rechtstreeks
        // $_ENV leest (bv. OAuthClient::getAppKey()) altijd de echte,
        // gegenereerde waarde ziet — nooit de lege default uit .env.example.
        $_ENV['APP_KEY']    = $config['app']['key'] ?? ($_ENV['APP_KEY'] ?? '');
        $_ENV['JWT_SECRET'] = $config['jwt']['secret'] ?? ($_ENV['JWT_SECRET'] ?? '');

        // Database
        $this->container->singleton(Connection::class, function() use ($config) {
            return new Connection($config['database']);
        });

        // JWT — eigen sleutel, los van app.key (zie config/config.php commentaar)
        $this->container->singleton(\CommunityFusion\Core\Auth\JWTManager::class, function() use ($config) {
            $secret = $config['jwt']['secret'] ?? $config['app']['key'] ?? '';
            if ($secret === '') {
                throw new \RuntimeException(
                    'jwt.secret ontbreekt in config/config.php — installer opnieuw draaien of handmatig aanvullen.'
                );
            }
            return new \CommunityFusion\Core\Auth\JWTManager(
                secret: $secret,
                ttl: (int) ($config['jwt']['ttl'] ?? 3600),
            );
        });

        // AuthManager expliciet als singleton (bewaart de ingelogde gebruiker
        // voor de duur van de request — mag nooit meerdere keren aangemaakt
        // worden). Zonder deze binding faalt de DI auto-resolve op
        // JWTManager's scalar $secret-parameter.
        $this->container->singleton(\CommunityFusion\Core\Auth\AuthManager::class, function() {
            return new \CommunityFusion\Core\Auth\AuthManager(
                $this->container->make(Connection::class),
                $this->container->make(\CommunityFusion\Core\Auth\RBAC\RBACManager::class),
                $this->container->make(\CommunityFusion\Core\Auth\JWTManager::class),
                $this->container->make(\CommunityFusion\Core\Audit\AuditLogger::class),
            );
        });

        // Hook systeem
        $this->container->singleton(HookManager::class, fn() => $this->hooks);

        // Audit-log (Wave 5) — vóór AuthManager geregistreerd, want die
        // heeft 'm nodig voor auth.login/auth.login_failed.
        $this->container->singleton(\CommunityFusion\Core\Audit\AuditLogger::class, function() {
            return new \CommunityFusion\Core\Audit\AuditLogger($this->container->make(Connection::class));
        });

        // Cache
        $this->container->singleton(CacheManager::class, function() use ($config) {
            return new CacheManager($config['cache']);
        });

        // Template engine — het actieve thema komt normaal uit
        // config/config.php['app']['theme'] (installer-default 'default'),
        // maar /admin/themes (Wave 5) moet zonder installer opnieuw te
        // draaien kunnen wisselen. cf_settings('core','active_theme') is
        // dus de nieuwe bron van waarheid ZODRA een admin ooit via dat
        // scherm heeft gewisseld; tot dan valt dit terug op config.php
        // (en vóór installatie bestaat cf_settings nog niet — vandaar de
        // try/catch, zelfde patroon als de andere pre-install-gevoelige
        // stukken hieronder).
        $this->container->singleton(ThemeManager::class, function() use ($config) {
            $theme = $config['app']['theme'] ?? 'default';
            try {
                $settingsRepo = $this->container->make(\CommunityFusion\Modules\Settings\SettingsRepository::class);
                $dbTheme = $settingsRepo->get('core', 'active_theme');
                if (is_string($dbTheme) && $dbTheme !== '') {
                    $theme = $dbTheme;
                }
            } catch (\Throwable) {}
            return new ThemeManager(CF_ROOT . '/themes', $theme);
        });

        // Uploads — schrijft altijd buiten webroot naar storage/uploads/
        $this->container->singleton(\CommunityFusion\Core\Storage\UploadManager::class, function() use ($config) {
            return new \CommunityFusion\Core\Storage\UploadManager(
                storagePath: $config['storage']['path'] ?? (CF_ROOT . '/storage/uploads'),
                maxBytes: (int) ($config['storage']['max_bytes'] ?? 5 * 1024 * 1024),
            );
        });

        // Mailer — config/config.php['mail'] bestond al sinds Sprint 1 (installer
        // schrijft er 'driver'+'from' in), maar er was geen enkele klasse die
        // hem daadwerkelijk gebruikte. 'driver' => 'smtp' + host/port/etc. is
        // (nog) geen installer-UI-veld — die haal je uit .env (MAIL_HOST e.a.,
        // zie .env.example), zodat je zonder installer-wijziging toch SMTP kan
        // inschakelen. Bij 'driver' => 'mail' (de installer-default) blijft
        // host altijd leeg, ongeacht wat er in .env staat, zodat Mailer bewust
        // op PHP's ingebouwde mail() terugvalt — zie Mailer::send().
        $this->container->singleton(\CommunityFusion\Core\Mail\Mailer::class, function() use ($config) {
            $mail   = $config['mail'] ?? [];
            $driver = $mail['driver'] ?? 'mail';
            return new \CommunityFusion\Core\Mail\Mailer(
                host:        $driver === 'smtp' ? (string) ($mail['host'] ?? ($_ENV['MAIL_HOST'] ?? '')) : '',
                port:        (int) ($mail['port'] ?? ($_ENV['MAIL_PORT'] ?? 587)),
                username:    (string) ($mail['username'] ?? ($_ENV['MAIL_USER'] ?? '')),
                password:    (string) ($mail['password'] ?? ($_ENV['MAIL_PASS'] ?? '')),
                fromAddress: (string) ($mail['from']['address'] ?? ($_ENV['MAIL_FROM'] ?? 'noreply@localhost')),
                fromName:    (string) ($mail['from']['name'] ?? ($_ENV['MAIL_FROM_NAME'] ?? '')),
                encryption:  (string) ($mail['encryption'] ?? ($_ENV['MAIL_ENCRYPTION'] ?? 'tls')),
            );
        });

        // Block Registry
        $this->container->singleton(\CommunityFusion\Core\Block\BlockRegistry::class, function() {
            return new \CommunityFusion\Core\Block\BlockRegistry(
                $this->container->make(\CommunityFusion\Core\Database\Connection::class),
                $this->container->make(\CommunityFusion\Core\Cache\CacheManager::class),
            );
        });

        // Registreer core block types
        $this->container->make(\CommunityFusion\Core\Block\BlockRegistry::class)->register(
            new \CommunityFusion\Blocks\Types\TextBlock()
        );
        $this->container->make(\CommunityFusion\Core\Block\BlockRegistry::class)->register(
            new \CommunityFusion\Blocks\Types\HtmlBlock()
        );
        $this->container->make(\CommunityFusion\Core\Block\BlockRegistry::class)->register(
            new \CommunityFusion\Blocks\Types\NewsBlock(
                $this->container->make(\CommunityFusion\Core\Database\Connection::class)
            )
        );
        $this->container->make(\CommunityFusion\Core\Block\BlockRegistry::class)->register(
            new \CommunityFusion\Blocks\Types\LoginBlock()
        );
        $this->container->make(\CommunityFusion\Core\Block\BlockRegistry::class)->register(
            new \CommunityFusion\Blocks\Types\StatsBlock(
                $this->container->make(\CommunityFusion\Core\Database\Connection::class)
            )
        );
        $this->container->make(\CommunityFusion\Core\Block\BlockRegistry::class)->register(
            new \CommunityFusion\Blocks\Types\AdBlock()
        );

        // Laad geregistreerde modules
        $this->loadModules();

        // PHP instellingen
        $this->configureRuntime($config);

        // Wire ThemeManager met BlockRegistry voor render_block() in Twig
        try {
            $theme    = $this->container->make(\CommunityFusion\Core\Template\ThemeManager::class);
            $registry = $this->container->make(\CommunityFusion\Core\Block\BlockRegistry::class);
            $theme->setBlockRegistry($registry);
        } catch (\Throwable) {}

        // `auth`, `settings` en `menu_pages` globaal beschikbaar maken in Twig —
        // layout.twig (elke pagina extends deze) leest ze al sinds Sprint 3,
        // maar zonder deze injectie waren ze overal undefined/leeg (zie
        // ThemeManager::addGlobal() docblock). Faalt stil vóór installatie,
        // wanneer cf_settings/cf_pages nog niet bestaan.
        try {
            $theme = $this->container->make(\CommunityFusion\Core\Template\ThemeManager::class);
            $theme->addGlobal('auth', $this->container->make(\CommunityFusion\Core\Auth\AuthManager::class));

            $settingsRepo = $this->container->make(\CommunityFusion\Modules\Settings\SettingsRepository::class);
            $theme->addGlobal('settings', $settingsRepo->getGroup('core'));

            $pageRepo = $this->container->make(\CommunityFusion\Modules\Pages\PageRepository::class);
            $theme->addGlobal('menu_pages', $pageRepo->getMenuPages());
        } catch (\Throwable) {}

        $this->booted = true;
        $this->hooks->doAction('app.booted', $this);
    }

    /**
     * Verwerk de inkomende HTTP request via de Router.
     */
    private function handleRequest(): void
    {
        $router   = new Router($this->container, $this->hooks);
        $request  = Request::fromGlobals();

        $this->hooks->doAction('request.before', $request);

        try {
            $response = $router->dispatch($request);
        } catch (\Throwable $e) {
            $response = $this->handleException($e);
        }

        $this->hooks->doAction('response.before', $response);
        $response->send();
    }

    /**
     * Laad alle ingeschakelde modules uit de database.
     */
    private function loadModules(): void
    {
        try {
            $db      = $this->container->make(Connection::class);
            $modules = $db->fetchAll(
                "SELECT slug, config FROM cf_modules WHERE is_enabled = 1 ORDER BY is_core DESC"
            );

            foreach ($modules as $row) {
                $this->loadModule($row['slug'], json_decode($row['config'] ?? '{}', true) ?? []);
            }
        } catch (\Throwable) {
            // DB nog niet beschikbaar (installatiefase) — negeren
        }
    }

    private function loadModule(string $slug, array $config): void
    {
        $manifestPath = CF_ROOT . "/modules/{$slug}/module.json";
        if (!file_exists($manifestPath)) return;

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $class    = $manifest['class'] ?? null;

        if ($class === null || !class_exists($class)) return;

        /** @var \CommunityFusion\Core\Module\ModuleInterface $module */
        $module = new $class($this);
        $module->boot($this);

        $this->container->instance("module.{$slug}", $module);
    }

    private function configureRuntime(array $config): void
    {
        $tz = $config['app']['timezone'] ?? 'UTC';
        date_default_timezone_set($tz);

        if (($config['app']['debug'] ?? false) === true) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(0);
            ini_set('display_errors', '0');
        }
    }

    private function handleException(\Throwable $e): Response
    {
        $code    = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $message = $_ENV['APP_DEBUG'] === 'true'
            ? $e->getMessage() . "\n" . $e->getTraceAsString()
            : 'Er is een fout opgetreden. Probeer het later opnieuw.';

        return new Response($message, $code, ['Content-Type' => 'text/plain']);
    }

    public function make(string $abstract): mixed
    {
        return $this->container->make($abstract);
    }

    public function getHooks(): HookManager
    {
        return $this->hooks;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}

// Creëer en return de applicatie instantie
return Application::getInstance();

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : Application.php                                      ║
// ║  Role         : Core                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-06-06                                           ║
// ║  Last Updated : 2026-06-06  03:00                                    ║
// ║  Status       : New                                                  ║
// ║  Notes        : Bootstrap + DI setup, module loader                  ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
