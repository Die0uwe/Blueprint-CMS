# Plan — Eigen editors + plugin-laag (Blueprint CMS, basis v1.28.0)

Status: **WACHT OP AKKOORD** van Ouwe. Er is nog geen code geschreven.
Opgesteld na verkenning door vijf specialisten (architectuur, database, security, plugins, UI/innovatie).

## 1. Belangrijkste bevindingen (wijken af van de opdracht)

| # | Bevinding | Gevolg voor het plan |
|---|---|---|
| 1 | De tabel heet `cf_blog_posts`, niet `cf_blog` | kolom `content_markup` komt op `cf_blog_posts` |
| 2 | Er is geen migratierunner en geen INFORMATION_SCHEMA-check. `importSchema()` voert `CREATE TABLE` uit en slaat bestaande tabellen over, dus nieuwe kolommen komen nooit op bestaande installaties | stap (a) bouwt eerst een kleine idempotente `ColumnMigrator` |
| 3 | `/admin/blocks/reorder` bestaat niet; herordenen loopt via `POST /api/v1/blocks/positions` (`BlockController::savePositions`) | Editor B hergebruikt dat endpoint (of alias) |
| 4 | Het schema-veld heet `getConfigSchema()` (PHP) en `settings` (module.json), niet `settings_schema` | de generieke renderer bouwt op `getConfigSchema()` |
| 5 | `src/Core/Marketplace/PackageManager` doet al ±80% van PluginManager (upload, install, uninstall, enable/disable, update) | `PluginManager` wordt een **dunne facade** met alleen discover, migrate, permissie-seed en autoload. Geen tweede installatie-engine |
| 6 | Modules die via upload komen zijn niet autoloadbaar (statische PSR-4 in `composer.json`, `vendor/` staat in git) en `loadModule` slaat ze stil over | **eerste blokkade voor echte plugins** → runtime-autoloader op `manifest.autoload` |
| 7 | `module.json` velden `permissions`, `hooks`, `dependencies`, `min_cms` worden nergens gelezen of afgedwongen | PluginManager seedt permissies en valideert `requires` |
| 8 | Er is geen `admin.menu`-hook; de admin-sidebar is statisch | filter `admin.menu` toevoegen |
| 9 | `applyFilters` bestaat maar wordt nergens gebruikt; `user.login` en `guild.application.created` hebben nooit een `doAction` | de nieuwe hooks (`content.before_render` e.d.) zijn de eerste echte filters |
| 10 | Geen Twig-sandbox, geen CSP, `X-Frame-Options: DENY` | preview via `<iframe sandbox srcdoc>` (werkt zonder header-wijziging); Twig krijgt een tweede, gesandboxte `Environment` |
| 11 | Er is geen HTML-sanitizer. Pages/News zijn vertrouwd (`\|raw`), Blog is bewust plain (`\|nl2br`) | Blog-editor blijft tekst/markup-naar-veilig; geen `\|raw` terug voor Blog |

## 2. Bestaande beveiligingsgaten die VÓÓR de plugin-upload dicht moeten (PackageManager)

Gevonden tijdens de audit; staan los van de editors maar maken plugin-upload onveilig.

1. `install()` neemt `slug` ongesaneerd uit de request; `deleteDirectory($destPath)` draait vóór het kopiëren. Een slug als `..` kan `modules/..` leegmaken (`PackageManager.php:299, 384-390`).
2. `installFromUpload()` kan een lege slug opleveren (`-1.zip`); dan wordt de hele `modules/`-map gewist (`:88-91`, `:390`).
3. `deployPackage()` vertrouwt `_extracted_path` uit de `module.json` in de zip (`:393-399`) → willekeurige map kopiëren.
4. Zip-check is alleen `..` en leidende `/`: geen backslash, `C:`, null-byte, symlink, aantal, uitgepakte grootte of bomb-ratio (`:341-348`).
5. SSRF in `download()`: URL uit de request, geen scheme/IP-filter, `FOLLOWLOCATION` zonder limiet (`:299-325`).
6. `update()` wist de oude module vóór de download slaagt; geen rollback (`:195-201`).
7. `UploadManager::resolve()` mist `DIRECTORY_SEPARATOR` bij de `str_starts_with` (`:191-206`).
8. `Request::ip()` vertrouwt `X-Forwarded-For` blind; `RateLimitMiddleware` is niet per route instelbaar en staat op 3 routes.
9. Bestaande `BlockController::update()` overschrijft `config`/`title` bij alleen een `is_visible`- of `zone`-wijziging (`:101-120`). Lijkt een echte bug.
10. `visibility_roles` wordt niet gefilterd in `renderZone`; het publieke `GET /api/v1/blocks/zones` lekt rolbeperkte blocks.

## 3. Uitvoeringsplan

Volgorde uit de opdracht, met een nieuwe stap **(0)** voor de security-fundering. Elke stap: `php -l`, PHPUnit, korte notitie in dit bestand.

