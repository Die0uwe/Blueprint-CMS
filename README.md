<!--
============================================================================
Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
GPL-3.0-or-later
============================================================================
-->

<div align="center">

<img src="public/assets/img/logo-256.png" alt="Blueprint CMS" width="120" height="120">

# Blueprint CMS

**Modulair PHP 8.3+ Community CMS voor gaming, streamers & gilden**

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php)](https://php.net)
[![MariaDB](https://img.shields.io/badge/MariaDB-10.11%2B-003545?style=flat-square&logo=mariadb)](https://mariadb.org)
[![License](https://img.shields.io/badge/License-GPL--3.0-blue?style=flat-square)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.26.3-brightgreen?style=flat-square)](CHANGELOG.md)
[![CI](https://img.shields.io/github/actions/workflow/status/Die0uwe/bluprint-cms/ci.yml?branch=main&style=flat-square&label=CI)](.github/workflows/ci.yml)

*Geïnspireerd door PHP-Fusion · Down Under Fusion · ImpressCMS*

[📊 Analyse Rapport](docs/ANALYSE.md) · [🔍 Wave 0/1 Gap-analyse](docs/wave-0-gap-analysis.md) · [🗺️ Stappenplan/Roadmap](docs/ROADMAP.md) · [🖼️ Frontpage Mockup](docs/MOCKUP-FRONTPAGE.html) · [🏗️ Architectuur](docs/assets/architecture.md)

</div>

---

## ✨ Wat is Blueprint CMS?

Blueprint CMS is een open-source, **modulair PHP-framework** speciaal gebouwd voor:

- 🎮 **Gaming communities** — guild management, server status, roster, raid progress
- 📺 **Streamers** — Discord / Twitch / YouTube / Kick integratie out-of-the-box
- ⚔️ **WoW Gilden** — Blizzard API v2 + Raider.IO integratie, guild roster, character armory, teams
- 🤖 **AI-powered** — Gratis lokale AI via Ollama (llama3.2, mistral, gemma2) + Open WebUI
- 🏢 **Verenigingen & bedrijven** — volledige website binnen 5 minuten

---

## 🏗️ Architectuur

```
public/               ← Enige publieke map (document root)
│  index.php          ← Front controller — enige entry point
│
src/Core/             ← Framework kernel
│  Application.php    ← Bootstrap + DI Container + module loader
│  Container.php      ← PSR-11 Dependency Injection
│  Router.php         ← URL routing + middleware pipeline
│  Request.php / Response.php
│  Hook/              ← Action/filter systeem
│  Cache/             ← PSR-16 (File + Redis)
│  Database/          ← PDO wrapper + Fluent QueryBuilder
│  Auth/              ← Login + JWT + RBAC
│  Block/             ← BlockRegistry + zone rendering
│  Queue/             ← Database job queue
│  Marketplace/       ← Package manager (download, extract, deploy)
│  Security/          ← CSRF + rate limiting
│  Template/          ← Twig 3.x ThemeManager
│
src/Modules/          ← Core modules
src/Api/              ← REST API v1 + middleware
src/Blocks/Types/     ← 6 core block types
modules/              ← 7 community/gaming modules
themes/               ← Twig dark gaming thema
```

---

## ⚡ Kernprincipes

| Principe | Implementatie |
|---|---|
| **Modulair** | Elke functionaliteit is een losstaande Module + BlockRegistry |
| **Drag & Drop** | Blokken positie-instelbaar via admin UI (Ajax, geen reload) |
| **Gaming/Streamer** | Discord, Twitch, WoW, Guild, Minecraft, FiveM ingebouwd |
| **AI-first** | Ollama AI chat + Open WebUI + guild analyse + nieuws samenvatting |
| **API-first** | REST API v1 met OAuth2 + JWT + CORS + rate limiting |
| **Secure by default** | RBAC, CSRF, prepared statements, argon2id, AES-256-GCM |
| **PSR-compliant** | PSR-4, PSR-7, PSR-11, PSR-14, PSR-16 |

---

## 🔧 Vereisten

| Component | Minimum |
|---|---|
| PHP | 8.3+ |
| MariaDB / MySQL | 10.11+ / 8.0+ |
| Extensions | PDO, pdo_mysql, GD, cURL, mbstring, openssl, json, zip |
| Composer | 2.x |

---

## 🚀 Installatie

```bash
git clone https://github.com/Die0uwe/Bluprint-CMS.git
cd Bluprint-CMS
composer install --optimize-autoloader
cp .env.example .env
# Navigeer naar http://jouwsite.nl/installer/
```

> ⚠️ **Alleen FTP/bestandsbeheer, geen SSH-toegang?** `composer install` kan dan niet op de
> server zelf draaien. Draai dat commando lokaal op je eigen computer, in dezelfde projectmap,
> vóór het uploaden — en upload daarna de hele map in één keer, **inclusief** de dan aangemaakte
> `vendor/`-map. Sla je die map per ongeluk over, dan toont de site sinds v1.26.1 een duidelijke
> uitlegpagina in plaats van een leeg wit scherm.

---

## 🧩 Modules (11 beschikbaar)

| Module | Blocks | Highlights |
|---|---|---|
| Discord | 2 | OAuth login/registratie, rollen sync, widget, online leden |
| Twitch | 2 | Live status, stream embed, Helix API |
| World of Warcraft | 3 | Guild roster, Mythic+/raid progress, character profiel — via `BlizzardApiClient` + Raider.IO |
| Guild Management | 3 | Aanmeldingen, leden, teams, rangen, admin |
| Minecraft | 1 | Server status via mcsrvstat.us |
| FiveM | 1 | Server status via FXServer endpoint |
| Ollama AI | 2 | Chat widget, assistent, guild analyse, nieuws samenvatting |
| Google | 0 | OAuth-login/registratie (OpenID Connect), koppelen aan bestaand account |
| Battle.net | 0 | OAuth-login/registratie (regio-gated: eu/us/kr/tw), BattleTag als weergavenaam |
| YouTube | 4 | Kanaalinfo, laatste video's (quota-efficiënt via uploads-playlist), live-status, playlist-embed — API-sleutel, geen OAuth |
| Kick | 2 | Live-status, kijkersaantal, stream-embed — publiek kanaal-endpoint, geen sleutel/OAuth nodig |

Het WoW-module bevat drie native blocks (`WowGuildRosterBlock`, `WowMythicProgressBlock`,
`WowCharacterBlock`) die via `src/Core/Auth`-stijl PDO/Connection-code werken — geen externe
CMS-functies. Een eerdere v1.8.0-changelog-entry claimde een "Guild Roster v2 / Character Armory
v2" uitbreiding; die bestanden bleken 1-op-1 gekopieerde WordPress-code (`$wpdb`, `WP_Error`,
shortcodes) uit de Slayer Alliance Master Suite-plugin, werden nergens door dit framework geladen,
en zijn in v1.9.0 verwijderd. Zie `docs/wave-0-gap-analysis.md` voor de volledige audit.

### Core content-modules (`src/Modules/`, altijd actief)

| Module | Sinds | Highlights |
|---|---|---|
| Users | v1.0.0 | Login/registratie, profiel + avatar-upload, Discord/Twitch OAuth (ook voor nieuwe bezoekers) |
| News | v1.10.0 | Artikelen, categorieën, volledige admin-CRUD (`/admin/news`) |
| Pages | v1.10.0 | Statische CMS-pagina's + menu, volledige admin-CRUD (`/admin/pages`) |
| Forum | v1.9.0 | Borden (gedeelde `cf_categories`), topics, reacties, pin/lock/verwijderen, `forum.post`/`forum.moderate` RBAC — **live end-to-end geverifieerd in v1.14.0**, `/admin/forum/boards` voor bordbeheer toegevoegd |
| Blog | v1.9.0 | Eén blog per lid (`/blog/{username}/{slug}`), draft/published, `blog.moderate` voor moderatie |
| Downloads | v1.9.0 | Bestandsbeheer via `UploadManager::forDownloads()` (zip/pdf/rar/7z/gz), `downloads.manage` |
| Contact | v1.10.0 | Publiek formulier + CSRF + honeypot, admin-inbox, verstuurt meldingsmail via `Mailer` |
| Gallery | v1.23.0 | Media-galerij — albums (`cf_categories`, `type=gallery`), foto/video-upload met GD-miniaturen, publieke doorbladering + lightbox (beide thema's), sidebar-widget, `gallery.manage`. Hergebruikt `/media/{path}` i.p.v. een eigen serveer-route. |

---

## 🗺️ Roadmap

| Sprint | Status | Inhoud |
|---|---|---|
| **S1** | ✅ v1.0.0 | Core Foundation — DI, Router, Auth, Cache, Queue, RBAC |
| **S2** | ✅ v1.1.0 | Web-installer + Admin Dashboard + Frontend |
| **S3** | ✅ v1.2.0 | Block API + Drag & Drop layout |
| **S4** | ✅ v1.3.0 | Discord OAuth + Twitch integratie |
| **S5** | ✅ v1.4.0 | Guild Management + WoW + Minecraft + FiveM |
| **S6** | ✅ v1.5.0 | REST API v1 compleet + Ollama AI + Open WebUI |
| **S7** | ✅ v1.6.0 | Marketplace (install, upload, update, toggle) |
| **S8** | ✅ v1.7.0 | Debug & fixes — 15 bugs opgelost, composer PSR-4 |
| **S9** | ⚠️ v1.8.0 | ~~WoW Module v2 — Guild Roster + Character Armory~~ — **ingetrokken in v1.9.0**: bleek WordPress-code, nooit geladen. Zie CHANGELOG v1.9.0. |
| **S9-audit** | ✅ v1.9.0 | Wave 0 gap-analyse tegen de echte repo, WP-code verwijderd, CI/tests toegevoegd, JWT/APP_KEY gescheiden, Discord-registratie, upload-handler, Forum/Blog/Downloads/Contact core-modules, installer Step5 dynamisch |
| **S9-audit²** | ✅ v1.10.0–v1.11.0 | Wave 2: `/admin` permissie-gating, echte Mailer, News/Pages admin-CRUD, `migrate`/`module:install` CLI-commando's — en een kritieke `LIMIT`/`OFFSET`-bug gevonden door voor het eerst tegen een echte MariaDB-server te testen (zie CHANGELOG v1.11.0) |
| **S9-audit³** | ✅ v1.12.0–v1.19.0 | Wave 3–9: eerste échte end-to-end boot (2 fatale autoload-bugs gevonden), `/admin/users`, Forum live-verificatie + bordbeheer, de laatste 5 placeholder-schermen (Roles/Menus/Logs/Themes/Media — **alle 6 oorspronkelijke placeholder-schermen uit v1.10.0 zijn hiermee vervangen**), het Blokkensysteem (Kernprincipe #3), dat sinds Sprint 1 nog nooit had gewerkt, `/admin/settings` (sitenaam/MOTD/favicon/taal/tijdzone), en tot slot vier losse gaten in één golf: een `$_ENV['APP_URL']`-bug die OAuth-login kon breken, een thema-wissel die letterlijk niets deed, News-categoriebeheer en een instelbaar contact-meldingsadres. Zie CHANGELOG v1.12.0–v1.19.0. |
| **Golf 10** | ✅ v1.20.0 | OAuth-login met Google, Discord, Battle.net en Twitch — generiek instellingenscherm (`/admin/marketplace/package/{slug}/instellingen`) dat werkt voor elke module met een `settings`-schema, échte encryptie voor opgeslagen secrets (`Core\Security\Crypto`, was voorheen een dode `'encrypted'`-kolomwaarde), en twee nieuwe modules (Google, Battle.net). Zie CHANGELOG v1.20.0. |
| **Golf 10a** | ✅ v1.21.0 | YouTube-module — 4 blocks (kanaal, laatste video's, live-status, playlist), quota-bewuste API-client. Zie CHANGELOG v1.21.0. |
| **S10** | ✅ v1.22.0 | Kick-integratie — live-status/kijkers/stream-embed via een publiek kanaal-endpoint (geen sleutel/OAuth nodig, zie CHANGELOG v1.22.0 voor de afweging tegen Kick's officiële Developer API). **Hiermee is S10 (YouTube + Kick) volledig afgerond.** |
| **S11** | ✅ v1.23.0 | Media-galerij — albums (`cf_categories`, `type=gallery`) met foto/video-upload, GD-miniaturen, publieke doorbladering + lightbox (beide thema's), sidebar-widget. Hergebruikt de bestaande `/media/{path}`-serveer-route i.p.v. een nieuwe. Zie CHANGELOG v1.23.0. |
| **S13** | ✅ v1.24.0 | Multi-language/i18n — `Translator` (nl/en/de), publieke + per-gebruiker + site-standaard taalwisseling met volledige prioriteitsketen, `trans()`/`Trans::get()` op de hoogst-verkeer schermen. **S12 (Premium ecosysteem) is bewust vóór S13 geplaatst uitgesteld** ("premium is nu niet belangerijk"). Zie CHANGELOG v1.24.0. |
| **S12** | 📋 Gepland | Premium ecosysteem + licenties + betalingen |
| **Inventarisatie + debug** | ✅ v1.25.0 | Projectbrede audit (2 onafhankelijke passes) + fix- en live-testronde: 2× privilege-escalatie (`/admin/users`, `/admin/roles`), JSON-request-bodies die nergens werden uitgelezen (Blokken-admin/Marketplace/Ollama-chat allemaal stuk), 3 module-adminpanels (Guild/Warcraft/Ollama) volledig onbereikbaar door een routing-volgordebug, login-CSRF op `/api/v1/auth/login`, en meer. Zie CHANGELOG v1.25.0. |
| **Deploy-fix: installer** | ✅ v1.25.1–1.25.2 | De installer bleek op een echte server (buiten de test-omgeving) onbereikbaar — bij `DocumentRoot=public/` (het door dit document aanbevolen model) zelfs een oneindige redirect-loop. Front controller `require`t de installer nu rechtstreeks i.p.v. te redirecten, en de root-`.htaccess` blokkeert `.env`/`config`/`vendor`/etc. expliciet i.p.v. impliciet door te laten — al blokkeerde die lijst in de eerste versie per ongeluk óók `/installer/` zelf ("Forbidden"), gefixt in v1.25.2. Getest via beide document-root-modellen, inclusief een volledige installatie-run door elk. Zie CHANGELOG v1.25.1/v1.25.2. |
| **Branding: logo + welkomstscherm** | ✅ v1.25.3 | Officieel logo verwerkt (`public/assets/img/`), plus een welkomstscherm vóór stap 1 van de installer (logo, uitleg, link om te starten). Logo ook terug te zien in de installer-header, de sitebrede header van beide thema's, en als standaard-favicon na installatie. Zie CHANGELOG v1.25.3. |
| **CI-fix: composer.json** | ✅ v1.25.4 | GitHub Actions faalde op elke push (`composer install` kon niet oplossen): `firebase/php-jwt ^6.0` was volledig geblokkeerd door een security-advisory, `league/route ^5.0` conflicteerde met de vereiste `psr/container`/`psr/simple-cache`-versies, en `league/container`/`league/event`/`monolog/monolog`/`ramsey/uuid` bleken (opnieuw geverifieerd) ongebruikt. Alle zes verwijderd uit `require`; alleen aantoonbaar-gebruikte packages blijven over. Zie CHANGELOG v1.25.4. |
| **Totale codebase-audit + KRITIEK-fix** | ✅ v1.25.5 | Zes parallelle deelaudits (security, architectuur, database, frontend/i18n, module-volledigheid, documentatie). Belangrijkste vondst: rol-priority-escalatie in `/admin/roles` omzeilde de v1.25.0-privilege-fix volledig — een gewone `admin` kon zijn eigen rol een priority van 999 geven en zo alsnog zichzelf `super_admin` toekennen. Gefixt en live getest (exploit-poging geblokkeerd, legitiem gebruik werkt onveranderd). Overige audit-bevindingen staan als backlog in de roadmap hieronder. Zie CHANGELOG v1.25.5. |
| **"GitHub compleet"** | ✅ v1.25.6 | Documentatie-audit opgevolgd: CHANGELOG-ordeningsbreuk gefixt (v1.0.0–v1.7.1 stond oplopend, nu aflopend zoals de rest), `docs/ANALYSE.md` gearchiveerd (was volledig verouderd, niet alleen het 98/100-cijfer), CONTRIBUTING.md/CODE_OF_CONDUCT.md/SECURITY.md + issue-/PR-templates toegevoegd, composer.json-metadata aangevuld (naam/homepage/authors/support). Zie CHANGELOG v1.25.6. |
| **HOOG: stored XSS via blog** | ✅ v1.25.7 | Nog een audit-vondst: elk geregistreerd lid (geen contentpermissie nodig, alleen `$auth`) kon `<script>` in een blogpost zetten die onversleuteld uitvoerde voor elke bezoeker — `blog/show.twig` gebruikte `|raw` op ledencontent, waar News/Pages dat bewust alleen doen voor admin/moderator-content. Nieuwe `nl2br`-Twig-filter (zelf-escapend) i.p.v. `|raw`. Live getest: `<script>`-payload komt geëscaped op de pagina terecht, geen uitvoerbare tag. Zie CHANGELOG v1.25.7. |
| **HOOG: cache-driver `redis` crashte** | ✅ v1.25.8 | Architectuur-deelaudit vond dat `CacheManager` een niet-bestaande `RedisCache`-klasse instantieerde zodra `cache.driver=redis` in `config.php` stond (het voorbeeld-commentaar suggereerde dat als geldige optie) — kale fatal error op elke request. Nu een duidelijke `RuntimeException` i.p.v. de crash; `file`-driver (de enige echt geïmplementeerde) ongewijzigd. Zie CHANGELOG v1.25.8. |
| **KRITIEK: guild/ollama-adminpanels open voor elk lid** | ✅ v1.25.9 | Een gerichte security-herscan vond een vierde instantie van dezelfde bugklasse (module declareert een permissie, niets zaait die ooit in `cf_permissions`): `guild.manage`/`ollama.admin` bestonden nergens, dus draaiden hun admin-routes alleen op `AuthMiddleware` — elk lid kon guild-aanmeldingen goed-/afkeuren en Ollama-host/systeemprompt overschrijven (opstap naar SSRF). Permissies geseed + routes op `PermissionMiddleware` gezet; ook rate limiting toegevoegd aan de voorheen onbeperkte publieke `/api/ollama/*`-endpoints. Live getest: exploit geblokkeerd (403), legitiem gebruik intact. Zie CHANGELOG v1.25.9. |
| **Stappenplan/Roadmap** | ✅ v1.26.0 | Vierde en laatste onderdeel van de sessie-opdracht: `docs/ROADMAP.md` — samenvatting van alle v1.25.x-fixes, de resterende audit-backlog per domein (Security/Architectuur/Database/Frontend-i18n) geprioriteerd op ernst, en een gefaseerd vervolgtraject (fundament verstevigen → S12 Premium → Rust/Ark heroverwegen). Zie CHANGELOG v1.26.0. |
| **HOOG: witte pagina i.p.v. installer** | ✅ v1.26.1 | Gebruikersmelding: na upload naar hosting leek de site "leeg", geen installer bereikbaar. Root cause: `public/index.php` deed een onvoorwaardelijke `require` van `vendor/autoload.php`, vóór de installer-dispatch — ontbrak die map (composer install nooit gedraaid, gangbaar bij een kale ZIP-upload zonder SSH), dan crashte élke request met een volledig leeg wit scherm en geen enkele aanwijzing. Nu een duidelijke Nederlandstalige uitlegpagina met concrete vervolgstappen (SSH of lokaal composer install + upload). Live getest: beide scenario's (ontbrekend/aanwezig) gedragen zich correct. Zie CHANGELOG v1.26.1. |
| **HOOG: mislukte stap 5 brak de installer blijvend** | ✅ v1.26.2 | Gebruikersmelding: installer "liep vast bij opslaan" (Discord+Google geselecteerd), site toonde daarna niets meer. Live gereproduceerd: `installer/steps/Step5.php` schreef `config/config.php` vóór de databasepoging i.p.v. erna — faalde die PDO-stap (verkeerd wachtwoord, weggevallen verbinding), dan stond config.php er al en verdween de installer blijvend uit beeld (`isCompleted()` checkt alleen `file_exists`), terwijl er nooit een geldige installatie had plaatsgevonden. Config-schrijfstap verplaatst naar ná een geslaagde DB-poging. Live getest: mislukte stap 5 laat de installer nu herstartbaar; een geslaagde vervolgpoging werkt normaal. Zie CHANGELOG v1.26.2. |
| **KRITIEK: `getallheaders()` ontbreekt op sommige hosting → elke pagina crashte** | ✅ v1.26.3 | Vervolgmelding van dezelfde gebruiker: nieuwe kale HTTP 500 direct na installatie, zodra de site voor het eerst echt laadt. Live gereproduceerd: `Request::fromGlobals()` riep `getallheaders()` aan zonder fallback — die functie bestaat alleen gegarandeerd onder Apache mod_php/php-fpm, niet onder CLI, de PHP-ingebouwde server (waarmee dit project altijd lokaal is getest, dus de bug bleef tot nu onopgemerkt) of bepaalde CGI/FastCGI-hosting. Een niet-afgevangen `Error`, dus élke request crashte — niet alleen de homepage. Nu een eigen `readHeaders()`-fallback die headers uit `$_SERVER` opbouwt wanneer `getallheaders()` ontbreekt, inclusief een `REDIRECT_HTTP_AUTHORIZATION`-fallback voor CGI-hosting die de Authorization-header normaal wegfiltert. Live getest: volledige installer-flow + homepage + login + admin + marketplace (Discord/Google), allemaal HTTP 200/302 zoals verwacht. Zie CHANGELOG v1.26.3. |

---

## 🔐 Security

- PDO prepared statements overal — geen raw SQL string-concatenatie
- XSS escaping (`htmlspecialchars`) op alle output
- CSRF-token validatie op alle POST requests
- Argon2id wachtwoord hashing
- AES-256-GCM OAuth token encryptie, met een **eigen `JWT_SECRET`** los van `APP_KEY` (sinds v1.9.0)
- Rate limiting (60 req/min per IP) op de REST API
- JWT authenticatie (HS256 + exp validatie)

> Het eerdere "98/100"-cijfer in `docs/ANALYSE.md` was een zelfgerapporteerde regex-telling,
> nooit gedraaid tegen echte tests. Sinds v1.9.0 draait CI (`.github/workflows/ci.yml`) PHPUnit,
> PHPStan level 8 en PHPCS PSR-12 op elke push — zie de CI-badge bovenaan voor de actuele status.

---

## ⚠️ Bekende beperkingen (stand v1.26.3)

Eerlijk overzicht van wat deze doorlopen (Wave 1 + Wave 2) wél en niet hebben opgelost — zie
`docs/wave-0-gap-analysis.md` en `CHANGELOG.md` voor de volledige context per punt.

> **v1.12.0: de allereerste échte end-to-end boot van dit project — en die vond meteen 2 fatale
> bugs die géén enkele eerdere verificatiemethode kon vinden.** Tot v1.12.0 was `public/index.php`
> nog nooit écht gestart: `composer install` kan in geen enkele sandbox waarin dit project tot nu
> toe gebouwd is slagen (`packagist.org` is netwerk-geblokkeerd), dus `vendor/autoload.php`
> bestond nooit. v1.12.0 reconstrueerde die autoloader eenmalig met échte, van GitHub gecloonde
> broncode om een keer écht te kunnen booten — en vond zo (1) dat `psr/container` compleet
> ontbrak in `composer.json` terwijl `Container` het implementeert, wat een schone
> `composer install` altijd fataal had doen crashen op de allereerste regel van elke request, en
> (2) dat alle vier CLI-commando's (`queue:work`, `cache:clear`, `migrate`, `module:install`) al
> sinds Sprint 1 onbereikbaar waren omdat `cli/commands/` nergens in de autoload-map stond. Beide
> zijn gefixt en live geverifieerd (echte login, echte News-CRUD via HTTP, alle 4 CLI-commando's
> daadwerkelijk uitgevoerd). Zie CHANGELOG v1.12.0 voor de volledige verificatie.
>
> **Nog steeds niet opgelost: er is geen `composer.lock` en dus geen bewijs dat een écht
> `composer install` op een normale server exact dezelfde versies pakt** als de handmatig
> gecloonde broncode hier. De code-fixes zelf zijn onafhankelijk daarvan correct (het zijn PSR-4-
> en dependency-declaratiefouten in `composer.json`, geen aannames over een specifieke versie).
>
> v1.11.0 vond en fixte eerder al een aanverwante kritieke bug: `Connection::execute()` bond alle
> parameters als string, waardoor élke `LIMIT ? OFFSET ?`-query faalde tegen een echte
> MySQL/MariaDB-server (tien bestanden, van News/Pages tot de REST API) — ontdekt doordat Wave 2
> voor het eerst tegen een echte, lokaal geïnstalleerde database draaide. Zie CHANGELOG v1.11.0.
>
> **v1.14.0: het Forum-core-module (gebouwd in Wave 1, nooit eerder echt getest) is als eerste
> module deze sessie zonder enige bug door de live end-to-end-verificatie gekomen** — borden,
> topics, reacties, pin/lock/verwijderen en de `forum.moderate`-permissiegrens werkten allemaal
> meteen goed. De verificatie legde wél één echt gat bloot: er was geen manier om een tweede
> forumbord aan te maken zonder rechtstreekse SQL. `/admin/forum/boards` lost dat op, mét
> bescherming tegen het per ongeluk cascade-verwijderen van topics. Hetzelfde soort gat bestaat
> trouwens ook voor News-categorieën — niet meegenomen in deze wave. Zie CHANGELOG v1.14.0.
>
> **v1.15.0: de laatste 5 placeholder-schermen (Media, Roles, Themes, Menus, Logs) zijn
> gebouwd en live geverifieerd — en legden zelf nog eens 4 echte bugs bloot**, waaronder
> een app-brede: elke CSRF-afwijzing (op *elk* formulier, niet alleen de nieuwe schermen)
> kwam als een generieke HTTP 500 naar buiten in plaats van een nette 403, omdat
> `CsrfProtection` niet de `HttpException`-class gebruikte die v1.14.0 voor precies dit
> probleem in `AuthManager::authorize()` had gebouwd. Ook gevonden: een RBAC-cache die na
> een rolwijziging tot 5 minuten stale bleef (zowel in het nieuwe Rollenscherm als,
> bleek bij nader onderzoek, in het al bestaande `/admin/users`), een verkeerd-
> uitgeschakelde permissiematrix voor niet-`super_admin`-rollen, en een SQL-importbug in
> de installer die crashte op een letterlijke puntkomma in een `--`-commentaarregel. Zie
> CHANGELOG v1.15.0 voor de volledige lijst en het live-testrapport per scherm.
>
> **v1.16.0: het hele drag&drop-Blokkensysteem — Kernprincipe #3 van dit project, aanwezig
> sinds Sprint 1 — bleek nog nooit één keer gewerkt te hebben.** Drie samenhangende bugs
> zorgden ervoor dat een blok plaatsen via `/admin/blocks` altijd faalde of, zelfs als het
> in de database belandde, nooit op de site verscheen: (1) de admin-pagina's eigen
> "toevoegen"-knop kon nooit een geldig CSRF-token versturen (zocht een `<meta>`-tag die
> nergens bestond), (2) `BlockRegistry::syncTypesToDatabase()` — al sinds Sprint 1
> aanwezig — werd nérgens aangeroepen, dus `cf_block_types` bleef altijd leeg voor élk
> block-type, ingebouwd én modulair, en (3) de belangrijkste: de Twig-global `zones` stond
> hardcoded op een lege array en werd nooit met échte data gevuld, dus zelfs een correct
> geplaatst blok kon nooit renderen. Alle drie gefixt en live bewezen: een blok toegevoegd
> via de echte admin-UI staat nu daadwerkelijk op de homepage. Bijvangst, eerlijk
> gedocumenteerd: van de 6 layout-zones zijn er nu 2 (linker/rechter sidebar) écht
> drag&drop-baar — header/topmenu/footer zijn nog vaste HTML. Zie CHANGELOG v1.16.0.
>
> **v1.17.0: beide openstaande gaten uit v1.16.0 gedicht.** Alle 6 layout-zones
> (header/topmenu/sidebar-links/content/sidebar-rechts/footer) zijn nu echte
> drag&drop-zones — header/topmenu-blokken krijgen elk hun eigen los-hoge balk náást de
> vaste 64px-header (die anders zou overflowen bij willekeurige blok-inhoud), footer-
> blokken passen binnen de al flexibele footer. En `cf_blocks.visibility_roles` wordt nu
> daadwerkelijk gefilterd — ná de gedeelde 120s-cache i.p.v. erin, om te voorkomen dat één
> bezoekers rol-gefilterde weergave in de gedeelde cache voor iedereen zou belanden. Beide
> live bewezen: alle 3 nieuwe zones tonen een echt geplaatst blok; een rol-beperkt blok is
> tegelijk onzichtbaar voor een anonieme bezoeker en zichtbaar voor de juiste rol. Zie
> CHANGELOG v1.17.0.
>
> **v1.18.0: `/admin/settings` bleek — net als het Blokkensysteem in v1.16.0 — al sinds
> Sprint 1 volledig niet-functioneel.** Geen `SettingsRepository`-injectie, geen `<form>`,
> en de pagina verwees de beheerder naar `config/config.php` (buiten webroot) en
> `/installer/` (bestaat na installatie niet meer). Er bestond dus geen enkele manier om
> de sitenaam, taal of tijdzone na installatie aan te passen zonder rechtstreeks in de
> database te werken — en `site_motd`/`site_icon` bestonden nog nergens, ook niet als
> kolom. Nu een echt, werkend formulier: sitenaam, MOTD/slogan (nieuw, verschijnt onder de
> sitetitel in de header), SEO-omschrijving, website-icoon/favicon (upload via de
> bestaande `UploadManager`, met live voorvertoning en een "verwijderen"-optie die het
> oude bestand ook echt van disk opruimt), standaardtaal en tijdzone. Live bewezen: een
> ingediend formulier met een echte PNG-upload liet zowel de databasewaarden als de
> `<title>`, `<link rel="icon">` en de nieuwe MOTD-regel op de homepage kloppen; CSRF- en
> `settings.edit`-permissiegating gaven allebei correct `403`. Zie CHANGELOG v1.18.0
> (inclusief een noot over hoe deze wave's sandbox-`vendor/` handmatig is samengesteld,
> aangezien `composer install` hier nog altijd geblokkeerd is).
>
> **v1.19.0: vier losse, onafhankelijke gaten in één golf gedicht — deels parallel door
> twee losse subagents in geïsoleerde git-worktrees.** (1) `$_ENV['APP_URL']` bleek niet op
> 2 maar op 4 plekken leeg te zijn — óók in `DiscordOAuthController`/`TwitchOAuthController`'s
> OAuth-`redirect_uri`, wat Discord/Twitch-login met een redirect_uri-mismatch kon laten
> mislukken; één regel toegevoegd aan een al-bewezen config→`$_ENV`-sync-patroon in
> `Application::boot()` fixt alle 4 in één keer. (2) Thema-wissel deed tot nu toe **letterlijk
> niets zichtbaars** — drie samenhangende oorzaken (identieke templates tussen `default`/
> `gaming-dark`, een lege `gaming-dark/assets/css/`, en `asset()` dat nooit naar een
> thema-eigen map verwijst) zorgden ervoor dat `theme.json`'s `colors`-blok wel werd ingelezen
> maar nergens landde; een kleine CSS-custom-property-override in `layout.twig` fixt dat nu
> écht — live bevestigd dat de homepage van paars naar groen/oranje omslaat bij thema-wissel.
> (3) Nieuw `/admin/news/categories`-scherm (hetzelfde gat dat v1.14.0 al voor Forumborden
> dichtte, nu voor News), met een bewuste afwijking van dat Forum-patroon: verwijderen wordt
> nooit geblokkeerd (News-artikelen worden bij een verwijderde categorie alleen ontkoppeld,
> nooit verwijderd — `ON DELETE SET NULL` i.p.v. Forum's `CASCADE`). (4) Het contactformulier
> heeft nu een instelbaar meldingen-e-mailadres via het (sinds v1.18.0 echte) `/admin/settings`
> -scherm. Alle vier live getest tegen een verse installatie. Zie CHANGELOG v1.19.0 voor het
> volledige verhaal, inclusief hoe de twee subagent-opgeleverde delta's gereviewd en
> samengevoegd zijn vóór de gezamenlijke live-testronde.
>
> **v1.20.0: OAuth-login met Google/Discord/Battle.net/Twitch, plus een generiek
> instellingenscherm dat dat soort providerconfiguratie voor het eerst überhaupt beheersbaar
> maakt vanuit de admin-UI.** Vóór deze golf was er geen enkele plek om een `client_id`/
> `client_secret` in te vullen zonder rechtstreeks in `cf_settings` te SQL'en — nu leest
> `/admin/marketplace/package/{slug}/instellingen` het `settings`-schema uit elke module's
> `module.json` en rendert er automatisch een formulier voor, met per provider de exacte
> uitleg waar je de sleutels vandaan haalt en welke redirect-URI je moet whitelisten. Tijdens
> het bouwen kwamen twee bugs in dezelfde familie aan het licht: `cf_settings.type =
> 'encrypted'` bestond al in het schema maar werd nergens gebruikt (een secret zou dus in
> platte tekst zijn opgeslagen), en Discord/Twitch's eigen `getSetting()` zou zo'n
> versleutelde waarde straks ONVERTAALD naar de provider gestuurd hebben. Beide gefixt via een
> nieuwe gedeelde `Core\Security\Crypto`-klasse (AES-256-GCM), en live bevestigd met een
> volledige save→DB→redirect-round-trip. Twee nieuwe modules (Google, Battle.net) zijn door
> twee parallelle subagents gebouwd naar het bestaande Discord-patroon; Twitch kreeg zijn tot
> nu toe ontbrekende "inloggen met"-flow (had alleen "koppelen"). Zie CHANGELOG v1.20.0 voor
> de volledige details, inclusief een derde ontdekte bug (de Marketplace "Geïnstalleerd"-tab
> track niet dezelfde modules als `Application::loadModules()` daadwerkelijk gebruikt).
>
> **v1.21.0: YouTube-module (Golf 10a) — kanaalinfo, laatste video's, live-status en een
> playlist-embed, als vier losse blocks naar het bewezen Twitch-blockpatroon.** De
> laatste-video's-block gebruikt bewust `playlistItems.list` op de uploads-playlist in plaats
> van `search.list` (~100x minder quotakosten voor hetzelfde resultaat); de live-status-check
> kán niet anders dan `search.list` gebruiken (de enige gedocumenteerde manier om live-status
> te detecteren) en cachet daarom 5 minuten i.p.v. Twitch's 90 seconden om de dagelijkse
> quota (10.000 eenheden bij een gratis Google Cloud-project, 100 per live-check) niet snel
> leeg te trekken. Het bestaande generieke instellingenscherm uit v1.20.0 werkte direct, zonder
> extra code. Tijdens het live-testen kwam een pre-existing, latent gat aan het licht: `GET
> /admin/blocks/create` gaf al sinds het bestaan van die route een lege HTTP 200 terug, omdat
> `views/create.php` nooit bestond — onschadelijk gebleven omdat de échte "blok
> toevoegen"-flow een JS-modal gebruikt die de route nooit aanroept. Nu een echt, werkend
> non-JS-formulier. Live bevestigd: alle 4 blocktypes verschijnen in de blocks-picker, zijn
> via de echte store-flow geplaatst en renderen (met nette graceful-degradation-teksten)
> op de homepage; 19-routes regressiesweep zonder breuken. Zie CHANGELOG v1.21.0.
>
> **v1.22.0: Kick-integratie (S10 afgerond) — bewust gebouwd op Kick's publieke kanaal-endpoint
> in plaats van de officiële OAuth-API.** Kick heeft sinds 2024/2025 een officiële, OAuth
> 2.1/PKCE-beveiligde Developer API, maar die is gebouwd voor kanaal-eigenaren die hún eigen
> kanaal beheren — er bestaat geen gedocumenteerd endpoint om een willekeurig kanaal zonder
> diens eigen login op te zoeken, wat nodig is voor een simpel live-status-blok. `KickApi.php`
> gebruikt daarom hetzelfde publieke `kick.com/api/v2/channels/{slug}`-endpoint dat kick.com's
> eigen website intern gebruikt — eerlijk gedocumenteerd als niet-officieel en dus zonder
> garantie. Twee blocks (`kick-live`, `kick-stream`), werkt direct met het generieke
> instellingenscherm uit Golf 10 (één veld, geen sleutel nodig). Live bevestigd: beide blocks
> in de picker, via de echte store-flow geplaatst, nette graceful-degradation op de homepage,
> settingsscherm-roundtrip correct, 19-routes regressiesweep zonder breuken. Zie CHANGELOG
> v1.22.0.
>
> **v1.24.0: Multi-language/i18n (S13) — en twee losstaande, deploy-relevante bugs gevonden
> tijdens het opzetten van de live-testomgeving zelf, vóór er zelfs maar één taalwisseling
> getest was.** (1) De taalwisselaar-route (`/taal/{locale:[a-z]{2}}`) gaf een kale 404 op
> élke aanroep: `Router::compilePattern()`'s eigen placeholder-regex knipt een constraint af
> bij de eerste `}`, dus een `{n}`-quantifier ín een route-constraint breekt de gecompileerde
> regex stil — geen enkele andere route in dit bestand gebruikt die vorm, en nu deze ook niet
> meer (`[a-z]+`). De onderliggende beperking in `compilePattern()` zelf blijft staan voor een
> toekomstige route die dezelfde vorm gebruikt. (2) De root-`.htaccess` herschreef *elke*
> request ongeconditioneerd naar `public/$1` — óók `/installer/`, dat bewust naast `public/`
> staat, waardoor de installatie-URL uit dit README (`/installer/`) in een echte Apache-
> deployment altijd op een 404 zou zijn gestuit. Beide alleen zichtbaar geworden omdat deze
> sprint voor het eerst een scripted install draaide tegen een PHP built-in server mét een
> `.htaccess`-nabootsende router, i.p.v. rechtstreeks tegen `public/`. Daarna volledig
> geverifieerd: gast- én ingelogde-gebruiker-taalwisseling, de volledige vier-staps-
> prioriteitsketen (sessie > gebruiker > sitestandaard > `nl`), de `/admin/settings`
> taal-whitelist-bugfix (accepteerde tot nu toe alleen nl/en, nooit de al langer door de
> installer aangeboden `de`), ontbrekende-sleutel-fallback, open-redirect-/loop-preventie op
> de taalwisselaar, en een 28-routes regressiesweep. **Bewust niet meegenomen:** de meeste
> losse tekstlabels ín de ~26 admin-schermen (buiten sidebar/dashboard — alleen hun
> `<html lang>` is gefixt), de installer zelf, en de losse game/streamer-modules. Zie
> CHANGELOG v1.24.0 voor het volledige verhaal.
>
> **v1.25.0: post-S13-inventarisatie- en debugronde — de zwaarste bugronde sinds v1.12.0's
> eerste échte boot.** Twee onafhankelijke audits (core + extern) leverden 13 bevindingen op,
> waarvan er twee ronduit ernstig waren: **elke `users.manage`- of `roles.manage`-houder (dus
> ook een gewone `admin`, niet alleen `super_admin`) kon zichzelf of een ander stilzwijgend
> tot super_admin maken** — via `/admin/users` (rol direct toewijzen, geen prioriteitscheck)
> óf via `/admin/roles` (de `*`-wildcard aan de `admin`-rol hangen, geen uitsluiting buiten
> `super_admin` zelf). Beide gefixt en live bevestigd: een escalatiepoging wordt nu geweigerd,
> een gewone rolwijziging werkt gewoon door. Daarnaast bleek **`Request::fromGlobals()` nooit
> een JSON-request-body uit te lezen** (PHP vult `$_POST` alleen voor form-urlencoded/
> multipart) — waardoor de Blokken-admin, de Marketplace-admin én de Ollama-chatwidget al
> sinds hun introductie functioneel dood waren ondanks een complete UI, en `/api/v1/auth/login`
> nooit een échte JSON-login kon verwerken. En de grootste verrassing: **de
> `router.routes`-hook (waarmee modules hun eigen routes registreren) vuurde pas ná de
> `/admin/{path}`-catch-all**, waardoor de complete adminschermen van **Guild Management,
> Warcraft en Ollama** sinds hun bestaan onbereikbaar waren — elke klik landde stil op de
> generieke `/admin`-redirect. Verder: login-CSRF op `/api/v1/auth/login` (riep intern gewoon
> de sessie-login aan, ondanks "stateless" te zijn), een ontbrekende CSRF-check op
> blok-herordenen, twee crashes bij een onbereikbare externe server (Battle.net/FiveM —
> `json_decode(false)` onder `strict_types`), Guild Management die 500'de i.p.v. netjes
> degradeerde zolang 'ie niet geïnstalleerd was, en wat opruiming van dode code. Alles live
> getest tegen een echte, opnieuw opgebouwde MariaDB-installatie met twee admin-accounts van
> verschillende `role priority`. Zie CHANGELOG v1.25.0 voor het volledige verhaal per punt.
>
> **v1.25.1: de installer bleek op een écht geüploade server niet te starten.** Alle eerdere
> live-tests draaiden tegen de PHP-ingebouwde server met de projectroot als vertrekpunt — nooit
> tegen een webserver met een écht ingestelde `DocumentRoot`, en dat bleek precies de blinde
> vlek. Onder het door dit document zelf aanbevolen model (`DocumentRoot=public/`, zie §2.1)
> stond `installer/` — bewust naast `public/`, niet erin — buiten de door de webserver
> bereikbare boom. `public/index.php` deed daarna een `header('Location: /installer/')` naar
> een URL die op zo'n server simpelweg niet bestaat; live nagebootst bleek dat geen 404 te
> geven maar een **oneindige redirect-loop** (`ERR_TOO_MANY_REDIRECTS`) — exact het gemelde
> "de installer start niet". Fix: de front controller `require`t `installer/index.php` nu
> rechtstreeks in-place i.p.v. te redirecten (het bestand blijft op zijn eigen plek staan, dus
> zijn eigen padberekeningen blijven kloppen), en de root-`.htaccess` (voor het andere,
> minder ideale model `DocumentRoot=projectroot`) blokkeert `.env`/`config/`/`storage/`/
> `vendor/`/`composer.json` nu expliciet i.p.v. — zoals voorheen, om `installer/` bereikbaar te
> houden — alles ongefilterd door te laten. Beide document-root-modellen zijn hierna elk apart
> met een volledige installatie-run (stap 1 t/m 5, echte MariaDB) getest. Zie CHANGELOG v1.25.1.
>
> **v1.25.2: die v1.25.1-fix blokkeerde zelf per ongeluk `/installer/`.** Direct na v1.25.1 een
> live "Forbidden"-melding — de nieuwe root-`.htaccess`-blokkeerlijst (bedoeld voor
> `.env`/`config`/`storage`/`vendor`) matchte ook letterlijk op het pad `installer` zelf, dus
> een rechtstreeks bezoek aan `/installer/` (precies de URL die dit document als
> installatie-instructie geeft) kreeg een 403 nog vóórdat de request `public/index.php` ooit
> bereikte. Bij het v1.25.1-testen was dit gemist: wél `/installer/index.php` rechtstreeks
> getest, nooit `/installer/` zonder bestandsnaam. Fix: `installer` uit die blokkeerlijst
> gehaald — er zit geen geheim achter, `installer/index.php` beschermt zichzelf al na
> installatie. Opnieuw live getest, inclusief een volledige installatie-run via exact de
> `/installer/?step=N`-URL's onder `DocumentRoot=projectroot`. Zie CHANGELOG v1.25.2.
>
> **v1.25.3: logo verwerkt + welkomstscherm vóór de installer.** Het officiële logo staat nu in
> `public/assets/img/` en is verwerkt op de plekken waar het zichtbaar effect heeft: een nieuw
> welkomstscherm (`installer/templates/welcome.php`) dat vóór stap 1 toont — logo, korte uitleg,
> knop om te starten — de installer-wizard-header zelf, de sitebrede header van beide thema's,
> en als standaard-favicon (`core.site_icon`) die Step5.php nu meteen seedt i.p.v. leeg te laten
> tot een beheerder er zelf een uploadt. In de installer zelf staat het logo als data-URI ingebed
> (niet gelinkt), want die draait vóórdat zeker is dat `/assets/` via de actieve document root
> bereikbaar is. Zie CHANGELOG v1.25.3.
>
> **v1.25.4: CI-pipeline weer groen.** `composer install` faalde op elke push — `firebase/php-jwt`
> was volledig onoplosbaar (alle v6.x-releases geblokkeerd door security-advisory PKSA-y2cr-5h3j-g3ys,
> pas gefixt in v7.0.0) en `league/route ^5.0` conflicteerde met de vereiste `psr/container`/
> `psr/simple-cache`-versies. Beide, plus vier andere nooit-gebruikte packages
> (`league/container`, `league/event`, `monolog/monolog`, `ramsey/uuid`), verwijderd uit
> `composer.json`. Geen enkele class verandert — puur een opschoning van dode/kapotte
> dependency-declaraties. Zie CHANGELOG v1.25.4.
>
> **v1.25.5: totale codebase-audit — KRITIEK rol-priority-escalatie gevonden en gefixt.** Zes
> parallelle deelaudits (security, architectuur, database, frontend/i18n, module-volledigheid,
> documentatie) op de volledige repo. Belangrijkste vondst: `/admin/roles` accepteerde een
> `priority`-veld zonder boven-grens, waarmee een gewone `admin` (niet `super_admin`) zijn eigen
> rol naar bv. priority 999 kon zetten — en zo de v1.25.0-privilege-fix in `UserAdminController`
> (die roltoewijzing blokkeert boven je eigen priority) volledig omzeilde: eerst je eigen rol
> optillen, dan gewoon `super_admin` aan jezelf toewijzen. `RoleAdminController::store()`/
> `update()` passen nu dezelfde priority-grens toe. Live getest: exploit-poging geblokkeerd
> (redirect met foutmelding, DB-waarde ongewijzigd), legitiem gebruik door `super_admin`
> onveranderd. Zie CHANGELOG v1.25.5.
>
> **v1.25.6: "maak de github compleet."** Opvolging van de Documentatie-deelaudit:
> `CHANGELOG.md` had een echte ordeningsbreuk (v1.0.0–v1.7.1 stond oplopend tussen twee
> aflopende blokken) — rechtgezet. `docs/ANALYSE.md` (stand v1.7.0, ruim verouderd) heeft nu
> een duidelijke archief-banner in plaats van als actuele status over te komen. Toegevoegd:
> `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`,
> `.github/ISSUE_TEMPLATE/{bug_report,feature_request}.md`, `.github/PULL_REQUEST_TEMPLATE.md`.
> `composer.json` kreeg `homepage`/`authors`/`support` en een package-naam die matcht met de
> "Blueprint CMS"-branding (`dieouwe/blueprint-cms` i.p.v. het oude `communityfusion/cms` — de
> PSR-4-namespace `CommunityFusion\` zelf is ongewijzigd). Zie CHANGELOG v1.25.6.
>
> **v1.25.7: HOOG — stored XSS via blogposts gefixt.** `blog/show.twig` gebruikte `|raw` op
> blogpost-inhoud, en die route is alleen `$auth`-gated — elk geregistreerd lid, geen
> contentpermissie zoals News (`news.create`) of Pages (`pages.manage`) wél hebben. Een lid kon
> dus `<script>` in een post zetten die voor elke bezoeker (incl. beheerders) uitvoerde. Fix:
> nieuwe zelf-escapende `nl2br`-Twig-filter i.p.v. `|raw` — News/Pages blijven bewust
> ongewijzigd (daar is `|raw` terecht, vertrouwde rollen). Live getest: een
> `<script>`-payload komt geëscaped op de pagina terecht (`&lt;script&gt;…`), regeleinden
> blijven behouden als `<br />`. Zie CHANGELOG v1.25.7.
>
> **v1.25.8: HOOG — `cache.driver=redis` gaf een kale fatal error.** `CacheManager` instantieerde
> een `RedisCache`-klasse die nergens bestaat, zodra `redis` als driver in `config.php` stond —
> nergens selecteerbaar via installer/admin-UI, maar het voorbeeld-commentaar suggereerde het
> wel als geldige optie. Nu een duidelijke `RuntimeException` i.p.v. de crash. Geen
> Redis-implementatie toegevoegd (buiten scope) — dit maakt de beperking expliciet. Zie
> CHANGELOG v1.25.8.
>
> **v1.25.9: KRITIEK — guild/ollama-adminpanels open voor elk lid.** Een gerichte
> security-herscan vond een vierde instantie van dezelfde bugklasse als hierboven: `module.json`
> declareert een permissie (`guild.manage`, `ollama.admin`), niets zaait die ooit in
> `cf_permissions`, dus draaiden de admin-routes op kale `AuthMiddleware`. Elk lid kon
> guild-aanmeldingen goed-/afkeuren en de Ollama-host/systeemprompt overschrijven — een opstap
> naar SSRF via de publieke chat-endpoint. Permissies geseed + routes op `PermissionMiddleware`
> gezet. Ook: `RateLimitMiddleware` toegevoegd aan de voorheen volledig onbeperkte publieke
> `/api/ollama/*`-endpoints (bewust géén inlogvereiste — de chat-widget is ook voor
> niet-ingelogde bezoekers bedoeld). Live getest: `member` krijgt `403` op beide exploitpogingen
> (DB-status ongewijzigd), `admin` behoudt volledige toegang. Zie CHANGELOG v1.25.9.
>
> **v1.26.0: het stappenplan.** Het vierde en laatste onderdeel van de sessie-opdracht —
> `docs/ROADMAP.md` bundelt wat hierboven al staat (v1.25.5–v1.25.9) met de resterende
> audit-backlog die bewust niet in de fix-ronde meeging: geen login-brute-force-bescherming,
> rate limiting die bijna geen publieke POST-route dekt, CSRF-gat op OAuth-disconnect
> (Security); een volledig ongebruikt queue-systeem en inconsistente error-handling
> (Architectuur); geen ALTER-capable migratierunner en een cascade-landmine op
> `cf_forum_topics.author_id` (Database); i18n-dekking in slechts ~4 van 25 templates en een
> `gaming-dark`-thema zonder eigen templates (Frontend). Geen van deze is deze sessie gefixt —
> ze staan expliciet als geprioriteerde backlog in het stappenplan, met een voorgestelde fasering
> voor de volgende ontwikkelronde. Zie CHANGELOG v1.26.0 en `docs/ROADMAP.md`.
>
> **v1.26.1: HOOG — witte pagina i.p.v. installer wanneer `composer install` niet is
> uitgevoerd.** Een gebruiker meldde dat de site na upload naar hosting "leeg" leek, zonder
> installer. Root cause: `public/index.php` deed een onvoorwaardelijke `require` van
> `vendor/autoload.php` vóór de installer-dispatch — ontbreekt die map (heel gebruikelijk bij
> een kale GitHub-ZIP zonder `composer install`, bijvoorbeeld op shared hosting zonder
> SSH-toegang), dan crashte élke request meteen, en met `display_errors=Off` (de standaard op
> praktisch elke productie-host) zonder enige zichtbare foutmelding. Nu een `file_exists()`-check
> met een duidelijke Nederlandstalige uitlegpagina (concrete vervolgstappen voor zowel
> SSH-toegang als pure FTP-hosting) i.p.v. de kale crash. Live getest: beide scenario's
> (`vendor/` ontbrekend/aanwezig) gedragen zich correct, geen regressie op de bestaande
> installer-flow. Zie CHANGELOG v1.26.1.
>
> **v1.26.2: HOOG — mislukte installatiestap 5 brak de installer blijvend.** Een gebruiker
> meldde dat de installer "vastliep bij opslaan" met Discord+Google geselecteerd, en de site
> daarna niets meer toonde. Live gereproduceerd tegen echte MariaDB: `installer/steps/Step5.php`
> schreef `config/config.php` vóór de databasepoging i.p.v. erna — faalde die stap (verkeerd
> wachtwoord, weggevallen verbinding, een tijdelijke storing), dan stond config.php er al en
> verdween de installer blijvend uit beeld (`isCompleted()` checkt alleen `file_exists`), terwijl
> er nooit een geldige installatie had plaatsgevonden. De enige uitweg was config.php handmatig
> via FTP verwijderen. Config-schrijfstap verplaatst naar ná een geslaagde DB-poging. Live
> getest: een mislukte stap 5 laat de installer nu gewoon herstartbaar, een geslaagde
> vervolgpoging werkt normaal. Zie CHANGELOG v1.26.2.
>
> **v1.26.3: KRITIEK — `getallheaders()` ontbreekt op sommige hosting, elke pagina na
> installatie crashte.** Vervolgmelding van dezelfde gebruiker: een nieuwe kale HTTP 500, dit
> keer zodra de site voor het eerst écht laadt (direct na de installer). Live gereproduceerd:
> `Request::fromGlobals()` riep `getallheaders()` aan zonder fallback. Die functie is alleen
> gegarandeerd beschikbaar onder Apache mod_php en php-fpm — niet onder CLI, niet onder de
> PHP-ingebouwde server (de testmethode die dit hele project altijd heeft gebruikt, dus deze bug
> bleef tot nu verborgen), en niet op een deel van gedeelde/budget-hosting (CGI, suPHP, bepaalde
> FastCGI-pools). Een niet-afgevangen `Error`, dus letterlijk elke request crashte, niet alleen de
> homepage. Fix: een eigen `readHeaders()` die headers uit `$_SERVER` opbouwt zodra
> `getallheaders()` ontbreekt, plus een `REDIRECT_HTTP_AUTHORIZATION`-fallback voor CGI-hosting
> die de Authorization-header wegfiltert (anders zou elke JWT/Bearer-API-call daar sowieso al
> stil blijven falen). Live getest: volledige installer-flow + eerste homepage-load + login +
> admin + marketplace (Discord/Google) — allemaal de verwachte 200/302, geen crash meer. Zie
> CHANGELOG v1.26.3.

- ~~`/admin`-routes zijn niet permissie-gated~~ — **opgelost in v1.10.0.** Zie CHANGELOG:
  `PermissionMiddleware` + `admin.access`/`settings.edit`/`blocks.manage`/`marketplace.*`.
- ~~De admin-sidebar bevat dode links~~ — **opgelost in v1.10.0** (geen 404's meer) **en
  gedeeltelijk écht afgebouwd in v1.13.0.** `/admin/news` en `/admin/pages` hebben volledige
  admin-CRUD sinds v1.10.0. `/admin/users` is sinds **v1.13.0** een echt, live-geteste
  scherm (lijst + zoeken + rollen toewijzen + activeren/deactiveren + zelf-lockout-
  bescherming — zie CHANGELOG v1.13.0). `/admin/modules` redirect naar `/admin/marketplace`.
  `/admin/forum/boards` is sinds **v1.14.0** eveneens een echt, live-geteste
  scherm (aanmaken/hernoemen/herordenen/verwijderen, met bescherming tegen het
  cascade-verwijderen van topics — zie CHANGELOG v1.14.0). Sinds **v1.15.0** zijn ook
  `/admin/roles` (permissiematrix, vergrendelde `super_admin`-wildcard, cascade-
  bescherming), `/admin/menus` (pagina's toevoegen/verwijderen/herordenen),
  `/admin/logs` (gepagineerde, filterbare auditlog — nieuwe `cf_audit_log`-tabel +
  `AuditLogger`), `/admin/themes` (echte omschakeling tussen "default" en het nieuwe
  "Gaming Dark"-thema, via een DB-instelling) en `/admin/media` (scant
  `storage/uploads/` + `storage/downloads/`, blokkeert verwijderen van bestanden die nog
  als avatar of download in gebruik zijn) echte, live-geteste schermen — zie CHANGELOG
  v1.15.0. **Alle 6 oorspronkelijke placeholder-schermen uit v1.10.0 zijn hiermee
  vervangen.**
  Nog wel een bekend gat: themawissel wisselt de Twig-templates, niet (nog) een
  kleurenschema (`theme.json`'s `colors`-blok wordt nergens toegepast — zie CHANGELOG
  v1.15.0), en er is nog geen beheerscherm voor News-categorieën (zie v1.14.0).
- ~~Het Blokkensysteem (drag & drop) werkte niet~~ — **opgelost in v1.16.0.** Drie
  samenhangende bugs (ontbrekend CSRF-token op `/admin/blocks`, `cf_block_types` nooit
  gesynchroniseerd, de Twig-global `zones` hardcoded leeg) zorgden ervoor dat een geplaatst
  blok nog nooit op de site was verschenen — zie CHANGELOG v1.16.0 voor het volledige
  verhaal. ~~Nog wel een bekend gat: alleen 2 van de 6 zones waren dynamisch, en
  `visibility_roles` werd niet gefilterd~~ — **beide opgelost in v1.17.0.** Alle 6
  layout-zones (Header, Top Menu, Linker Sidebar, Content, Rechter Sidebar, Footer) zijn nu
  echte drag&drop-zones, en een geplaatst blok respecteert nu daadwerkelijk zijn
  rol-zichtbaarheid — zie CHANGELOG v1.17.0.
- ~~Contact verstuurt geen e-mail~~ — **opgelost in v1.10.0** (`Mailer`, raw-socket SMTP + `mail()`-
  fallback). Wel nog geen instelbaar "meldingen naar"-adres via de admin-UI — de mail gaat naar het
  geconfigureerde afzenderadres zelf. SMTP zelf heeft ook nog geen installer-veld; vul `MAIL_HOST`
  e.a. in `.env` in vóór je de installer draait (zie `.env.example`).
- ~~`migrate`/`module:install` CLI-commando's ontbreken~~ — **opgelost in v1.11.0.** Beide
  gebouwd en écht getest tegen een lokale MariaDB-server (zie CHANGELOG). Bijvangst: dit legde
  ook bloot dat `queue:work` al sinds Sprint 1 stuk was (`Application::boot()` was `private`,
  nu `public`) — ook gefixt.
- ~~`/admin/settings` was volledig statisch — geen manier om sitenaam/taal/tijdzone na
  installatie te wijzigen zonder directe SQL~~ — **opgelost in v1.18.0.** Echt formulier
  met sitenaam, MOTD/slogan (nieuw — verschijnt onder de sitetitel in de header),
  SEO-omschrijving, website-icoon/favicon-upload (met opruiming van het oude bestand),
  standaardtaal en tijdzone — zie CHANGELOG v1.18.0.
- ~~`$_ENV['APP_URL']` was leeg op 4 plekken, incl. Discord/Twitch OAuth-`redirect_uri`~~ —
  **opgelost in v1.19.0.** Zie CHANGELOG voor de volledige uitleg; niet live tegen een echte
  Discord/Twitch-app-registratie te verifiëren in deze sandbox.
- ~~Thema-wissel wisselde alleen de Twig-templates, niet een kleurenschema~~ — **opgelost in
  v1.19.0.** Bleek bij nader onderzoek erger dan gedacht (thema-wissel deed helemaal niets
  zichtbaars) — nu fixt een CSS-custom-property-override op basis van `theme.json`'s
  `colors`-blok dit écht, live bevestigd. Zie CHANGELOG v1.19.0.
- ~~Geen beheerscherm voor News-categorieën~~ — **opgelost in v1.19.0.** `/admin/news/categories`,
  zelfde patroon als Forumborden (v1.14.0), met een bewuste afwijking: verwijderen ontkoppelt
  artikelen in plaats van ze te blokkeren of te verwijderen. Zie CHANGELOG v1.19.0.
- ~~Contactformulier had geen instelbaar meldingen-e-mailadres~~ — **opgelost in v1.19.0.**
  Nieuw veld in het (sinds v1.18.0 echte) `/admin/settings`-scherm. Zie CHANGELOG v1.19.0.
- ~~Alleen inloggen met Discord (koppelen), geen Google/Battle.net, geen "inloggen met
  Twitch"~~ — **opgelost in v1.20.0.** Alle vier providers werken nu voor zowel login/
  accountaanmaak als koppelen aan een bestaand account.
- ~~Geen enkele admin-UI om een module's `client_id`/`client_secret` in te vullen — alleen
  via kale SQL op `cf_settings`~~ — **opgelost in v1.20.0.** Generiek instellingenscherm
  (`/admin/marketplace/package/{slug}/instellingen`) leest elke module's `module.json`-schema.
- ~~`cf_settings.type = 'encrypted'` bestond in het schema maar werd nooit gebruikt — een
  opgeslagen client_secret zou in platte tekst hebben gestaan~~ — **opgelost in v1.20.0.**
  Nieuwe gedeelde `Core\Security\Crypto`-klasse (AES-256-GCM); `SettingsRepository` en
  Discord/Twitch's `getSetting()` versleutelen/ontsleutelen nu écht. Zie CHANGELOG v1.20.0
  voor waarom dit pas tijdens het bouwen van het instellingenscherm aan het licht kwam.
- Nog niet live tegen echte Discord/Twitch/Google/Battle.net-app-registraties (of een echte
  YouTube Data API-sleutel) te verifiëren in deze sandbox (geen uitgaand verkeer naar
  willekeurige domeinen) — de redirect-opbouw, encryptie-roundtrip en callback-dispatch zijn
  wel volledig live geverifieerd (zie CHANGELOG v1.20.0/v1.21.0).
- ~~`GET /admin/blocks/create` gaf een lege HTTP 200 (ontbrekende view)~~ — **opgelost in
  v1.21.0.** `views/create.php` bestond nooit; onschadelijk gebleven omdat de echte
  "blok toevoegen"-flow (JS-modal) deze route nooit aanroept. Zie CHANGELOG v1.21.0.
- ~~Kick-integratie was nog niet gebouwd~~ — **opgelost in v1.22.0.** `kick-live`/
  `kick-stream`-blocks, gebouwd op Kick's publieke kanaal-endpoint (geen sleutel/OAuth nodig).
- **Kick's live-status/stream-embed draait op een niet-officieel, ongeauthenticeerd endpoint
  (`kick.com/api/v2/channels/{slug}`)**, niet op Kick's officiële OAuth-Developer-API — zie
  CHANGELOG v1.22.0 voor de volledige afweging. Kan door Kick zonder aankondiging gewijzigd
  worden; niet live tegen een echt Kick-kanaal te verifiëren in deze sandbox (geen uitgaand
  verkeer naar willekeurige domeinen) — de iframe-embed-opbouw en graceful-degradation zijn
  wel volledig live geverifieerd.
- **Module-specifieke extra tabellen** (bv. `cf_discord_role_mapping`) worden niet direct
  aangemaakt wanneer je een module in installer-stap 5 selecteert — de installer laadt bewust
  geen framework-klassen. Ze ontstaan zodra een beheerder de module later via
  `/admin/marketplace` (opnieuw) activeert.
- **Geen `composer.lock`.** `packagist.org` was niet bereikbaar in de sandbox waarin dit werk
  is gedaan — PHPUnit/PHPStan/PHPCS zijn dus nooit lokaal gedraaid. Verificatie liep via
  `php -l` op elk bestand, handmatige smoke-test scripts tegen de echte klassen (`JWTManager`,
  `HookManager`, `CsrfProtection`, `UploadManager`), sinds v1.11.0 ook tegen een echte,
  apt-geïnstalleerde lokale MariaDB-server, en sinds **v1.12.0 ook tegen de échte
  `public/index.php`/`cli/console.php` entry points zelf**, via een eenmalig met de hand
  samengestelde `vendor/` van ongewijzigde, van GitHub gecloonde broncode (niet gecommit — zie
  CHANGELOG v1.12.0). Dat vond meteen twee fatale `composer.json`-fouten die zelfs de MariaDB-
  test niet kon zien. CI draait de echte suite (zie hierboven) zodra de eerste `composer install`
  het lockfile committed — op een server met normale internettoegang zou dat nu moeten werken,
  maar dat is in geen enkele sandbox tot nu toe zelf getest kunnen worden.
- ~~Geen media-galerij — alleen de generieke `UploadManager` en het `/admin/media`-
  huishoudscherm~~ — **opgelost in v1.23.0.** Albums, upload met GD-miniaturen, publieke
  doorbladering + lightbox, sidebar-widget. Zie CHANGELOG v1.23.0.
- **Media-galerij: alleen staff-curated**, geen lid-uploads of moderatiewachtrij (zelfde model
  als Downloads/News) — geen per-item zichtbaarheids-toggle in de UI (`is_published` bestaat in
  het schema, maar S11-upload is altijd direct zichtbaar).
- **Media-galerij: video-items hebben geen miniatuur** (vaste ▶️-placeholder) — een echte
  frame-thumbnail zou `ffmpeg` of een vergelijkbare decoder vereisen, een procesafhankelijkheid
  die dit project bewust nergens anders heeft. Zie CHANGELOG v1.23.0.

---

## 🖥️ CLI Tools

```bash
php cli/console.php queue:work                      # Queue worker starten
php cli/console.php cache:clear                      # Cache wissen
php cli/console.php migrate                           # DB-schema (opnieuw) importeren
php cli/console.php module:install <slug> [--url=…]  # Module installeren zonder /admin
```

> `migrate` en `module:install` stonden al sinds v1.0.0 in de `console.php`-help maar waren
> nooit gebouwd — sinds **v1.11.0** bestaan beide en zijn ze getest tegen een echte lokale
> MariaDB-server (zie CHANGELOG v1.11.0). `migrate` is idempotent: opnieuw draaien tegen een
> al gevulde database slaat bestaande tabellen én seed-rijen netjes over. `module:install`
> hergebruikt dezelfde `PackageManager`-service als `/admin/marketplace`, zonder de
> HTTP-only RBAC-check (shell-toegang tot de server is hier de vertrouwensgrens).

---

## 🤝 Bijdragen

Zie [CONTRIBUTING.md](CONTRIBUTING.md) voor ontwikkelomgeving, codestijl en de PR-workflow, en
[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) voor de gedragscode. Een kwetsbaarheid gevonden? Meld
die via [SECURITY.md](SECURITY.md) — **niet** via een publieke issue.

## 📄 Licentie

GPL-3.0-or-later — © 2026 [DieOuwe](https://www.dieouwe.nl) / [Slayer Alliance](https://www.slayeralliance.com)

<div align="center">

🌐 [www.dieouwe.nl](https://www.dieouwe.nl) &nbsp;·&nbsp;
⚔️ [www.slayeralliance.com](https://www.slayeralliance.com) &nbsp;·&nbsp;
📦 [CurseForge](https://curseforge.com/members/dieouwe/projects) &nbsp;·&nbsp;
💬 [Discord](https://discord.gg/y8Pu5qsEbQ)

</div>

<!--
╔══════════════════════════════════════════════════════════════════════╗
║  File: README.md | Role: Docs | Version: 1.26.3                      ║
║  Updated: 2026-09-30 — KRITIEK: getallheaders() bestaat niet op elke ║
║           SAPI/hosting — Request::fromGlobals() crashte daardoor op  ║
║           élke pagina-load. Nu een $_SERVER-gebaseerde fallback.     ║
╚══════════════════════════════════════════════════════════════════════╝
-->
