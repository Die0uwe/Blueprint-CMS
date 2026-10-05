<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\Ollama;

use CommunityFusion\Core\Application;
use CommunityFusion\Core\Module\ModuleInterface;
use CommunityFusion\Core\Block\BlockRegistry;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Hook\HookManager;

final class OllamaModule implements ModuleInterface
{
    private Application $app;
    public function getSlug(): string { return 'ollama'; }

    public function boot(Application $app): void
    {
        $this->app = $app;
        $db        = $app->make(Connection::class);
        $cache     = $app->make(CacheManager::class);
        $registry  = $app->make(BlockRegistry::class);
        $hooks     = $app->make(HookManager::class);

        $cfg    = OllamaConfig::load($db);
        $client = OllamaConfig::client($cfg, $cache);
        unset($cfg['open_webui_key']);   // blokken hebben de sleutel niet nodig (en mogen hem niet kunnen lekken)

        // Registreer blocks
        $registry->register(new OllamaChatBlock($client, $cfg));
        $registry->register(new OllamaAssistantBlock($client, $cfg));

        // Routes
        $hooks->addAction('router.routes', function($router) {
            $auth = ['CommunityFusion\Api\Middleware\AuthMiddleware'];
            $rate = ['CommunityFusion\Api\Middleware\RateLimitMiddleware'];
            // KRITIEK, v1.25.9: /admin/ollama(/save) hing alleen aan $auth
            // (elke ingelogde gebruiker) i.p.v. een permissie — module.json
            // declareerde 'ollama.admin' al sinds het begin, maar niets
            // zaaide die ooit in cf_permissions (nu wél, zie schema.sql).
            // Zonder deze fix kon elk lid de Ollama-host/systeemprompt
            // overschrijven — een opstap naar SSRF via de chat-endpoint
            // hieronder. Zie CHANGELOG v1.25.9.
            $perm = fn(string $permission) => [...$auth, "CommunityFusion\\Api\\Middleware\\PermissionMiddleware:{$permission}"];
            // Publieke chat API (gebruikt door de chat block via AJAX, ook
            // door niet-ingelogde bezoekers — OllamaChatBlock::render() doet
            // geen auth-check, dus is bewust ook voor guests bedoeld) — stond
            // wél volledig ONBEPERKT open (geen rate limit): een onbeperkte
            // anonieme proxy naar de geconfigureerde Ollama-host, op
            // kosten/capaciteit van de sitebeheerder. $rate toegevoegd
            // (Gemiddeld-bevinding uit dezelfde Security-herscan) — geen
            // $auth, om het publieke-widget-gedrag niet te breken.
            $router->post('/api/ollama/chat',      'CommunityFusion\Modules\Ollama\OllamaApiController@chat',      $rate);
            $router->post('/api/ollama/summarize', 'CommunityFusion\Modules\Ollama\OllamaApiController@summarize', $rate);
            $router->get('/api/ollama/models',     'CommunityFusion\Modules\Ollama\OllamaApiController@models',    $rate);
            $router->get('/admin/ollama',          'CommunityFusion\Modules\Ollama\OllamaAdminController@index', $perm('ollama.admin'));
            $router->post('/admin/ollama/test',    'CommunityFusion\Modules\Ollama\OllamaAdminController@test',  $perm('ollama.admin'));
            $router->post('/admin/ollama/save',    'CommunityFusion\Modules\Ollama\OllamaAdminController@save',  $perm('ollama.admin'));
        });

        // Hook: analyseer guild aanmeldingen automatisch met AI
        $hooks->addAction('guild.application.created', function(array $app) use ($client, $db) {
            try {
                $analysis = $client->analyzeGuildApplication($app);
                if ($analysis) {
                    $db->execute(
                        "UPDATE cf_guild_applications SET review_note = CONCAT(COALESCE(review_note,''), '\n\n🤖 AI Analyse:\n', ?) WHERE id = ?",
                        [$analysis, $app['id']]
                    );
                }
            } catch (\Throwable) {}
        });

        // Sla de client op in de container voor gebruik door andere modules
        $app->getContainer()->instance(OllamaClient::class, $client);
    }

    public function install(): void {}
    public function uninstall(): void {}
    public function getBlocks(): array { return ['ollama-chat', 'ollama-assistant']; }
}