| Stap | Inhoud | Nieuwe/gewijzigde bestanden | Tests |
|---|---|---|---|
| 0 | Security-fundering: `ManifestValidator` (slug `^[a-z0-9-]{1,64}$`, gelijk aan mapnaam, `class` in verwachte namespace), `ZipInspector` (entries, limieten, padnormalisatie, symlinks, extensies), slug-containment met `realpath` vóór elke delete, `_extracted_path` niet meer uit JSON, SSRF-guard, rollback bij update | `src/Core/Marketplace/ManifestValidator.php`, `ZipInspector.php`, `PackageManager.php` | zip-slip, lege slug, `..`-slug, bomb, symlink, SSRF |
| a | Migraties: `cf_plugins`, `cf_block_content`, `cf_editor_drafts`; kolom `content_markup` op `cf_pages`, `cf_news`, `cf_blog_posts`; `ColumnMigrator` (INFORMATION_SCHEMA + ALTER); permissie-seed | `schema.sql`, `src/Core/Database/ColumnMigrator.php`, `cli/commands/MigrateCommand.php` | migrate 2× draaien = idempotent |
| b | `PluginManager` (eigen laag, hergebruikt ZipInspector/SafeFs/AtomicDeploy — geen tweede installatie-engine, wel losse `plugins/`-map en `cf_plugins`): `discover/activate/deactivate/uninstall/migrate/loadActive`, runtime-autoloader, permissie-seed uit manifest, prefix-regel `cf_plg_{slug}_`, audit | `src/Core/Plugin/PluginManager.php`, `PluginAutoloader.php`, `Application::boot()` (na `loadModules()`, vóór `configureRuntime`) | discover/activate/deactivate, isolatie-regels |
| c | Editor A: textarea + regelnummers (CSS counter), tokenizer HTML/PHP/Twig (~200 regels vanilla JS), overlay via `textContent`, toolbar, "Bron"-knop, autosave 30 s, preview-endpoint | `public/assets/js/admin/editor.js`, `tokenizer.js`, `EditorController`, admin-form views | preview: escaping, CSRF, permissie, geen PHP-exec |
| d | Editor B: blok-editor (3 kolommen), HTML5 DnD, generieke `getConfigSchema()`-renderer (text, textarea, select, checkbox, number, color, media), zone-preview | `public/assets/js/admin/blocks.js`, `BlockPreviewController` | schema-render, reorder |
| e | Markup-block: gesandboxte Twig-`Environment`, whitelist in `config/markup-block.php`, geen PHP-uitvoering, `permission_required`; override-loader (`ChainLoader`) | `src/Blocks/Types/MarkupBlock.php`, `src/Core/Template/SandboxFactory.php` | `{{ system('rm -rf /') }}` faalt, whitelist |
| f | Admin-UI `/admin/plugins` (lijst, (de)activeren, instellingen via bestaand settings-scherm), `admin.menu`-filter, upload via bestaande Marketplace-route | views, `AdminMenu` helper, `Router.php` | RBAC per route |
| g | `docs/editors.md`, `docs/plugins.md`, voorbeeldplugin `plugins/example-hello-world/`, CHANGELOG met security-klassen | docs | — |

Basisregels: geen externe libraries, PSR-12, PHPStan level 8, alle nieuwe POST-routes met CSRF + permissie + rate limit, audit-log bij elke plugin-statuswijziging en elke markup-override.

## 4. Nieuwe routes en permissies

Routes: zoals in de opdracht, plus `POST /api/v1/blocks/positions` blijft bestaan (geen `/admin/blocks/reorder`).
Permissies (seed in `schema.sql`, `INSERT IGNORE`): `editor.use`, `editor.markup.php` (alleen super_admin), `plugins.manage` (alleen super_admin), `blocks.override_template` (alleen super_admin). Aanbevolen extra: `templates.edit`.

## 5. Vragen aan Ouwe (met voorgestelde standaard)

1. **PHP-tags in de markup-editor?** Voorstel: *bewerken en tonen* voor `editor.markup.php`, maar **nooit uitvoeren in user-markup** (alleen Twig). Zo blijft "PHP-exec" onmogelijk, ook voor super_admin.
2. **Blok-editor: alle 6 zones of eerst alleen `content`?** Voorstel: alle zes (ze staan al in `BlockController::ZONES`); de editor werkt zone-agnostisch.
3. **Mogen plugins eigen admin-pagina's registreren?** Voorstel: ja, via `router.routes` + `admin.menu`-filter (beide nodig voor menu-items).
4. **Plugin-upload ook via CLI?** Voorstel: ja, `ModuleInstallCommand` bestaat al; `plugin:install <zip>` wordt een dunne wrapper.
5. **Markup-override per block-instantie, per block-type of beide?** Voorstel: **beide**, instantie wint (`cf_block_content`), dan type (`storage/block-overrides/{type}.twig`), dan core.
6. (nieuw) **Plugins = gewone PHP-code met volle rechten** (er is geen PHP-sandbox). Voorstel: upload alleen voor `super_admin` en een config-vlag `ALLOW_PLUGIN_UPLOAD` die standaard uit staat. Akkoord?
7. (nieuw) Stap 0 (hardening van de bestaande PackageManager) eerst, als aparte release v1.28.1?

