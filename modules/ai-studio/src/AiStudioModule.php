<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio;

use CommunityFusion\Core\Application;
use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Container;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Hook\HookManager;
use CommunityFusion\Core\I18n\Translator;
use CommunityFusion\Core\Module\ModuleInterface;
use CommunityFusion\Core\Router;
use CommunityFusion\Modules\AiStudio\Http\CurlTransport;
use CommunityFusion\Modules\AiStudio\Http\HttpTransportInterface;
use CommunityFusion\Modules\AiStudio\Http\SsrfGuard;

final class AiStudioModule implements ModuleInterface
{
    public const DIR = __DIR__ . '/..';

    private ?Application $app = null;

    public function getSlug(): string
    {
        return 'ai-studio';
    }

    public function boot(Application $app): void
    {
        $this->app = $app;
        $container = $app->getContainer();
        $this->bind($container);

        $container->make(HookManager::class)->addAction('router.routes', function (Router $router): void {
            /** @var list<array{0: string, 1: string, 2: string, 3: list<string>}> $routes */
            $routes = require self::DIR . '/routes.php';
            foreach ($routes as [$method, $pattern, $handler, $middleware]) {
                if ($method === 'POST') {
                    $router->post($pattern, $handler, $middleware);
                } else {
                    $router->get($pattern, $handler, $middleware);
                }
            }
        });
    }

    /**
     * Services van de module. Expliciet gebonden omdat Container::autoResolve
     * interfaces en optionele array-parameters niet kan invullen.
     */
    private function bind(Container $c): void
    {
        $c->singleton(HttpTransportInterface::class, static fn (): HttpTransportInterface => new CurlTransport());
        $c->singleton(SsrfGuard::class, static fn (): SsrfGuard => new SsrfGuard());
        $c->singleton(SettingsStore::class, static fn (Container $c): SettingsStore => new SettingsStore($c->make(Connection::class)));
        $c->singleton(StudioAuth::class, static fn (Container $c): StudioAuth => new AuthManagerAdapter($c->make(AuthManager::class)));
        $c->singleton(UserRateLimiter::class, static fn (Container $c): UserRateLimiter => new UserRateLimiter($c->make(CacheManager::class)));
        $c->singleton(ProviderRegistry::class, static fn (Container $c): ProviderRegistry => new ProviderRegistry(
            $c->make(SettingsStore::class),
            $c->make(HttpTransportInterface::class),
            $c->make(SsrfGuard::class),
        ));
        $c->singleton(SchemaMigrator::class, static fn (Container $c): SchemaMigrator => new SchemaMigrator(
            $c->make(Connection::class)->getPdo(),
            $c->make(SettingsStore::class),
            self::DIR . '/migrations',
        ));
        $c->singleton(ChatController::class, static function (Container $c): ChatController {
            $locale = $c->make(Translator::class)->locale();
            return new ChatController(
                $c->make(StudioAuth::class),
                $c->make(ProviderRegistry::class),
                $c->make(ConversationRepository::class),
                $c->make(MessageRepository::class),
                new PromptBuilder(),
                new DiffService(),
                $c->make(AuditLogger::class),
                $c->make(UserRateLimiter::class),
                $c->make(SettingsStore::class),
                ['locale' => $locale, 'timezone' => date_default_timezone_get()],
            );
        });
        $c->singleton(AiStudioController::class, static fn (Container $c): AiStudioController => new AiStudioController(
            $c->make(StudioAuth::class),
            $c->make(ProviderRegistry::class),
            $c->make(ConversationRepository::class),
            $c->make(MessageRepository::class),
            $c->make(SettingsStore::class),
            $c->make(SchemaMigrator::class),
            $c->make(AuditLogger::class),
            $c->make(UserRateLimiter::class),
            self::DIR,
        ));
    }

    public function install(): void
    {
        // Wordt door PackageManager aangeroepen na boot(): schema aanmaken.
        $app = $this->app ?? Application::getInstance();
        $app->getContainer()->make(SchemaMigrator::class)->run();
    }

    public function uninstall(): void
    {
        // Bewust geen DROP TABLE: gesprekken van gebruikers verwijder je niet
        // stilletjes bij het uitschakelen van een module. API-keys blijven
        // versleuteld in cf_settings (groep "aistudio"); verwijderen kan op
        // het instellingenscherm.
    }

    /**
     * @return list<string>
     */
    public function getBlocks(): array
    {
        return [];
    }
}
