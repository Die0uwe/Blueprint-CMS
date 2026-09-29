<!--
============================================================================
Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
GPL-3.0-or-later
============================================================================
-->

<div align="center">

# 🔮 Blueprint CMS

**Modulair PHP 8.3+ Community CMS voor gaming, streamers & gilden**

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php)](https://php.net)
[![MariaDB](https://img.shields.io/badge/MariaDB-10.11%2B-003545?style=flat-square&logo=mariadb)](https://mariadb.org)
[![License](https://img.shields.io/badge/License-GPL--3.0-blue?style=flat-square)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.20.0-brightgreen?style=flat-square)](CHANGELOG.md)
[![CI](https://img.shields.io/github/actions/workflow/status/Die0uwe/bluprint-cms/ci.yml?branch=main&style=flat-square&label=CI)](.github/workflows/ci.yml)

*Geïnspireerd door PHP-Fusion · Down Under Fusion · ImpressCMS*

[📊 Analyse Rapport](docs/ANALYSE.md) · [🔍 Wave 0/1 Gap-analyse](docs/wave-0-gap-analysis.md) · [🖼️ Frontpage Mockup](docs/MOCKUP-FRONTPAGE.html) · [🏗️ Architectuur](docs/assets/architecture.md)

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

---

## 🧩 Modules (7 beschikbaar)

| Module | Blocks | Highlights |
|---|---|---|
| Discord | 2 | OAuth login/registratie, rollen sync, widget, online leden |
| Twitch | 2 | Live status, stream embed, Helix API |
| World of Warcraft | 3 | Guild roster, Mythic+/raid progress, character profiel — via `BlizzardApiClient` + Raider.IO |
| Guild Management | 3 | Aanmeldingen, leden, teams, rangen, admin |
| Minecraft | 1 | Server status via mcsrvstat.us |
| FiveM | 1 | Server status via FXServer endpoint |
| Ollama AI | 2 | Chat widget, assistent, guild analyse, nieuws samenvatting |

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
| **S10** | 📋 Gepland | YouTube + Kick integratie |
| **S11** | 📋 Gepland | Media-galerij (los van de generieke upload-handler) |
| **S12** | 📋 Gepland | Premium ecosysteem + licenties + betalingen |
| **S13** | 📋 Gepland | Multi-language / i18n volledige implementatie |

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

## ⚠️ Bekende beperkingen (stand v1.20.0)

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
- Nog niet live tegen echte Discord/Twitch/Google/Battle.net-app-registraties te verifiëren
  in deze sandbox (geen uitgaand verkeer naar willekeurige domeinen) — de redirect-opbouw,
  encryptie-roundtrip en callback-dispatch zijn wel volledig live geverifieerd (zie
  CHANGELOG v1.20.0).
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
║  File: README.md | Role: Docs | Version: 1.9.0                       ║
║  Updated: 2026-09-28 — Wave 1: Forum/Blog/Downloads/Contact, RBAC-   ║
║           seeding, Twig-globals, installer Step5 dynamisch           ║
╚══════════════════════════════════════════════════════════════════════╝
-->