## 6. Innovatie-ideeën (zonder externe libs)

Gerangschikt op waarde/inspanning; de eerste vijf passen direct bij dit plan.

1. **Live-data shortcodes in content** — `[block type="twitch-live" channel="x"]` rendert bestaande blocks (Twitch, Kick, YouTube, Discord, Warcraft, FiveM) in Pages/News. Via nieuw filter `content.render`. Klein-middel. Uniek voor een gaming-CMS.
2. **Autosave met recover-banner** — eerst localStorage, later `cf_editor_drafts`. Klein.
3. **Sandbox-preview met thema** — iframe `srcdoc`, `.cf-prose`-stylesheet, tablet/mobiel-knoppen. Klein-middel. Vereist eerst `.cf-prose` CSS (ontbreekt nu).
4. **A11y/SEO-lint in de editor** — `alt`, kopniveaus, "klik hier", meta-lengte, Google-snippet-preview. Klein. Sluit aan op ROADMAP §2.4.
5. **Slash-commands/snippets** — `/raidtijd`, `/wow-class`, `/discord`, `/patch-notes`; door plugins uitbreidbaar via `editor.toolbar.register`. Klein.
6. **Block-presets en pagina-sjablonen** — "Raid-aanmelding", "Streamschema", "Roster"; modules leveren sjablonen mee via `module.json`. Klein-middel.
7. **Revisies met diff + herstel** — `cf_content_revisions`, LCS-diff in vanilla JS, gekoppeld aan audit-log. Middel.
8. **Plugin-capabilities in `plugin.json`** (`"capabilities": ["blocks","routes","settings"]`) met tonen vóór activeren — "deze plugin vraagt: routes, eigen tabellen, menu-item". Klein, veel vertrouwen.
9. **Plugin-gezondheidscheck** — `discover()` toont `invalid`/`needs_migration`/`missing-deps` en een "dry-run" van de migraties. Klein.
10. **Niet-rechtenverstrekkende preview-link** — tijdelijke, ondertekende URL voor concept-pagina's zonder login (HMAC + verloop). Middel.

## 7. Voortgang

- [x] Verkenning (5 specialisten)
- [x] Plan geschreven
- [x] Akkoord Ouwe (2026-09-30, alle voorstellen §5 overgenomen)
- [x] Stap 0 — security-fundering (v1.28.1): ManifestValidator, ZipInspector, SafeFs, SsrfGuard/SafeDownloader, atomisch deployen met rollback, CSRF-fix. 69 unit-tests groen (mini-runner; PHPUnit niet installeerbaar in sandbox)
- [x] Stap a — migraties (cf_plugins, cf_plugin_migrations, cf_block_content, cf_editor_drafts), `ColumnMigrator` voor `content_markup`, 4 editor/plugin-permissies. Getest op upgrade vanaf v1.28.0-schema en 2× achter elkaar
- [x] Stap b — PluginManager (discover/activate/deactivate/uninstall/migrate/loadActive/settings/upload), PluginManifest, PluginSqlGuard (alleen `cf_plg_{slug}_*`), PluginAutoloader, `Application::loadPlugins()` na `loadModules()`. 17 unit- + 12 integratietests
- [x] Stap c — Bericht-editor: eigen tokenizer (HTML/PHP/Twig, 9 node-tests incl. fuzz), overlay + regelnummers, toolbar, preview (`<iframe sandbox="">`), autosave 30 s, `MarkupRenderer` (Twig-sandbox, PHP nooit uitgevoerd), ratelimit, links vanuit Pagina's/Nieuws. 103 PHP-tests + 9 JS-tests groen. **Open:** EditorController-integratietest (CSRF/permissie/save) nog te schrijven; oude formulier wist `content_markup` niet bij opslaan (dan toont de editor oude bron)
- [x] Stap d — blok-editor: ⚙️-instellingen (schema-renderer, `BlockSettings`), `update()` wist config niet meer, verborgen blokken blijven in beheer, Markup-sectie met sandbox-voorbeeld, zone-voorbeeld (`<iframe sandbox="">`), sleep-herordenen bestond al
- [x] Stap e — markup-blok (`cf_block_content`, recht `blocks.override_template`, PHP-tags alleen met `editor.markup.php` en nooit uitgevoerd) + sjabloon-override per blocktype in `storage/block-overrides/{type}.twig` (proefrender bij opslaan, kapotte override valt terug op standaard, auditlog `block.markup.save`). 11 integratietests met echte RBAC
- [x] Stap f — `/admin/plugins` (overzicht, activeren/deactiveren/migreren/verwijderen met naambevestiging, instellingen uit het manifest, versleutelde velden blijven verborgen), ZIP-upload standaard UIT (`ALLOW_PLUGIN_UPLOAD=true` én rol super_admin), menu-filter `admin.menu` (alleen `/admin/...`-paden, tekst geëscaped), CLI `plugin:list|install|activate|deactivate|migrate`. 7 integratietests (rechten, CSRF, slug-traversal, upload-gate)
- [ ] Stap g
