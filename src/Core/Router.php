<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Core;

use CommunityFusion\Core\Hook\HookManager;

final class Router
{
    private array $routes = [];

    public function __construct(
        private readonly Container   $container,
        private readonly HookManager $hooks,
    ) {
        $this->registerCoreRoutes();
    }

    public function get(string $pattern, callable|string $handler, array $middleware = []): void
    { $this->addRoute('GET', $pattern, $handler, $middleware); }
    public function post(string $pattern, callable|string $handler, array $middleware = []): void
    { $this->addRoute('POST', $pattern, $handler, $middleware); }
    public function put(string $pattern, callable|string $handler, array $middleware = []): void
    { $this->addRoute('PUT', $pattern, $handler, $middleware); }
    public function patch(string $pattern, callable|string $handler, array $middleware = []): void
    { $this->addRoute('PATCH', $pattern, $handler, $middleware); }
    public function delete(string $pattern, callable|string $handler, array $middleware = []): void
    { $this->addRoute('DELETE', $pattern, $handler, $middleware); }

    private function addRoute(string $method, string $pattern, callable|string $handler, array $middleware): void
    {
        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $this->compilePattern($pattern),
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $path   = $request->getPath();

        $this->hooks->doAction('router.routes', $this);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) continue;
            if (preg_match($route['pattern'], $path, $matches)) {
                $request->setParams(array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));
                $handler  = $this->resolveHandler($route['handler']);
                $pipeline = $this->buildPipeline($route['middleware'], $handler);
                return $pipeline($request);
            }
        }

        return new Response('404 — Pagina niet gevonden.', 404);
    }

    private function compilePattern(string $pattern): string
    {
        $regex = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}/',
            fn($m) => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $pattern
        );
        return '#^' . $regex . '$#';
    }

    private function resolveHandler(callable|string $handler): callable
    {
        if (is_callable($handler)) return $handler;
        [$class, $method] = explode('@', $handler, 2);
        return [$this->container->make($class), $method];
    }

    private function buildPipeline(array $middlewareClasses, callable $handler): callable
    {
        $pipeline = $handler;
        foreach (array_reverse($middlewareClasses) as $class) {
            $middleware = $this->container->make($class);
            $next       = $pipeline;
            $pipeline   = fn(Request $req) => $middleware->handle($req, $next);
        }
        return $pipeline;
    }

    private function registerCoreRoutes(): void
    {
        $auth  = ['CommunityFusion\Api\Middleware\AuthMiddleware'];
        $rate  = ['CommunityFusion\Api\Middleware\RateLimitMiddleware'];
        $cors  = ['CommunityFusion\Api\Middleware\CorsMiddleware'];

        // ── Publiek ─────────────────────────────────────────────────────
        $this->get('/',                        'CommunityFusion\Modules\Pages\PageController@home');
        $this->get('/news',                    'CommunityFusion\Modules\News\NewsController@index');
        $this->get('/news/{slug:[a-z0-9-]+}',  'CommunityFusion\Modules\News\NewsController@show');
        $this->get('/page/{slug:[a-z0-9-/]+}', 'CommunityFusion\Modules\Pages\PageController@show');

        // ── Forum ───────────────────────────────────────────────────────
        // Let op volgorde: de letterlijke /nieuw-route moet vóór de generieke
        // {topic}-route staan, anders matcht "nieuw" als topic-slug.
        $this->get('/forum',                                       'CommunityFusion\Modules\Forum\ForumController@index');
        $this->get('/forum/{board:[a-z0-9-]+}',                    'CommunityFusion\Modules\Forum\ForumController@board');
        $this->get('/forum/{board:[a-z0-9-]+}/nieuw',               'CommunityFusion\Modules\Forum\ForumController@newTopicForm', $auth);
        $this->post('/forum/{board:[a-z0-9-]+}/nieuw',              'CommunityFusion\Modules\Forum\ForumController@storeTopic',   $auth);
        $this->get('/forum/{board:[a-z0-9-]+}/{topic:[a-z0-9-]+}',              'CommunityFusion\Modules\Forum\ForumController@topic');
        $this->post('/forum/{board:[a-z0-9-]+}/{topic:[a-z0-9-]+}/reageer',     'CommunityFusion\Modules\Forum\ForumController@storePost',  $auth);
        $this->post('/forum/{board:[a-z0-9-]+}/{topic:[a-z0-9-]+}/pin',         'CommunityFusion\Modules\Forum\ForumController@togglePin',  $auth);
        $this->post('/forum/{board:[a-z0-9-]+}/{topic:[a-z0-9-]+}/lock',        'CommunityFusion\Modules\Forum\ForumController@toggleLock', $auth);
        $this->post('/forum/{board:[a-z0-9-]+}/{topic:[a-z0-9-]+}/verwijder',   'CommunityFusion\Modules\Forum\ForumController@deleteTopic', $auth);

        // ── Blog ────────────────────────────────────────────────────────
        // Elk lid heeft z'n eigen blog: /blog/{username}/{slug}. Letterlijke
        // suffix-routes (/nieuw, /bewerk, /verwijder) staan vóór de generieke
        // {slug}-route om te voorkomen dat ze als slug worden gelezen.
        $this->get('/blog',                                        'CommunityFusion\Modules\Blog\BlogController@index');
        $this->get('/blog/{username:[a-zA-Z0-9_.-]+}',              'CommunityFusion\Modules\Blog\BlogController@author');
        $this->get('/blog/{username:[a-zA-Z0-9_.-]+}/nieuw',        'CommunityFusion\Modules\Blog\BlogController@createForm', $auth);
        $this->post('/blog/{username:[a-zA-Z0-9_.-]+}/nieuw',       'CommunityFusion\Modules\Blog\BlogController@store',      $auth);
        $this->get('/blog/{username:[a-zA-Z0-9_.-]+}/{slug:[a-z0-9-]+}/bewerk',      'CommunityFusion\Modules\Blog\BlogController@editForm', $auth);
        $this->post('/blog/{username:[a-zA-Z0-9_.-]+}/{slug:[a-z0-9-]+}/bewerk',     'CommunityFusion\Modules\Blog\BlogController@update',   $auth);
        $this->post('/blog/{username:[a-zA-Z0-9_.-]+}/{slug:[a-z0-9-]+}/verwijder',  'CommunityFusion\Modules\Blog\BlogController@delete',   $auth);
        $this->get('/blog/{username:[a-zA-Z0-9_.-]+}/{slug:[a-z0-9-]+}',             'CommunityFusion\Modules\Blog\BlogController@show');

        // ── Downloads ───────────────────────────────────────────────────
        $this->get('/downloads',                              'CommunityFusion\Modules\Downloads\DownloadsController@index');
        $this->get('/downloads/nieuw',                         'CommunityFusion\Modules\Downloads\DownloadsController@createForm', $auth);
        $this->post('/downloads/nieuw',                        'CommunityFusion\Modules\Downloads\DownloadsController@store',      $auth);
        $this->get('/downloads/{slug:[a-z0-9-]+}/bewerk',      'CommunityFusion\Modules\Downloads\DownloadsController@editForm', $auth);
        $this->post('/downloads/{slug:[a-z0-9-]+}/bewerk',     'CommunityFusion\Modules\Downloads\DownloadsController@update',   $auth);
        $this->post('/downloads/{slug:[a-z0-9-]+}/verwijder',  'CommunityFusion\Modules\Downloads\DownloadsController@delete',   $auth);
        $this->get('/downloads/{slug:[a-z0-9-]+}/bestand',     'CommunityFusion\Modules\Downloads\DownloadsController@download');
        $this->get('/downloads/{slug:[a-z0-9-]+}',             'CommunityFusion\Modules\Downloads\DownloadsController@show');

        // ── Contact ─────────────────────────────────────────────────────
        $this->get('/contact',   'CommunityFusion\Modules\Contact\ContactController@form');
        $this->post('/contact',  'CommunityFusion\Modules\Contact\ContactController@store');
        $this->get('/admin/contact',                        'CommunityFusion\Modules\Contact\ContactController@inbox',  $auth);
        $this->get('/admin/contact/{id:[0-9]+}',            'CommunityFusion\Modules\Contact\ContactController@show',   $auth);
        $this->post('/admin/contact/{id:[0-9]+}/verwijder', 'CommunityFusion\Modules\Contact\ContactController@delete', $auth);

        // ── Auth ────────────────────────────────────────────────────────
        $this->get('/login',     'CommunityFusion\Modules\Users\AuthController@loginForm');
        $this->post('/login',    'CommunityFusion\Modules\Users\AuthController@login');
        $this->get('/logout',    'CommunityFusion\Modules\Users\AuthController@logout');
        $this->get('/register',  'CommunityFusion\Modules\Users\AuthController@registerForm');
        $this->post('/register', 'CommunityFusion\Modules\Users\AuthController@register');
        $this->get('/profiel',          'CommunityFusion\Modules\Users\ProfileController@show',         $auth);
        $this->post('/profiel/avatar',  'CommunityFusion\Modules\Users\ProfileController@updateAvatar',  $auth);

        // ── Media (uploads, buiten webroot — zie UploadManager) ─────────
        $this->get('/media/{path:[a-zA-Z0-9/_.-]+}', 'CommunityFusion\Modules\Media\MediaController@show');

        // ── Admin ────────────────────────────────────────────────────────
        $this->get('/admin',          'CommunityFusion\Modules\Settings\AdminController@dashboard', $auth);
        $this->get('/admin/settings', 'CommunityFusion\Modules\Settings\AdminController@settings',  $auth);

        // Blokken admin
        $this->get('/admin/blocks',                     'CommunityFusion\Modules\Blocks\BlockController@index',  $auth);
        $this->get('/admin/blocks/create',              'CommunityFusion\Modules\Blocks\BlockController@create', $auth);
        $this->post('/admin/blocks/store',              'CommunityFusion\Modules\Blocks\BlockController@store',  $auth);
        $this->post('/admin/blocks/{id:[0-9]+}/update', 'CommunityFusion\Modules\Blocks\BlockController@update', $auth);
        $this->post('/admin/blocks/{id:[0-9]+}/delete', 'CommunityFusion\Modules\Blocks\BlockController@delete', $auth);

        // ── REST API v1 ─────────────────────────────────────────────────
        $this->get('/api/v1/status',               'CommunityFusion\Api\V1\StatusController@index',           $cors);
        $this->post('/api/v1/auth/login',          'CommunityFusion\Api\V1\AuthController@login',             $cors);
        $this->get('/api/v1/auth/me',              'CommunityFusion\Api\V1\AuthController@me',     [...$cors, ...$auth]);
        $this->get('/api/v1/users',                'CommunityFusion\Api\V1\UsersController@index',  [...$cors, ...$auth, ...$rate]);
        $this->get('/api/v1/users/{id:[0-9]+}',    'CommunityFusion\Api\V1\UsersController@show',             $cors);
        $this->get('/api/v1/news',                 'CommunityFusion\Api\V1\ContentController@news',           [...$cors, ...$rate]);
        $this->get('/api/v1/news/{slug:[a-z0-9-]+}', 'CommunityFusion\Api\V1\ContentController@newsItem',    $cors);
        $this->get('/api/v1/pages',                'CommunityFusion\Api\V1\ContentController@pages',          $cors);
        $this->get('/api/v1/blocks/zones',         'CommunityFusion\Modules\Blocks\BlockController@getZonesApi', $cors);
        $this->post('/api/v1/blocks/positions',    'CommunityFusion\Modules\Blocks\BlockController@savePositions', $auth);


        // ── Marketplace ────────────────────────────────────────────────────
        $this->get('/admin/marketplace',                      'CommunityFusion\Modules\Marketplace\MarketplaceController@index',     $auth);
        $this->get('/admin/marketplace/package/{slug:[a-z0-9-]+}', 'CommunityFusion\Modules\Marketplace\MarketplaceController@detail',  $auth);
        $this->post('/admin/marketplace/install',             'CommunityFusion\Modules\Marketplace\MarketplaceController@install',   $auth);
        $this->post('/admin/marketplace/upload',              'CommunityFusion\Modules\Marketplace\MarketplaceController@upload',    $auth);
        $this->post('/admin/marketplace/uninstall',           'CommunityFusion\Modules\Marketplace\MarketplaceController@uninstall',$auth);
        $this->post('/admin/marketplace/toggle',              'CommunityFusion\Modules\Marketplace\MarketplaceController@toggle',    $auth);
        $this->post('/admin/marketplace/update',              'CommunityFusion\Modules\Marketplace\MarketplaceController@update',    $auth);
        $this->get('/api/v1/marketplace',                     'CommunityFusion\Modules\Marketplace\MarketplaceController@apiCatalog',  $cors);
        $this->get('/api/v1/marketplace/installed',           'CommunityFusion\Modules\Marketplace\MarketplaceController@apiInstalled', [...$cors,...$auth]);
        $this->get('/api/v1/marketplace/updates',             'CommunityFusion\Modules\Marketplace\MarketplaceController@apiUpdates',   [...$cors,...$auth]);

        // ── OAuth Callbacks ─────────────────────────────────────────────
        $this->get('/auth/discord/callback', 'CommunityFusion\Modules\Users\OAuthController@discordCallback');
        $this->get('/auth/twitch/callback',  'CommunityFusion\Modules\Users\OAuthController@twitchCallback');
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: Router.php | Role: Core | Version: 1.2.0                     ║
// ║  Last Updated: 2026-06-06  Sprint 6 — REST API + Ollama routes      ║
// ╚══════════════════════════════════════════════════════════════════════╝
