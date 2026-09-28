<!--
============================================================================
Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
GPL-3.0-or-later
============================================================================
-->

# BigBoss Wave 0 — Gap-analyse Blueprint CMS (Bluprint-CMS repo)

> Audit uitgevoerd tegen de echte repo `Die0uwe/bluprint-cms` (public, HEAD commit `6108fec3`,
> v1.8.0, 2026-09-27). Dit vervangt een eerdere inschatting die zonder repo-toegang was gemaakt.
> Dit document is het oorspronkelijke Wave 0-auditrapport; de tabel onderaan ("Wave 1 — status per
> bevinding") is later toegevoegd en houdt bij wat er sindsdien daadwerkelijk is opgelost. Voor de
> volledige technische details per fix, zie `CHANGELOG.md` (v1.9.0).

## Status samengevat

8 sprints + Sprint 9 zijn gepusht. `docs/ANALYSE.md` claimt "0 issues" na Sprint 8, maar dat
rapport is **nooit met PHP zelf gedraaid** — er is geen `composer.lock`, geen `phpunit.xml`, geen
CI, en `tests/Unit` + `tests/Integration` bevatten alleen `.gitkeep`. De "98/100 security audit"
en "0 broken routes" zijn dus zelfgerapporteerde regex-tellingen, niet geverifieerde
testresultaten.

## 🔴 Kritieke bevindingen

1. **Sprint 9 (WoW Roster/Armory) is dode, verkeerde-codebase code.**
   `modules/warcraft/src/roster.php` en `armory.php` zijn **WordPress-code** (`ABSPATH`,
   `WP_Error`, `get_transient()`, `wp_remote_get()`, `$wpdb`, `add_shortcode`) — overduidelijk
   1-op-1 gekopieerd uit de Slayer Alliance Master Suite (de WordPress-plugin). Dit project is
   geen WordPress; die functies bestaan hier niet. De bestanden worden nergens door
   `WarcraftModule.php` geladen (niet in `getBlocks()`, geen require/autoload-hook), dus ze
   crashen niet — ze zijn puur dode gewicht die de CHANGELOG en README ten onrechte als
   "Productie" en "voltooid" bestempelen.
   → **Actie:** verwijderen of herbouwen als native `CommunityFusion\Modules\Warcraft` blocks
   (PDO/Connection, geen WP-functies). CHANGELOG/README-claim corrigeren.

2. **Discord-koppeling ondersteunt geen "inloggen met Discord" voor nieuwe bezoekers.**
   Geverifieerd in `DiscordOAuthController::redirect()` en `::callback()`: beide beginnen met
   `if (!$this->auth->check()) return Response::redirect('/login')`. Discord kan dus alleen als
   *extra* koppeling op een al bestaand, ingelogd account — nooit als eerste registratie/login.
   Dit staat haaks op wat community-CMS'en normaal bieden en op de blueprint-tekst ("Discord
   OAuth Login").
   → **Actie:** `AuthController` uitbreiden met "registreer/login via Discord"-flow die bij
   afwezigheid van een gekoppeld account automatisch een `cf_users`-rij aanmaakt.

3. **Geen enkele echte test, geen CI, geen composer.lock.**
   `composer.json` definieert `phpunit/phpunit`, `phpstan`, `phpcs` als require-dev en een
   `composer test`-script, maar er is geen `phpunit.xml` en 0 testbestanden — het script zou
   meteen falen. Er is nooit `composer install` gedraaid (geen lockfile), dus de
   dependency-graph is nooit gevalideerd. Geen `.github/workflows`.
   → **Actie:** GitHub Actions workflow opzetten (PHP 8.3, `composer install`, `phpunit`,
   `phpstan level 8`, `phpcs PSR-12`) vóór er nog een sprint bovenop gebouwd wordt.

## 🟡 Belangrijke bevindingen

4. **JWT-signing en OAuth-tokenversleuteling delen dezelfde sleutel.**
   `JWTManager` (HS256) en `OAuthClient::getAppKey()` (AES-256-GCM) gebruiken beide
   rechtstreeks `$_ENV['APP_KEY']`. Geen catastrofale breuk (andere algoritmes), maar tegen het
   principe van sleutelscheiding — een sleutellek in de ene context is meteen bruikbaar in de
   andere.
   → **Actie:** aparte `JWT_KEY` en `APP_KEY` in `.env.example` + beide managers aanpassen.

5. **Kernmodules uit de blueprint ontbreken volledig: Blog, Downloads, Media, Contact, Forum.**
   Alleen `src/Modules/Forum/` bestaat als lege map (`.gitkeep`). Blog/Downloads/Media/Contact
   komen nergens voor in `src/Modules/` of `modules/` — geen schema, geen controller, geen
   template. De installer (`installer/steps/Step5.php`) biedt bovendien maar 4 vaste modules aan
   (`users, news, pages, settings`) — niet de vrije module-selectie die de blueprint beschrijft
   (Pagina's/Nieuws/Blog/Downloads/Contact/Forum/Discord/Twitch/Guild).
   → **Actie:** Forum-schema + core module; Blog, Downloads, Contact modules; installer Step5
   dynamisch maken op basis van geïnstalleerde/marketplace-modules.

6. **Geen enkele upload-functionaliteit gebouwd.**
   `cf_news.featured_image` en `cf_users.avatar_url` bestaan als kolommen, `public/uploads/` en
   `storage/uploads/` bestaan als lege mappen, maar er is **geen enkele regel code** die
   daadwerkelijk naar een van beide schrijft. De eerder aangenomen "uploads staan onder public/,
   spec zegt buiten webroot" is dus geen actueel risico — er is simpelweg nog geen
   upload-feature.
   → **Actie:** bouw de upload-handler in `storage/uploads/` (buiten webroot, zoals SD v1.0
   voorschrijft) met MIME-whitelist, en serveer via een controller-route i.p.v. directe
   public-toegang.

## 🟢 Wat wél klopt (bevestigd, geen actie nodig)

- Module-schema's (`cf_discord_role_mapping`, `cf_discord_sync_log`,
  `cf_guild_ranks/members/teams/applications`) staan **niet** in het centrale `schema.sql`, maar
  worden correct via `CREATE TABLE IF NOT EXISTS` in de `install()`-methode van elke module
  aangemaakt. Dit is een geldig patroon (self-installing modules) — eerder ten onrechte als
  "ontbrekend schema" gemeld.
- CSRF, prepared statements, argon2id, AES-256-GCM, session regeneration: aanwezig zoals
  geclaimd (visueel gecontroleerd in `CsrfProtection.php`, `AuthManager.php`, `OAuthClient.php`).
- GPL-3.0 headers + file cards: consistent aanwezig op de gecontroleerde bestanden.

---

## Wave 1 — status per bevinding (bijgewerkt na de fix-doorloop)

| # | Bevinding | Status | Detail |
|---|---|---|---|
| 1 | WP-code in `modules/warcraft/` | ✅ Opgelost | `roster.php`/`armory.php` + assets verwijderd; native WoW-blocks (`WowGuildRosterBlock` e.a.) dekten de functionaliteit al. |
| 2 | Discord login voor nieuwe bezoekers | ✅ Opgelost | `DiscordOAuthController::loginRedirect()` + `AuthManager::findOrCreateFromOAuth()`. |
| 3 | Geen tests/CI/composer.lock | 🟡 Deels | CI-workflow + echte PHPUnit-tests toegevoegd. `composer.lock` nog niet gegenereerd — packagist.org was onbereikbaar in de sandbox waarin dit werk is gedaan; eerste `composer install` in een omgeving mét registry-toegang moet het lockfile committen. |
| 4 | JWT/APP_KEY delen dezelfde sleutel | ✅ Opgelost | Aparte `jwt.secret`/`app.key`, door de installer met eigen willekeurige waarden gegenereerd. Onderweg bleek de échte, ergere bug: `AuthManager`/`JWTManager` waren nooit in de DI-container gebonden (elke auth-route crashte) en `APP_KEY` werd nooit naar `$_ENV` gesynchroniseerd (nul-byte encryptiesleutel) — beide gefixed in `Application::boot()`. |
| 5 | Forum/Blog/Downloads/Media/Contact ontbreken | ✅ Opgelost (Media als algemene upload-serve-route, geen aparte galerij-module — die staat gepland als S11) | Forum (`cf_categories`+`cf_forum_topics`+`cf_forum_posts`), Blog (`cf_blog_posts`, één blog per lid), Downloads (`cf_downloads`), Contact (`cf_contact_messages`) — zie CHANGELOG v1.9.0 voor volledige details per module. |
| 6 | Geen upload-functionaliteit | ✅ Opgelost | `UploadManager` (afbeeldingen) + `UploadManager::forDownloads()` (bredere whitelist), buiten webroot, MIME-whitelist via `finfo`, geserveerd via `MediaController`/`DownloadsController`. |
| — | Installer Step5 vaste 4 modules | ✅ Opgelost | Dynamisch gescand uit `modules/*/module.json`; selectie wordt nu ook echt naar `cf_modules` geschreven (voorheen las Step5 `$_POST['modules']` in maar deed er niets mee). |
| — | *(nieuw gevonden tijdens Wave 1, niet in Wave 0 gezien)* RBAC-permissies nooit geseed | ✅ Opgelost | `cf_permissions`/`cf_role_permissions` waren volledig leeg — elke `auth()->can(...)`-check gaf altijd `false`, ook voor de door de installer aangemaakte super-admin. Basisset nu geseed in `schema.sql`. |
| — | *(nieuw gevonden)* `auth`/`settings`/`menu_pages` nooit als Twig-globals beschikbaar | ✅ Opgelost | Header toonde op elke pagina altijd "Inloggen", nooit het echte account; site-titel/menu waren leeg. `ThemeManager::addGlobal()` + injectie in `Application::boot()`. |
| — | *(nieuw gevonden)* `/admin`-routes niet permissie-gated | ⚠️ Niet opgelost | `AuthMiddleware` controleert alleen "ingelogd", niet welke rol — elk lid kan bij `/admin`. Gedocumenteerd in README "Bekende beperkingen", niet gefixed in deze doorloop. |
| — | *(nieuw gevonden)* Admin-sidebar met dode links; geen News/Pages admin-CRUD | ⚠️ Niet opgelost | `dashboard.php` linkt naar `/admin/news`, `/admin/users`, `/admin/media`, enz. zonder route/controller. Zie README. |
| — | *(nieuw gevonden)* Geen `Mailer`-klasse | ⚠️ Niet opgelost | Contact-formulier slaat berichten op maar verstuurt geen e-mail, ondanks een volledige SMTP-config-sectie. |
| — | *(nieuw gevonden)* `migrate`/`module:install` CLI-commando's ontbreken | 🟡 Deels | Bestonden nooit; `console.php` gaf voorheen een kale fatal error, geeft nu een duidelijke melding. Commando's zelf nog niet gebouwd. |
| — | *(nieuw gevonden bij eindcontrole, volledige repo-sweep)* Installer stap 2/3/4-templates: dubbele/ongesloten `<?php`-tag | ✅ Opgelost | Fatale parse-fout — elke installatie crashte op stap 2. Zie CHANGELOG v1.9.0 "Opgelost — fatale parse-fouten". |
| — | *(nieuw gevonden)* `AdminController::handle()` twee keer gedeclareerd | ✅ Opgelost | Fatale "Cannot redeclare" — de hele `/admin`-sectie kon nooit laden. De verwijderde dubbele versie had bovendien geen path-sanitization (LFI-risico). |
| — | *(nieuw gevonden)* `WowGuildRosterBlock.php`: `<?=` in heredoc + geciteerde array-key | ✅ Opgelost | Parse-fout zodra dit (native, WP-vervangende) blok rendert. |

Audit uitgevoerd 2026-09-27 (Wave 0). Wave 1-fixes 2026-09-28. Repo: `github.com/Die0uwe/bluprint-cms`.
