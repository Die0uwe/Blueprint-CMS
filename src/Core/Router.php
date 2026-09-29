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
        foreach (array_reverse($middlewareClasses) as $entry) {
            // "ClassName:arg" laat een route een parameter doorgeven aan de
            // middleware, bv. PermissionMiddleware:settings.edit. Container::make()
            // kent alleen class-namen, dus het deel na de eerste ':' wordt
            // hier afgesplitst en als extra argument aan handle() gegeven —
            // bestaande 2-parameter middlewares (AuthMiddleware e.a.) negeren
            // dat gewoon, PHP staat extra argumenten toe zonder foutmelding.
            [$class, $arg] = array_pad(explode(':', $entry, 2), 2, '');
            $middleware = $this->container->make($class);
            $next       = $pipeline;
            $pipeline   = fn(Request $req) => $middleware->handle($req, $next, $arg);
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
        // Wave 0/1 gap: deze routes hadden alleen $auth (ingelogd?), geen
        // rol-check — elk lid kon bij /admin. $perm() hangt PermissionMiddleware
        // ná AuthMiddleware zodat een ongeautoriseerde bezoeker eerst netjes
        // naar /login gaat, en een ingelogd lid zonder de juiste permissie een
        // 403 krijgt (zie PermissionMiddleware, HttpException).
        $perm = fn(string $permission) => [...$auth, "CommunityFusion\\Api\\Middleware\\PermissionMiddleware:{$permission}"];

        $this->get('/admin',          'CommunityFusion\Modules\Settings\AdminController@dashboard', $perm('admin.access'));
        $this->get('/admin/settings', 'CommunityFusion\Modules\Settings\AdminController@settings',  $perm('settings.edit'));
        $this->post('/admin/settings', 'CommunityFusion\Modules\Settings\AdminController@updateSettings', $perm('settings.edit'));

        // Blokken admin
        $this->get('/admin/blocks',                     'CommunityFusion\Modules\Blocks\BlockController@index',  $perm('blocks.manage'));
        $this->get('/admin/blocks/create',              'CommunityFusion\Modules\Blocks\BlockController@create', $perm('blocks.manage'));
        $this->post('/admin/blocks/store',              'CommunityFusion\Modules\Blocks\BlockController@store',  $perm('blocks.manage'));
        $this->post('/admin/blocks/{id:[0-9]+}/update', 'CommunityFusion\Modules\Blocks\BlockController@update', $perm('blocks.manage'));
        $this->post('/admin/blocks/{id:[0-9]+}/delete', 'CommunityFusion\Modules\Blocks\BlockController@delete', $perm('blocks.manage'));

        // ── Nieuws admin (Wave 2 — dashboard.php linkte al sinds Sprint 2
        //    naar /admin/news, dat bestond niet) — letterlijke /create-route
        //    vóór de generieke {id}-route, zelfde volgorde-conventie als Blog. ──
        $this->get('/admin/news',                     'CommunityFusion\Modules\News\NewsController@adminIndex', $perm('news.create'));
        $this->get('/admin/news/create',               'CommunityFusion\Modules\News\NewsController@createForm', $perm('news.create'));
        $this->post('/admin/news',                     'CommunityFusion\Modules\News\NewsController@store',      $perm('news.create'));
        $this->get('/admin/news/{id:[0-9]+}/bewerk',    'CommunityFusion\Modules\News\NewsController@editForm',   $perm('news.create'));
        $this->post('/admin/news/{id:[0-9]+}/bewerk',   'CommunityFusion\Modules\News\NewsController@update',     $perm('news.create'));
        $this->post('/admin/news/{id:[0-9]+}/verwijder','CommunityFusion\Modules\News\NewsController@delete',     $perm('news.create'));

        // ── Pagina's admin (Wave 2 — zelfde gap als News hierboven) ─────────
        $this->get('/admin/pages',                      'CommunityFusion\Modules\Pages\PageController@adminIndex', $perm('pages.manage'));
        $this->get('/admin/pages/create',                'CommunityFusion\Modules\Pages\PageController@createForm', $perm('pages.manage'));
        $this->post('/admin/pages',                      'CommunityFusion\Modules\Pages\PageController@store',      $perm('pages.manage'));
        $this->get('/admin/pages/{id:[0-9]+}/bewerk',     'CommunityFusion\Modules\Pages\PageController@editForm',   $perm('pages.manage'));
        $this->post('/admin/pages/{id:[0-9]+}/bewerk',    'CommunityFusion\Modules\Pages\PageController@update',     $perm('pages.manage'));
        $this->post('/admin/pages/{id:[0-9]+}/verwijder', 'CommunityFusion\Modules\Pages\PageController@delete',     $perm('pages.manage'));

        // ── Gebruikersbeheer admin (Wave 3 — verving de "nog niet gebouwd"-
        //    placeholder uit Wave 2; zie Settings/views/_placeholder.php-patroon,
        //    dat bestand hier vervangen is door een echt scherm). ────────────
        $this->get('/admin/users',                       'CommunityFusion\Modules\Users\UserAdminController@index',    $perm('users.manage'));
        $this->get('/admin/users/{id:[0-9]+}/bewerk',     'CommunityFusion\Modules\Users\UserAdminController@editForm', $perm('users.manage'));
        $this->post('/admin/users/{id:[0-9]+}/bewerk',    'CommunityFusion\Modules\Users\UserAdminController@update',   $perm('users.manage'));

        // ── Forumborden (Wave 4 — voorheen alleen via SQL aan te maken) ──
        $this->get('/admin/forum/boards',                    'CommunityFusion\Modules\Forum\BoardAdminController@index',      $perm('forum.moderate'));
        $this->get('/admin/forum/boards/nieuw',               'CommunityFusion\Modules\Forum\BoardAdminController@createForm', $perm('forum.moderate'));
        $this->post('/admin/forum/boards',                    'CommunityFusion\Modules\Forum\BoardAdminController@store',      $perm('forum.moderate'));
        $this->get('/admin/forum/boards/{id:[0-9]+}/bewerk',   'CommunityFusion\Modules\Forum\BoardAdminController@editForm',   $perm('forum.moderate'));
        $this->post('/admin/forum/boards/{id:[0-9]+}/bewerk',  'CommunityFusion\Modules\Forum\BoardAdminController@update',     $perm('forum.moderate'));
        $this->post('/admin/forum/boards/{id:[0-9]+}/verwijder', 'CommunityFusion\Modules\Forum\BoardAdminController@delete',   $perm('forum.moderate'));

        // ── Nieuwscategorieën (Wave 9 — zelfde gap als Forumborden hierboven,
        //    nu voor News; identiek patroon, zie CategoryAdminController).
        //    Letterlijke /nieuw-route vóór de generieke {id}-route, zelfde
        //    volgorde-conventie als Forumborden/Blog/Downloads hierboven. ──
        $this->get('/admin/news/categories',                    'CommunityFusion\Modules\News\CategoryAdminController@index',      $perm('news.create'));
        $this->get('/admin/news/categories/nieuw',               'CommunityFusion\Modules\News\CategoryAdminController@createForm', $perm('news.create'));
        $this->post('/admin/news/categories',                    'CommunityFusion\Modules\News\CategoryAdminController@store',      $perm('news.create'));
        $this->get('/admin/news/categories/{id:[0-9]+}/bewerk',   'CommunityFusion\Modules\News\CategoryAdminController@editForm',   $perm('news.create'));
        $this->post('/admin/news/categories/{id:[0-9]+}/bewerk',  'CommunityFusion\Modules\News\CategoryAdminController@update',     $perm('news.create'));
        $this->post('/admin/news/categories/{id:[0-9]+}/verwijder', 'CommunityFusion\Modules\News\CategoryAdminController@delete',   $perm('news.create'));

        // ── Rollen & Permissies (Wave 5 — voorheen alleen via SQL) ──────
        $this->get('/admin/roles',                        'CommunityFusion\Modules\Roles\RoleAdminController@index',      $perm('roles.manage'));
        $this->get('/admin/roles/nieuw',                   'CommunityFusion\Modules\Roles\RoleAdminController@createForm', $perm('roles.manage'));
        $this->post('/admin/roles',                        'CommunityFusion\Modules\Roles\RoleAdminController@store',      $perm('roles.manage'));
        $this->get('/admin/roles/{id:[0-9]+}/bewerk',       'CommunityFusion\Modules\Roles\RoleAdminController@editForm',   $perm('roles.manage'));
        $this->post('/admin/roles/{id:[0-9]+}/bewerk',      'CommunityFusion\Modules\Roles\RoleAdminController@update',     $perm('roles.manage'));
        $this->post('/admin/roles/{id:[0-9]+}/standaard',   'CommunityFusion\Modules\Roles\RoleAdminController@setDefault', $perm('roles.manage'));
        $this->post('/admin/roles/{id:[0-9]+}/verwijder',   'CommunityFusion\Modules\Roles\RoleAdminController@delete',     $perm('roles.manage'));

        // ── Sitenavigatie (Wave 5 — bouwt op cf_pages.menu_position) ────
        $this->get('/admin/menus',                          'CommunityFusion\Modules\Menus\MenuAdminController@index',    $perm('menus.manage'));
        $this->post('/admin/menus/{id:[0-9]+}/toevoegen',    'CommunityFusion\Modules\Menus\MenuAdminController@add',      $perm('menus.manage'));
        $this->post('/admin/menus/{id:[0-9]+}/verwijderen',  'CommunityFusion\Modules\Menus\MenuAdminController@remove',   $perm('menus.manage'));
        $this->post('/admin/menus/{id:[0-9]+}/omhoog',       'CommunityFusion\Modules\Menus\MenuAdminController@moveUp',   $perm('menus.manage'));
        $this->post('/admin/menus/{id:[0-9]+}/omlaag',       'CommunityFusion\Modules\Menus\MenuAdminController@moveDown', $perm('menus.manage'));

        // ── Systeemlogs (Wave 5 — alleen-lezen) ─────────────────────────
        $this->get('/admin/logs', 'CommunityFusion\Modules\Logs\LogAdminController@index', $perm('logs.view'));

        // ── Thema's (Wave 5 — actief thema wisselen zonder installer) ───
        $this->get('/admin/themes',                        'CommunityFusion\Modules\Themes\ThemeAdminController@index',    $perm('themes.manage'));
        $this->post('/admin/themes/{slug:[a-z0-9-]+}/activeren', 'CommunityFusion\Modules\Themes\ThemeAdminController@activate', $perm('themes.manage'));

        // ── Media (Wave 5 — scant storage/uploads/ + storage/downloads/) ─
        $this->get('/admin/media',              'CommunityFusion\Modules\Media\MediaAdminController@index',  $perm('media.manage'));
        $this->post('/admin/media/verwijderen', 'CommunityFusion\Modules\Media\MediaAdminController@delete', $perm('media.manage'));

        // ── Overige admin-sidebar links (Wave 2) ────────────────────────────
        // /admin/modules dupliceerde in de praktijk /admin/marketplace (module-
        // installatie/-beheer gebeurt daar al) — een redirect voorkomt twee
        // losse "modules"-schermen die uit de pas gaan lopen.
        $this->get('/admin/modules', fn(Request $r) => Response::redirect('/admin/marketplace'), $perm('admin.access'));

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
        // BlockController zelf heeft geen enkele interne ->can()-check — dit was
        // de enige gate, en die was login-only. Nu ook permissie-gated.
        $this->post('/api/v1/blocks/positions',    'CommunityFusion\Modules\Blocks\BlockController@savePositions', $perm('blocks.manage'));


        // ── Marketplace ────────────────────────────────────────────────────
        // MarketplaceController zelf roept al authorize('marketplace.view'/
        // 'marketplace.install') aan — maar die permissies waren nooit geseed
        // (zie schema.sql) en authorize() gooide tot deze doorloop een
        // exception die als generieke 500 werd afgehandeld i.p.v. een 403
        // (zie HttpException.php). Route-level $perm() hier is een tweede,
        // vroege laag die nu ook echt iets doet.
        $this->get('/admin/marketplace',                      'CommunityFusion\Modules\Marketplace\MarketplaceController@index',     $perm('marketplace.view'));
        $this->get('/admin/marketplace/package/{slug:[a-z0-9-]+}', 'CommunityFusion\Modules\Marketplace\MarketplaceController@detail',  $perm('marketplace.view'));
        $this->post('/admin/marketplace/install',             'CommunityFusion\Modules\Marketplace\MarketplaceController@install',   $perm('marketplace.install'));
        $this->post('/admin/marketplace/upload',              'CommunityFusion\Modules\Marketplace\MarketplaceController@upload',    $perm('marketplace.install'));
        $this->post('/admin/marketplace/uninstall',           'CommunityFusion\Modules\Marketplace\MarketplaceController@uninstall', $perm('marketplace.install'));
        $this->post('/admin/marketplace/toggle',              'CommunityFusion\Modules\Marketplace\MarketplaceController@toggle',    $perm('marketplace.install'));
        $this->post('/admin/marketplace/update',               'CommunityFusion\Modules\Marketplace\MarketplaceController@update',    $perm('marketplace.install'));
        $this->get('/api/v1/marketplace',                     'CommunityFusion\Modules\Marketplace\MarketplaceController@apiCatalog',  $cors);
        $this->get('/api/v1/marketplace/installed',           'CommunityFusion\Modules\Marketplace\MarketplaceController@apiInstalled', [...$cors, ...$perm('marketplace.view')]);
        $this->get('/api/v1/marketplace/updates',             'CommunityFusion\Modules\Marketplace\MarketplaceController@apiUpdates',   [...$cors,...$auth]);

        // ── Overige admin-sidebar links, catch-all (Wave 2) ─────────────────
        // Media/Gebruikers/Rollen/Thema's/Menu's/Logs stonden al sinds Sprint 2
        // in de sidebar maar hadden geen enkele route (kale 404). AdminController
        // ::handle() bestond al (zie dat bestand) maar werd nergens geregistreerd
        // — dit vangt die zes op met een eerlijk "nog niet gebouwd"-scherm i.p.v.
        // een 404 zonder uitleg. Moet de ALLERLAATSTE /admin/*-route zijn: de
        // Router matcht routes in registratievolgorde (niet op specificiteit),
        // dus elke specifiekere /admin/... hierboven (incl. Marketplace) moet
        // hier vóór staan — anders "wint" deze catch-all en breekt die route.
        $this->get('/admin/{path:[a-z0-9\/-]+}', 'CommunityFusion\Modules\Settings\AdminController@handle', $perm('admin.access'));

        // ── OAuth Callbacks ─────────────────────────────────────────────
        $this->get('/auth/discord/callback', 'CommunityFusion\Modules\Users\OAuthController@discordCallback');
        $this->get('/auth/twitch/callback',  'CommunityFusion\Modules\Users\OAuthController@twitchCallback');
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: Router.php | Role: Core | Version: 1.2.0                     ║
// ║  Last Updated: 2026-06-06  Sprint 6 — REST API + Ollama routes      ║
// ╚══════════════════════════════════════════════════════════════════════╝
