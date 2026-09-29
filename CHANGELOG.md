<!--
============================================================================
Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)

This work is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.
============================================================================
-->

# Changelog — Blueprint CMS

Alle noemenswaardige wijzigingen worden in dit bestand bijgehouden.

Format gebaseerd op [Keep a Changelog](https://keepachangelog.com/nl/1.0.0/).
Versienummering volgt [Semantic Versioning](https://semver.org/lang/nl/).

---

## [1.25.4] — 2026-09-29 — CI kapot: composer.json vroeg om zes ongebruikte/onoplosbare packages

Aanleiding: gebruiker plakte de GitHub Actions-foutmelding van de CI-pipeline (PHP 8.3 én 8.4
jobs, beide rood op de `composer install`-stap, nog vóórdat er ook maar één test kon draaien).

**Root cause:** `composer.json` `require` vroeg om zes packages die stuk voor stuk problematisch
óf overbodig bleken:

- `firebase/php-jwt: ^6.0` — **onoplosbaar.** Composer kan geen enkele v6.x-release
  installeren: alle releases van v6.0.0 t/m v6.11.1 zijn geblokkeerd door een
  security-advisory (`PKSA-y2cr-5h3j-g3ys`, ook bekend als GHSA-2x45-7fc3-mxwq — een
  "weak encryption"-probleem, CWE-326, pas gefixt in v7.0.0). `^6.0` in composer.json kan dus
  per definitie nooit meer oplossen.
- `league/route: ^5.0` — **conflicteert.** v5.x vereist `psr/container: ^1.0` en
  `psr/simple-cache: ^1.0`, terwijl root expliciet `psr/container: ^2.0` en
  `psr/simple-cache: ^3.0` vraagt (nodig voor `src/Core/Cache/*` en `src/Core/Container.php`,
  die wél echt gebruikt worden). Onoplosbare versie-tegenspraak.
- `league/container`, `league/event`, `monolog/monolog`, `ramsey/uuid` — **ongebruikt.**
  Geverifieerd met een volledige grep over `src/`, `modules/`, `cli/`, `tests/`: geen enkele
  `use`-statement of namespace-verwijzing naar een van deze vier pakketten. Al genoemd als
  "bewust buiten scope" in de v1.25.0-changelog-entry, maar toen niet daadwerkelijk uit
  composer.json verwijderd.

`firebase/php-jwt` bleek bij nader inzien óók ongebruikt: `src/Core/Auth/JWTManager.php`
implementeert HS256 JWT-signing zelf, met `hash_hmac('sha256', ...)` en een eigen
base64url-encode/decode — geen `Firebase\JWT`-namespace-verwijzing waar dan ook in de codebase.

### 🔧 Fix

`require` teruggebracht tot exact wat er echt gebruikt wordt:

```diff
- "firebase/php-jwt": "^6.0",
- "league/container": "^4.0",
- "league/event": "^3.0",
- "league/route": "^5.0",
  "psr/simple-cache": "^3.0",
  "psr/container": "^2.0",
- "monolog/monolog": "^3.0",
  "vlucas/phpdotenv": "^5.0",
- "ramsey/uuid": "^4.0"
```

Blijft over: `twig/twig`, `psr/simple-cache`, `psr/container`, `vlucas/phpdotenv` — stuk voor
stuk aantoonbaar in gebruik (Twig-templates via `ThemeManager`, PSR-16-cache via
`FileCache`/`CacheManager`, PSR-11-container via `src/Core/Container.php`, `.env`-laden via
`vlucas/phpdotenv`). Geen enkele class in de codebase verandert door deze wijziging — puur
opschonen van dode/kapotte dependency-declaraties. `composer validate --strict` en
`json_decode()` beide schoon getest op het resulterende bestand.

---

## [1.25.3] — 2026-09-29 — Logo + welkomstscherm vóór de installer

Aanleiding: gebruiker leverde het officiële Blueprint CMS-logo aan met het verzoek om, vóórdat
de installer-wizard start, eerst een informatief tussenscherm te tonen (met het logo en een
link om door te klikken), en het logo verder te verwerken waar het zinnig is.

### ✨ Nieuw

- **Welkomstscherm vóór stap 1.** Een verse bezoeker die de site voor het eerst opent (geen
  `?step=`-parameter, nog geen sessie) ziet nu eerst `installer/templates/welcome.php`: logo,
  een korte uitleg van wat de installer gaat doen, een featurelijstje, en een knop
  "Installatie starten →" die naar stap 1 linkt. Doorklikken zet
  `$_SESSION['installer']['started']`, dus een latere `?step=`-loze herlaad (bv. de gebruiker
  wist de querystring handmatig) valt terug op de huidige stap, niet opnieuw op dit scherm.
  `InstallerCore::isCompleted()`s bestaande gedrag (na installatie altijd doorsturen naar `/`)
  blijft ervoor staan, dus dit scherm is sowieso nooit zichtbaar op een al-geïnstalleerde site.
- **Logo-bestanden toegevoegd** onder `public/assets/img/`: `logo.png` (bronbestand, hoge
  resolutie), `logo-256.png` (UI-gebruik), `logo-64.png` (header-icoon) en `favicon-32.png`.
- **Logo verwerkt waar het paste:**
  - Welkomstscherm en de installer-wizard-header (stap 1–5) — als data-URI ingebed (niet als
    `<img src="/assets/...">` gelinkt), omdat dit draait vóórdat zeker is dat `/assets/`
    via de actieve document root bereikbaar is — precies de klasse fouten die v1.25.1/v1.25.2
    al kostte. Dependency-vrij, zoals de rest van de installer.
  - Sitebrede header in beide thema's (`cf-logo-wrap`) — het logo staat nu naast de sitenaam
    i.p.v. alleen de 🔮-emoji. Los van `.cf-logo` gehouden (nieuwe `.cf-logo-icon`/
    `.cf-logo-row`-classes), want `.cf-logo` gebruikt `-webkit-text-fill-color: transparent`
    voor de gradient-tekst — op een `<img>` gezet zou dat 'm gewoon onzichtbaar maken.
  - Standaard-favicon: Step5.php seedt voortaan `core.site_icon` met
    `/assets/img/favicon-32.png` zodra de installatie afrondt. Voorheen bleef die instelling
    leeg totdat een beheerder er zelf handmatig een uploadde via `/admin/settings` — een verse
    site toonde dus een lege/gebroken favicon-tag totdat iemand daaraan dacht.
  - README.md — logo bovenaan.

Live getest: het welkomstscherm toont bij het eerste bezoek, de doorklik-link werkt en het
"onthouden dat er al gestart is"-gedrag klopt (herhaald bezoek zonder querystring valt niet
terug naar het welkomstscherm), een volledige installatie is opnieuw doorlopen, en ná
installatie tonen zowel de favicon-tag als het header-logo het juiste, nu bereikbare bestand
(`/assets/img/favicon-32.png` resp. `/assets/img/logo-64.png`, beide 200 OK).

---

## [1.25.2] — 2026-09-29 — "Forbidden" op /installer/ — eigen v1.25.1-fix zat nog in de weg

Aanleiding: direct na v1.25.1 live-melding "Forbidden / you don't have permission to access
this resource" bij het starten van de installer. Zelf veroorzaakt: de nieuwe root-`.htaccess`
uit v1.25.1 blokkeerde (bedoeld voor `.env`/`config`/`storage`/`vendor`/etc.) per ongeluk óók
letterlijk het pad `installer` zelf — dus een rechtstreeks bezoek aan `/installer/` (de URL die
README als installatie-instructie geeft) werd al door Apache met een 403 afgekapt, nog vóórdat
de request `public/index.php` (en daarmee de v1.25.1-dispatch-fix) ooit bereikte. Bij het
live-testen van v1.25.1 is dit gemist: wél `/installer/index.php` rechtstreeks getest (bewust
403, single-entry-point), maar nooit `/installer/` zonder bestandsnaam — exact het pad dat de
gebruiker gebruikte.

**Fix:** `installer` uit de blokkeerlijst in de root-`.htaccess` gehaald. Er is geen
beveiligingswinst in het blokkeren van directe toegang tot de installer-map specifiek —
`installer/index.php` beschermt zichzelf al (`InstallerCore::isCompleted()` stuurt na
installatie door naar `/`), in tegenstelling tot `.env`/`config/`/`storage/`/`vendor/`, die wel
echte geheimen bevatten en dus geblokkeerd blijven.

Live opnieuw getest (PHP-ingebouwde server met `DocumentRoot=projectroot`, dus precies het
model waar dit speelde): `/installer/` geeft nu 200 i.p.v. 403, en een complete installatie
(stap 1–5 via die exacte `/installer/?step=N`-URL's, echte MariaDB) is opnieuw volledig
doorlopen. `.env`, `config/config.php`, `vendor/autoload.php`, `composer.json`,
`src/Core/Application.php` en `modules/discord/module.json` blijven allemaal 403, zoals bedoeld.

---

## [1.25.1] — 2026-09-29 — Installer onbereikbaar bij upload naar een echte server

Aanleiding: live-melding van de gebruiker na het uploaden van het project naar een echte
server — "als ik het script upload naar server start de installer al niet". Op de PHP-
ingebouwde-serveromgeving waarmee tot nu toe altijd is getest, viel dit niet op (daar werd
altijd rechtstreeks tegen `public/` of de projectroot getest, nooit via een écht ingerichte
webserver-`DocumentRoot` — precies dezelfde blinde vlek als de `.htaccess`-routingbug uit
v1.25.0). Root cause en fix hieronder zijn ditmaal wél live getest via een PHP-ingebouwde
server met TWEE apart nagebouwde `.htaccess`-emulerende routers — één per document-root-model
— inclusief een volledige installatie-run (stap 1 t/m 5, met een echte MariaDB) door beide
heen.

### 🔴 Kritiek — installer altijd onbereikbaar (of erger: redirect-loop) op een echte server

- **Root cause: architectuur-tegenspraak tussen `README.md` en de mappenstructuur.**
  `README.md` §2.1 documenteert `public/` nadrukkelijk als het enige, bedoelde document root
  ("Enige publieke map"), en `public/.htaccess` gaat daar ook van uit. Maar `installer/` staat
  bewust NAAST `public/`, niet erin (zie de architectuurspec §2.2) — wat betekent dat onder het
  door README zelf aanbevolen, veilige `DocumentRoot=public/`-model de map `installer/`
  helemaal niet in de door de webserver bereikbare boom staat. De v1.25.0-fix aan de
  root-`.htaccess` (die alléén relevant is bij het andere, minder ideale model
  `DocumentRoot=projectroot`) loste dus maar de helft van het probleem op.
  `public/index.php` deed vervolgens een `header('Location: /installer/')` — een URL die onder
  `DocumentRoot=public/` simpelweg niet bestaat. Live bevestigd met een router die
  `public/.htaccess` nabootst: dat gaf niet zomaar een 404, maar een oneindige **redirect-loop**
  (`/` → 302 naar `/installer/` → front controller ziet nog steeds geen `config/config.php` →
  weer 302 naar `/installer/` → …) — in een echte browser zichtbaar als
  `ERR_TOO_MANY_REDIRECTS`, wat exact overeenkomt met "de installer start niet".
  **Fix:** `public/index.php` stuurt niet langer een HTTP-redirect, maar `require`t het echte
  `installer/index.php` rechtstreeks in-place. Dat bestand blijft op zijn eigen plek
  (`CF_ROOT/installer/`) staan, dus zijn eigen padberekeningen (`dirname(__DIR__)` voor
  `config/config.php` wegschrijven, `schema.sql` inlezen, `storage/`-checks) blijven kloppen
  ongeacht vanaf welke document root het front-controllerbestand is aangeroepen — de
  installer-forms posten toch al zonder `action`-attribuut (dus terug naar de huidige URL) en
  de stap-navigatie gebruikt relatieve `?step=N`-redirects, dus dit is voor de browser volledig
  transparant. `installer/index.php` kreeg een `defined('CF_ROOT')`-guard zodat de constante
  niet dubbel wordt gedefinieerd wanneer het bestand zo wordt geïncluded i.p.v. rechtstreeks
  aangeroepen. Live getest: een volledige installatie (stap 1–5, echte MariaDB-schema-import,
  admin-account, `config/config.php` wegschrijven) succesvol doorlopen met de PHP-server
  geconfigureerd als `DocumentRoot=public/` — zowel via `/` als via de door README
  gedocumenteerde `/installer/`-URL. Na installatie serveert `/` gewoon de normale site (geen
  loop, geen herhaalde installer).
- **Bijkomend lek in de root-`.htaccess` gedicht.** Voor het andere document-root-model
  (`DocumentRoot=projectroot`, bv. gedeelde hosting zonder eigen document-root-instelling) liet
  de root-`.htaccess` van v1.25.0 ieder bestaand bestand/map ongefilterd door — inclusief
  `.env`, `config/`, `storage/`, `vendor/` en `composer.json` — specifiek om `installer/`
  rechtstreeks bereikbaar te houden. Nu de front controller de installer zelf dispatcht, is die
  uitzondering niet meer nodig: de root-`.htaccess` blokkeert deze gevoelige paden nu expliciet
  (dezelfde `[F,L]`-aanpak als `public/.htaccess` al gebruikte) en stuurt verder, zonder
  uitzondering, alles via `public/`. Live bevestigd: `/.env`, `/config/config.php`,
  `/composer.json` en directe toegang tot `/installer/index.php` geven nu allemaal 403 i.p.v.
  de wachtwoorden/geheimen uit `.env` als platte tekst te serveren; een volledige installatie
  via dit model (`DocumentRoot=projectroot`) is apart getest en werkt, inclusief normale
  paginaverzoeken en statische assets (`/assets/css/...`) ná installatie.

---

## [1.25.0] — 2026-09-29 — Post-S13 inventarisatie- en debugronde

Aanleiding: tweede helft van "s13 en dan inventariseren en de debug" — na S13 (i18n, zie
v1.24.0) een projectbrede audit van alle modules op gaten/bugs, gevolgd door een echte fix- en
live-testronde. Twee onafhankelijke audits (core `src/Modules`+`src/Core`, en de externe
`modules/`+`themes/`+`installer/`) leverden 13 concrete bevindingen op; alle zijn gefixt en
live getest tegen een echte, opnieuw opgebouwde MariaDB-installatie (schema-import, twee
admin-accounts met verschillende role-priority, een guest-account, en de PHP-ingebouwde server
achter een `.htaccess`-emulerende router — zie eerdere versies voor waarom dat laatste nodig
is). Elke bevinding hieronder werd zowel vóór als na de fix daadwerkelijk getest, niet alleen
gelezen.

### 🔴 Kritiek — privilege-escalatie (2×)

- **`/admin/users` — elke `users.manage`-houder kon zichzelf/anderen super_admin maken.**
  `users.manage` staat standaard ook op de `admin`-rol (priority 80), niet alleen op
  `super_admin` (priority 100) — maar `UserAdminController::update()` accepteerde élke
  `roles[]`-selectie zonder enige prioriteitscheck, en `admin_edit.php` toont alle rollen
  inclusief `super_admin` als gewone checkbox. Live bevestigd: een ingelogde priority-80
  `admin` kon een andere gebruiker via een simpele POST de `super_admin`-rol geven. Fix:
  `UserAdminController` weigert nu elke `roles[]`-toewijzing met een hogere `priority` dan de
  hoogste rol van de ingelogde beheerder zelf (`highestPriority()`, via
  `RBACManager::getUserRoles()`). Getest: escalatiepoging → redirect met foutmelding, géén
  rij in `cf_user_roles`; toewijzen van een gelijke/lagere rol (bv. `moderator`, `admin`)
  werkt gewoon door.
- **`/admin/roles` — elke `roles.manage`-houder kon de `*`-wildcard aan een ANDERE rol hangen.**
  `RoleAdminController::update()` forceerde de `*`-wildcard alleen op de rol `super_admin`
  zélf, maar sloot 'm nergens uit bij het bewerken van een andere rol — en de
  permissie-checkboxlijst toont `*` gewoon als aanvinkbaar voor elke niet-`super_admin`-rol.
  Live bevestigd: de `admin`-rol de `*`-permissie geven via het gewone formulier lukte
  probleemloos, wat elke toekomstige `admin`-gebruiker stilzwijgend tot super_admin had
  gemaakt. Fix: de wildcard-permissie-id wordt nu altijd uit `permissions[]` gefilterd tenzij
  de bewerkte rol `super_admin` is. Getest: dezelfde poging slaat de overige permissies wél
  op, maar `*` verschijnt niet in `cf_role_permissions` voor de `admin`-rol.

### 🔴 Kritiek — architectuurbug: JSON-request-bodies werden nergens uitgelezen

- **`Request::fromGlobals()` gebruikte kaal `$_POST` als body.** PHP vult `$_POST` NOOIT voor
  een `Content-Type: application/json`-request (alleen voor
  `application/x-www-form-urlencoded`/`multipart/form-data`) — maar drie plekken in de app
  sturen wél JSON: de Blokken-admin (drag & drop), de Marketplace-admin, en de
  Ollama-chatwidget. Voor alle drie betekende dit dat `Request::input()` altijd de
  default-waarde teruggaf, ongeacht wat de client stuurde: blok toevoegen/verplaatsen/
  verwijderen gaf altijd "Block type '' niet gevonden", en de Ollama-chat altijd "messages
  array vereist." — functioneel volledig kapot, ondanks dat de UI's er compleet uitzagen.
  Bovendien leest `CsrfProtection::validateRequest()` rechtstreeks `$_POST`, dus zelfs ná een
  eventuele body-fix zou elke CSRF-check op deze routes blijven falen. Fix: `Request::
  fromGlobals()` detecteert nu `Content-Type: application/json`, parsed `php://input` en
  merget het resultaat zowel in `$body` als terug in `$_POST` (zodat rechtstreekse
  `$_POST`-lezers als `CsrfProtection` hetzelfde zien). Los daarvan bleek de Blokken-admin-JS
  de al wél uitgelezen `CSRF`-const nooit daadwerkelijk mee te sturen — gefixt naar hetzelfde
  patroon als Marketplace (`_csrf_token` in de JSON-body). Live getest: blok toevoegen via de
  JSON-`api()`-helper werkt nu (`{"success":true,"block_id":"1"}`); `/api/v1/auth/login`
  (die al langer op `$request->input()` leunde) las voorheen dus óók nooit een echte
  `identifier`/`password` uit een JSON-body — bevestigd via curl vóór en na de fix.

### 🔴 Kritiek — routing: 3 module-admin-panels waren volledig onbereikbaar

- **De `router.routes`-hook vuurde ná de `/admin/{path}`-catch-all, niet ervóór.** `Router`'s
  constructor registreert alle kernroutes (incl. de bewust-laatste catch-all die elke
  onbekende `/admin/...` afvangt met een "nog niet gebouwd"-scherm), en pas ìn `dispatch()`
  vuurde de `router.routes`-hook waarmee modules hun eigen routes registreren
  (`ModuleInterface::boot()`). Omdat de Router op registratievolgorde matcht (niet op
  specificiteit — hetzelfde principe dat de catch-all's eigen commentaar al beschreef voor de
  kernroutes, maar niet voor hook-routes), werd élke module-geregistreerde `/admin/*`-route
  altijd door de catch-all onderschept. Trof drie modules: **Guild Management** (`/admin/
  guild`), **Warcraft** (`/admin/wow`) en **Ollama** (`/admin/ollama`) — hun volledige
  adminschermen waren sinds hun introductie nooit bereikbaar, elke klik landde stilletjes op
  de generieke `/admin`-redirect zonder foutmelding. Fix: de `router.routes`-hook vuurt nu in
  de constructor, ná `registerCoreRoutes()` maar vóór de (nu losgetrokken)
  `registerFallbackRoutes()` die de catch-all + OAuth-callbacks bevat. Live bevestigd: vóór de
  fix gaven alle drie `/admin/guild`, `/admin/wow` en `/admin/ollama` een 302 naar `/admin`;
  ná de fix geven ze allemaal 200 met het echte modulescherm — een volledige regressiesweep
  over 18 andere admin- en publieke routes bevestigde dat niets anders brak.

### 🟠 Hoog — CSRF

- **`BlockController::savePositions()`** (drag & drop-herordening) miste
  `CsrfProtection::validateRequest()`, als enige state-wijzigende actie in dat bestand. Live
  getest: zonder token nu een 403, met token 200.
- **Guild-admin "Goedkeuren"/"Afwijzen"-knoppen** stuurden nooit een CSRF-token mee (geen
  `_csrf_token`-veld op de pagina, en de `fetch()`-body bevatte 'm ook niet) — beide knoppen
  gaven dus altijd een 403. Fix: `CsrfProtection::field()` + `CSRF`-const toegevoegd naar
  hetzelfde patroon als Blokken/Marketplace.
- **`/api/v1/auth/login` opende login-CSRF.** Dit "stateless" JWT-endpoint riep intern gewoon
  `AuthManager::login()` aan — `session_regenerate_id()` + `$_SESSION['user_id']` zetten,
  identiek aan de normale weblogin. Een cross-site `<form method=POST>` (form-urlencoded,
  geen CORS-preflight nodig) naar dit endpoint met de inloggegevens van de AANVALLER logde
  het sessiecookie van het SLACHTOFFER stilletjes in op het account van de aanvaller — een
  klassieke login-CSRF/session-fixation. Fix: `AuthManager::attempt()`/`login()` kregen een
  `$startSession`-parameter (default `true`, ongewijzigd voor de twee weblogin-aanroepen);
  `Api\V1\AuthController::login()` roept nu `attempt(..., startSession: false)` aan. Live
  bevestigd: een API-login retourneert een geldig JWT maar zet `$_SESSION['user_id']` niet —
  `/api/v1/auth/me` met dát sessiecookie stuurt nog steeds door naar `/login`, terwijl de
  normale weblogin met dezelfde credentials `/api/v1/auth/me` wél de juiste gebruiker geeft.

### 🟡 Gemiddeld — module-degradatie & netwerkfouten

- **Guild Management crashte met een kale 500 als de module wel ingeschakeld maar nog niet
  geïnstalleerd was.** De installer registreert een geselecteerde module bewust alleen in
  `cf_modules` zonder `install()` aan te roepen (zie `installer/steps/Step5.php`'s eigen
  commentaar: modules horen zich netjes te degraderen tot een beheerder ze via de Marketplace
  echt installeert) — maar Guild's controllers hadden geen enkele try/catch rond hun queries
  tegen de dan nog niet bestaande `cf_guild_*`-tabellen. Fix: `installed()`-check (probeert
  `SELECT 1 FROM cf_guild_teams`) vóór elke actie in `GuildController`/`GuildAdminController`,
  met een vriendelijke 503 i.p.v. een PDOException. Live bevestigd met een module die wél
  `is_enabled=1` maar nooit `install()`'d is: `/guild` en `/admin/guild` geven nu netjes 503
  i.p.v. 500.
- **`BlizzardApiClient::getAccessToken()`, `FiveMController::index()` en
  `FiveMStatusBlock::render()` crashten bij een netwerkfout.** `curl_exec()` geeft `false`
  terug bij een timeout/onbereikbare server — en `json_decode(false, …)` gooit onder
  `strict_types=1` een `TypeError` (verwacht `string`) i.p.v. dat een `?? []`/`?? null` erna
  nog kan redden. Voor FiveM is een offline server nota bene het meest voorkomende geval op
  die pagina. Alle drie kregen een `is_string()`-check vóór `json_decode()`, naar hetzelfde
  patroon als de reeds-correcte buren in dezelfde bestanden (bv. `BlizzardApiClient::get()`,
  `MinecraftController`). Live bevestigd tegen een echt onbereikbaar IP (`10.255.255.1`):
  `/fivem` geeft nu gewoon 200 (offline-status) i.p.v. 500.

### 🟢 Laag — opruiming

- **`FileCache::get()`** gaf een PHP-warning + effectief altijd een cache-miss bij een
  corrupt/half-geschreven cachebestand (`unserialize()` op ongeldige data geeft `false`,
  waarna `$data['expires']` op een bool een warning gooit) — en liet het kapotte bestand
  staan, dus de warning bleef terugkomen. Nu een expliciete check die het bestand opruimt en
  netjes `$default` teruggeeft.
- **Dode code verwijderd**: `BlockController::zones()` (nooit gerouteerd, vervangen door
  `getZonesApi()`), en zes wezen-views onder `src/Modules/Settings/views/` (`roles.php`,
  `menus.php`, `themes.php`, `logs.php`, `media.php`, `_placeholder.php`) — de Wave 2
  "nog niet gebouwd"-placeholders voor precies die vijf schermen, die sinds Wave 5 elk hun
  eigen dedicated controller + route hebben en dus nooit meer via de `/admin/{path}`-catch-all
  bereikt worden (bevestigd: geen enkele andere plek `include`t ze).

### Niet aangepast (bewust, of te klein om los te noemen)

Enkele `module.json`-manifesten (kick/twitch/youtube/battlenet/google) declareren `hooks[]`
die nergens geregistreerd of afgevuurd worden — puur decoratieve metadata zonder functioneel
effect, niet aangepakt in deze ronde. `composer.json` vereist nog altijd `league/container`,
`league/event`, `league/route`, `monolog/monolog` en `ramsey/uuid`, die nergens in de
codebase gebruikt worden (bevestigd via een projectbrede grep) — vermoedelijk restanten van
een vroege architectuurkeuze die is losgelaten; opschonen is uitstelbaar (het kost niets
zolang `composer install` toch al niet tegen packagist.org kan draaien in deze omgeving) en
dus bewust buiten scope van deze inventarisatieronde gelaten.

---

## [1.24.0] — 2026-09-29 — S13: Multi-language / i18n

Aanleiding: "s13 en dan inventariseren en de debug" — na S11 (Media-galerij) werd S12
(Premium ecosysteem) expliciet uitgesteld ("premium is nu niet belangerijk") ten gunste van
S13 (i18n), met een post-sprint inventarisatie- en debugronde erna gepland.

### Nieuw — `src/Core/I18n/`, `lang/`

- **`Translator`** (`src/Core/I18n/Translator.php`) — platte PHP-array-vertaalbestanden
  (`lang/{locale}.php`, dot-notation sleutels zoals `admin.sidebar.dashboard`) i.p.v. een
  databasetabel of .po/.mo: geen eigen CRUD-admin-UI nodig om strings te beheren, profiteert
  van OPcache net als `config/config.php`, en is git-diffable. `SUPPORTED = ['nl','en','de']`
  — bewust gelijk aan wat `installer/templates/step3.php` al langer aanbood (`/admin/settings`
  accepteerde tot nu toe alleen nl/en, zie de bugfix hieronder). Ontbrekende sleutels vallen
  terug op `nl` (de taal waarin dit project native geschreven is), en als zelfs dat ontbreekt
  wordt de kale sleutel teruggegeven — nooit een lege string of fatale fout.
- **`lang/nl.php` / `lang/en.php` / `lang/de.php`** — `common`, `nav`, `auth.login.*`,
  `auth.register.*`, `profile.*`, `admin.sidebar.*`, `admin.settings.*`, `admin.dashboard.*`.
  `nl.php` is de bron van waarheid (meest compleet); `en`/`de` spiegelen dezelfde sleutels.
- **`Trans`** (`src/Core/I18n/Trans.php`) — statische facade voor de ~30 raw-PHP admin-schermen
  (die geen Twig/container-scope hebben), naar hetzelfde patroon als `CsrfProtection::field()`.
  `Trans::get($key)`/`Trans::t($key)` voor vertalingen, en `Trans::locale()` (nieuw, tijdens de
  live-testronde toegevoegd) zodat elk admin-scherm zijn `<html lang="…">` mechanisch kan
  laten kloppen zonder een aparte Translator-lookup per view.
- **Locale-resolutievolgorde** (`Application::boot()`): 1) `$_SESSION['locale']` (expliciete
  keuze, werkt ook voor gasten) → 2) ingelogde `cf_users.locale` → 3) `cf_settings`
  `core.default_locale` (sitestandaard) → 4) hardcoded `'nl'`. Elke stap apart in een
  try/catch, consistent met het bestaande pre-install-veiligheidspatroon in `boot()`.
- **Twig-integratie** (`ThemeManager::setTranslator()`) — `{{ trans('key') }}`-functie en
  `'key'|trans`-filter, plus de globals `locale` en `supported_locales`.
- **Publieke taalwisselaar** — `GET /taal/{locale}` (`Modules\I18n\LanguageController`), werkt
  voor gasten via de sessie, met CSRF-vrije maar wél beveiligde redirect: `safeRedirectTarget()`
  vertrouwt uitsluitend het *pad* van de `Referer`-header (nooit scheme/host) en weigert een
  redirect terug naar `/taal/` zelf (loop-preventie), met `/` als fallback.
- **Per-gebruiker taalvoorkeur** — `POST /profiel/taal` (`ProfileController::updateLanguage()`)
  schrijft naar `cf_users.locale` én direct naar `$_SESSION['locale']`, zodat de wijziging al
  op dezelfde pagina-load zichtbaar is i.p.v. te wachten op de volgende Translator-resolutie.
- **`.cf-lang-switch`-styling** in `blueprint.css` — de header-taalwisselaar (NL/EN/DE, actieve
  taal gemarkeerd) had tot nu toe geen eigen stijl.

### Conversie naar `trans()`/`Trans::get()`

`layout.twig`, `auth/login.twig`, `auth/register.twig`, `users/profile.twig` (beide thema's,
byte-identiek gesynchroniseerd), de gedeelde `Shared/views/admin_sidebar.php`-partial, en het
admin-dashboard (`Settings/views/dashboard.php` — inclusief het dedupliceren van zijn tot nu
toe *handmatig gekopieerde* sidebar-markup naar diezelfde gedeelde partial, wat de i18n-
conversie van die sidebar gratis meenam). Daarnaast: `<html lang="nl">` mechanisch vervangen
door `Trans::locale()` in alle 26 overige raw-PHP `/admin/*`-schermen (News, Pages, Users,
Roles, Forum, Gallery, Media, Themes, Menus, Logs, Marketplace, Blocks, Settings). De
sitebrede navigatie miste bovendien al sinds S11 een link naar `/galerij` (de module bestond,
maar was via de UI onbereikbaar) — nu toegevoegd in `layout.twig`.

**Bewust niet geconverteerd (eerlijk gedocumenteerd, zie ook README "Bekende beperkingen"):**
losse tekst-labels ín de ~26 admin-schermen buiten dashboard/sidebar (stat-cards, tabelkoppen,
formulierlabels, enz. — alleen hun `<html lang>` is gefixt), de installer (draait vóór er een
sessie/database is om een locale uit op te lossen) en de losse game/streamer-modules
(`modules/warcraft`, `modules/minecraft`, enz. — hun eigen templates).

### Bugfix — `Settings\AdminController::updateSettings()` (locale-whitelist)

`default_locale` accepteerde hardcoded alleen `['nl', 'en']`, terwijl de installer al langer
nl/en/de aanbood — een sitebeheerder kon dus nooit Duits als sitestandaard instellen zonder
rechtstreeks in `cf_settings` te SQL'en. Nu `in_array($locale, Translator::SUPPORTED, true)`.

### Bugfix — `Router::compilePattern()` brace-quantifier in een route-constraint (gevonden tijdens live-testen)

De taalwisselaar-route was aanvankelijk geregistreerd als `/taal/{locale:[a-z]{2}}`. Elke
andere geconstrainde route in dit bestand gebruikt uitsluitend `[...]+`-vormen — met reden:
`compilePattern()`'s eigen placeholder-regex (`/\{(\w+)(?::([^}]+))?\}/`) knipt de constraint
af bij de **eerste** `}`, dus een `{n}`-quantifier ín de constraint zelf breekt de
gecompileerde regex stil kapot (de route matchte daardoor helemaal niets — `GET /taal/en` gaf
een kale 404). Ontdekt via de live-testronde van deze sprint, exact dezelfde categorie fout
als de kapotte `schema.sql`-import die Sprint 11 blokkeerde: onzichtbaar bij lezen, meteen
zichtbaar bij een echte request. Fix: `/taal/{locale:[a-z]+}` (de daadwerkelijke validatie
tegen `Translator::SUPPORTED` gebeurt toch al in `LanguageController::switch()`). **Nog niet
opgelost:** `compilePattern()` zelf blijft deze algemene beperking houden voor elke toekomstige
route die een brace-quantifier in een constraint gebruikt — meegenomen naar de inventarisatie-
/debugronde die na deze sprint volgt.

### Bugfix — root `.htaccess` herschreef ook `/installer/` naar `public/` (gevonden tijdens live-testen)

De root-`.htaccess` herschreef *elke* request ongeconditioneerd naar `public/$1` — inclusief
`/installer/`, dat bewust NAAST `public/` staat (architectuurspec §2.2). In een echte
Apache-deployment zou de door README gedocumenteerde installatie-URL (`http://jouwsite.nl/installer/`)
dus altijd op een 404 zijn gestuit, omdat `public/installer/` niet bestaat. Gevonden tijdens het
opzetten van deze sprint se live-testomgeving (een `RewriteRule (.*) public/$1`-loop die eerder
nooit tegen een echte `.htaccess`-parserende server was getest — eerdere waves testten altijd
rechtstreeks tegen `public/` of `installer/`, nooit via deze root-`.htaccess`). Fix: twee
`RewriteCond`-regels die een bestaand bestand/map ongemoeid laten vóór de onvoorwaardelijke
herschrijving naar `public/`.

### Overig

- Footer-crediet "Slayer Alliance" → **ScriptSpace** (`https://www.scriptspace.nl`, de site
  vanwaar deze CMS gehost wordt en draait) in beide thema's én de installer-footer.

### Live-testverificatie (scripted install tegen een echte MariaDB-server, PHP built-in server)

- Gast-taalwisselaar: `/taal/en` en `/taal/de` zetten de sessie en veranderen zowel
  `<html lang>` als alle `trans()`-tekst direct (`Nieuws`→`News`/`Neuigkeiten`, sidebar-
  secties `Uiterlijk`→`Appearance`, enz.); `/taal/nl` schakelt terug.
- Prioriteitsketen end-to-end bevestigd: sessie-override > ingelogde `cf_users.locale` >
  site-`default_locale` > `'nl'` — inclusief een verse login (géén sessie-override) die
  meteen de zojuist via `/profiel/taal` opgeslagen DB-voorkeur toont, en een site-
  `default_locale`-wijziging naar `de` via `/admin/settings` die onmiddellijk zichtbaar is
  voor een geheel nieuwe, niet-ingelogde bezoeker.
- `/admin/settings`'s taal-dropdown biedt nu daadwerkelijk nl/en/de en de POST slaat `de`
  correct op (whitelist-bugfix hierboven).
- Beveiliging: een ongeldige locale (`/taal/xx`) wordt geweigerd zonder de sessie te wijzigen;
  de loop-preventie (Referer terug naar `/taal/`) valt terug op `/`; de Referer-pad-only-
  bescherming laat nooit een cross-origin redirect toe (een externe Referer levert hooguit een
  lokaal pad op, nooit het externe domein).
- Ontbrekende-sleutel-fallback bevestigd via een losstaande Translator-aanroep: een niet-
  bestaande sleutel geeft de kale sleutel terug, nooit een fatale fout of lege string.
- 28-routes regressiesweep (publiek + admin, ingelogd) zonder nieuwe breuken.
- Testomgeving nadien volledig opgeruimd: testdatabase/-gebruiker gedropt, `config/config.php`
  en `installer/.installed` verwijderd (beide toch al git-genegeerd).

---

## [1.23.0] — 2026-09-29 — S11: Media-galerij (los van de generieke upload-handler)

Aanleiding: "s11" — het volgende item op de roadmap na S10 (YouTube + Kick). Een échte
media-galerij (albums met foto's en video's, upload, miniaturen, publieke doorbladering),
expliciet te onderscheiden van twee dingen die er oppervlakkig op leken maar het niet waren:
`UploadManager` (de generieke bestandsopslag-utility — valideert en bewaart, maar toont niets)
en het bestaande `/admin/media`-scherm (een bestandshuishouding-scanner over `storage/`, geen
galerij met albums, miniaturen of een publieke pagina).

### Nieuw — `src/Modules/Gallery/`

- **Albums = `cf_categories` met `type='gallery'`** — zelfde hergebruikpatroon als Forumborden
  (`type=forum`) en Nieuwscategorieën (`type=news`): geen nieuwe albumtabel nodig, wel een eigen
  `cf_gallery_items`-tabel (bestand, miniatuur, afmetingen, auteur, soft-delete).
- **`GalleryRepository`** — albums/items, publiek + admin, met dezelfde cache-op-schrijven-
  invalideren-conventie als de rest van het project.
- **`GalleryThumbnailer`** — vanaf nul geschreven GD-miniaturenmaker (er bestond nog NERGENS
  beeldbewerkingscode in dit project, ook niet bij avatars). Witte ondergrond i.p.v. zwart
  (voorkomt lelijke randen bij transparante PNG/GIF-bronnen), max. 480px, beeldverhouding
  behouden. Alleen voor afbeeldingen — video's krijgen bewust GEEN miniatuur (zou `ffmpeg` of een
  vergelijkbare frame-decoder vereisen, een procesafhankelijkheid die dit project nergens anders
  heeft en niet betrouwbaar is op gedeelde hosting); video-items tonen een vaste ▶️-placeholder.
- **`GalleryController`** (publiek) — `/galerij` (albumindex) en `/galerij/{slug}` (album met
  paginering), met een lichtgewicht, framework-loze lightbox (vanilla JS, geen library) voor
  foto's én video's (native `<video controls>`).
- **`GalleryAdminController`** (staff, `gallery.manage`) — album-CRUD naar het bewezen
  `BoardAdminController`-patroon inclusief cascade-bescherming (`countItemsInAlbum() > 0` blokkeert
  verwijderen, met de FK's `ON DELETE CASCADE` als laatste vangnet, nooit als bedoeld pad), plus
  upload/verwijderen van items (multipart, media-type gedetecteerd via bestandsextensie na échte
  MIME-sniffing door `UploadManager`).
- **`GalleryLatestBlock`** (`gallery-latest`) — sidebar-widget met de laatst geüploade
  AFBEELDINGEN (bewust geen video's — zie hierboven), zelfde registratiepatroon als
  `NewsBlock`/`StatsBlock` in `Application.php`.
- Publieke Twig-templates in **beide** thema's (`default` én `gaming-dark`, identiek gehouden,
  zoals bij Downloads/Forum al de conventie was).

### Bestandsopslag: hergebruik van de bestaande `/media/{path}`-route

Galerijbestanden landen onder `storage/uploads/gallery/` — dus binnen de map die de bestaande,
al langer draaiende `GET /media/{path}` (`MediaController`) al serveert. Er was dus GEEN nieuwe
serveer-route nodig; alleen `mp4`/`webm` toegevoegd aan `MediaController::CONTENT_TYPES` voor de
juiste `Content-Type`-header bij video. `UploadManager` kreeg een `ALLOWED_GALLERY`-whitelist
(afbeeldingen + mp4/webm — bewust geen avi/mov/mkv, i.v.m. brede `<video>`-compatibiliteit zonder
transcoderen) en een `forGallery()`-factory, naar het `forDownloads()`-patroon.

### Bugfix — `/admin/media` zou galerijbestanden als "ongebruikt" hebben bestempeld

Gevonden tijdens het ontwerp (vóór livetest, dus nooit in productie geraakt): de bestaande
bestandshuishouding-scanner (`MediaAdminController`) kruiste geüploade bestanden alleen tegen
`cf_users.avatar_url` en `cf_downloads.file_path` — een gloednieuwe galerijfoto zou daardoor als
"ongebruikt, veilig te verwijderen" zijn getoond. Opgelost door ook `cf_gallery_items.file_path`
en `.thumbnail_path` mee te tellen, vóórdat dit ooit een echte upload trof.

### Bugfix — dubbel geneste opslagpaden tijdens livetest ontdekt en gecorrigeerd

De eerste implementatie construeerde `GalleryAdminController`'s eigen `UploadManager`-instantie
geworteld op `storage/uploads/gallery/` (naar analogie met Downloads' eigen `storage/downloads/`-
root), maar gaf `store()` daarna óók nog eens `'gallery'` als submap mee — resultaat:
`storage/uploads/gallery/gallery/xxx.jpg`. Ontdekt bij de eerste live upload-test (het bestand kwam
niet aan waar `/media/{path}` het verwachtte). Root-oorzaak: Gallery hergebruikt bewust de gedeelde
`/media/{path}`-serveer-route (zie hierboven), dus de `UploadManager`-instantie moet — anders dan
Downloads, die een eigen, aparte serveer-route heeft — geworteld zijn op `storage/uploads/` (net
als de DI-singleton voor avatars), met `'gallery'` alleen als submap-parameter. Gecorrigeerd vóór
commit; live opnieuw geverifieerd met een echte upload (zie hieronder).

### Bugfix — `schema.sql`-import brak op een puntkomma binnen een `COMMENT`-string

`InstallerCore::importSchema()` splitst statements op een kale `explode(';', ...)` (met een eigen,
al langer bekende beperking — zie het commentaar daar over regel-comments). Een `COMMENT`-string in
de nieuwe `cf_gallery_items`-tabel bevatte zelf een `;` ("Relatief pad; alleen gevuld voor..."),
wat de CREATE TABLE-statement middendoor brak bij elke verse installatie. Gevonden bij de eerste
scripted install van deze wave, vóór enige andere test kon draaien. Gecorrigeerd (komma i.p.v.
puntkomma) — dit had zonder livetest elke fresh install van het hele project gebroken, niet
alleen S11.

### Live getest (scripted install, DB `bluprint_gallery`, PHP dev-server)

Album aanmaken/bewerken/verwijderen; upload van een écht gegenereerde JPEG (GD, 1200×800) —
miniatuur correct gegenereerd op 480×320 (beeldverhouding behouden), breedte/hoogte correct in de
DB; upload van een écht gegenereerde MP4 (`ffmpeg`) — correct opgeslagen als `media_type=video`
zonder miniatuur, correct geserveerd met `Content-Type: video/mp4`; cascade-bescherming (album met
items → 302 + foutmelding, niet verwijderd; leeg album → verwijderd); fysieke bestanden
daadwerkelijk van schijf verdwenen na item-verwijdering; `/admin/media` toont galerijbestanden nu
correct als "nog in gebruik"; `GalleryLatestBlock` geplaatst via de echte `/admin/blocks`-flow en
correct gerenderd op de homepage-sidebar met de echte miniatuur; publieke albumpagina + lightbox
correct in zowel het `default`- als het `gaming-dark`-thema; 20+ routes regressie-geveegd,
serverlog volledig schoon (geen enkele PHP error/warning/notice). Testomgeving nadien opgeruimd
(DB, `vendor/`, `config/config.php`, testbestanden).

### Bekende beperkingen

- Alleen staff met `gallery.manage` kan albums/items beheren — geen lid-uploads of
  moderatiewachtrij in deze wave (zelfde "staff-curated" model als Downloads/News).
- Video-items hebben geen miniatuur (vaste ▶️-placeholder) — zie de ontwerpkeuze hierboven.
- Geen per-item zichtbaarheids-toggle in de UI (`is_published` bestaat in het schema voor
  toekomstig gebruik; S11-upload is altijd direct zichtbaar, verwijderen is het enige
  zichtbaarheidscommando).
- Maximale video-uploadgrootte 25MB (ruimer dan de 5MB voor afbeeldingen) — geen transcodering of
  compressie; alleen mp4/webm worden geaccepteerd.

---

## [1.22.0] — 2026-09-29 — S10 (vervolg): Kick-integratie (live-status, kijkers, stream-embed)

Aanleiding: "ja kick eerst aub" — de tweede en laatste van de twee S10-integraties uit de
roadmap (YouTube in v1.21.0, nu Kick), zodat S10 volledig is afgerond.

### Nieuw — `modules/kick/`

Twee blocktypes, naar hetzelfde bewezen Twitch/YouTube-patroon:

- **`kick-live`** — live/offline-status, titel, kijkersaantal, categorie, thumbnail.
- **`kick-stream`** — iframe-embed via `player.kick.com/{kanaal}` (eenvoudiger dan Twitch's
  embed: geen verplichte `parent`-domeinwhitelist nodig).

### Ontwerpkeuze: publiek endpoint i.p.v. Kick's officiële OAuth-API

Onderzocht en bevestigd (zie bronvermeldingen in `KickApi.php`'s klassecommentaar): Kick heeft
sinds 2024/2025 een officiële, OAuth 2.1-beveiligde Developer API
(`https://api.kick.com/public/v1`, login via `id.kick.com/oauth/authorize` + `/oauth/token`,
verplichte PKCE — vergelijkbaar qua zwaarte met de Golf 10-providers). Die API is echter gebouwd
voor kanaal-eigenaren die hún eigen kanaal beheren (chat, moderatie, beloningen) — er bestaat
geen gedocumenteerd "zoek kanaal X op zonder dat X zelf inlogt"-endpoint, wat nodig is voor een
simpel live-status-blok. Daarom gebruikt `KickApi.php` bewust het publieke, ongeauthenticeerde
`kick.com/api/v2/channels/{slug}`-endpoint — hetzelfde endpoint dat kick.com's eigen website
intern gebruikt en dat de meeste bestaande open-source Kick-tools om diezelfde reden gebruiken.
Dit is **eerlijk gedocumenteerd als een bekende beperking**: het is geen door Kick officieel
ondersteund endpoint en kan zonder aankondiging wijzigen. Een browser-achtige `User-Agent`-header
is toegevoegd omdat Kick's Cloudflare-bescherming een kale PHP-curl-UA vaker blokkeert. Precies
zoals `TwitchApi`/`YouTubeApi` degradeert elke aanroep netjes naar `null` bij een fout — nooit
een crash, alleen een "Offline"-weergave.

Instellingenscherm werkt weer direct via het generieke `ModuleSettingsController` uit Golf 10
(één simpel veld: `channel_slug`, geen sleutel/app-registratie nodig) — inclusief een eigen
provider-uitleg in `module_settings.php::oauth_provider_hint()` die expliciet uitlegt waarom
hier geen OAuth-koppeling nodig is en waar de officiële Developer API wél voor bedoeld is.

### Live getest (scripted install + PHP-server + curl)

Beide blocktypes verschijnen automatisch in de `/admin/blocks`-picker; via de echte
`/admin/blocks/store`-flow geplaatst en op de homepage gerenderd. `kick-live` degradeerde
netjes naar "⚫ Offline" (geen uitgaand verkeer naar willekeurige domeinen toegestaan in deze
sandbox — hetzelfde als bij YouTube/Twitch), `kick-stream` rendert een correcte
`player.kick.com/xqc`-iframe zonder netwerkaanroep nodig te hebben. Instellingenscherm
save→DB→herlaad-roundtrip bevestigd (`channel_slug` blijft correct staan na opslaan). Geen
PHP-fouten/warnings in de serverlog. Regressiesweep over 19 routes (incl. alle vier
OAuth-login-redirects en alle vier providerinstellingenschermen) — alles 200/302, geen enkele
breuk.

---

## [1.21.0] — 2026-09-29 — Golf 10a: YouTube-module (kanaalinfo, laatste video's, live-status, playlists)

Aanleiding: vervolg op Golf 10 — "ga vervolgens verder met stap 10a youtube", de eerste van de
twee S10-integraties uit de roadmap (YouTube + Kick).

### Nieuw — `modules/youtube/`

Vier blocktypes, naar het bewezen Twitch-patroon (`TwitchLiveBlock`/`TwitchStreamBlock`):

- **`youtube-channel`** — kanaaltitel, avatar, abonnee-/video-aantallen.
- **`youtube-latest`** — grid met de laatste N uploads (via `playlistItems.list` op de
  `uploads`-playlist, NIET `search.list` — dat laatste raadt Google zelf af voor dit doel omdat
  het ~100x zoveel API-quota kost).
- **`youtube-live`** — live/niet-live-status. Gebruikt wél `search.list` met `eventType=live`
  (de enige gedocumenteerde manier om dit te detecteren), en cachet daarom 5 minuten i.p.v.
  Twitch's 90 seconden — bewuste quota-sparing (een gratis Google Cloud-project krijgt 10.000
  eenheden/dag, deze ene aanroep kost al 100).
- **`youtube-playlist`** — pure iframe-embed (`youtube.com/embed/videoseries?list=...`), heeft
  BEWUST geen API-sleutel nodig, zelfde soort embed als `TwitchStreamBlock`.

Nieuwe dunne API-client `YouTubeApi.php` (server-side API-sleutel, geen OAuth — dit is
functioneel iets heel anders dan de Google-*login*-module uit Golf 10, vandaar een eigen
`api_key`-instelling i.p.v. `client_id`/`client_secret`). Elke methode degradeert netjes naar
`null`/`[]` bij een API-fout, zelfde patroon als `TwitchLiveBlock::fetchLiveStatus()` — een
verkeerde of lege sleutel mag een blok nooit laten crashen.

Instellingenscherm werkt out-of-the-box via het generieke `ModuleSettingsController` uit Golf
10 (geen extra code nodig) — inclusief een eigen provider-uitleg in
`module_settings.php::oauth_provider_hint()`: waar je de YouTube Data API v3 inschakelt in de
Google Cloud Console (aparte stap van de sleutel zelf aanmaken — vaak vergeten) en hoe je een
kanaal-ID (niet de gebruikersnaam) vindt.

### Bug gevonden tijdens live-testen: `GET /admin/blocks/create` was al sinds het bestaan van
### deze controller een lege 200-pagina

`BlockController::create()` doet `include __DIR__ . '/views/create.php'`, maar dat bestand
bestond nergens in de repo — alleen `views/index.php` was er. In de praktijk onschadelijk
gebleven omdat de ECHTE "blok toevoegen"-flow in `views/index.php` een JS-modal gebruikt die
rechtstreeks naar `POST /admin/blocks/store` post en deze `GET`-route nooit aanroept — vandaar
dat dit nooit eerder opviel, ook niet in eerdere golven die het Blokkensysteem live testten
(die gingen via dezelfde modal-flow). Kwam nu aan het licht omdat ik voor het live-testen van
de nieuwe YouTube-blokken eerst deze route rechtstreeks probeerde. Nieuwe
`src/Modules/Blocks/views/create.php` toegevoegd: een simpele non-JS-fallback met hetzelfde
formulier als de modal (titel, zone, per-blocktype config-schema, CSRF-veld) — nu ook bruikbaar
als directe link of zonder JavaScript.

### Live getest (scripted install + PHP-server + curl)

Alle vier blocktypes verschijnen automatisch in de `/admin/blocks`-picker (dynamische registry,
geen extra wiring nodig); elk type geplaatst via de echte `/admin/blocks/store`-flow en op de
homepage gerenderd — `youtube-live`/`youtube-channel`/`youtube-latest` degraderen netjes
("Niet live" / "Kanaal niet gevonden" / "Geen video's gevonden") met een neptest-sleutel tegen
een onbereikbare API (geen willekeurig uitgaand verkeer in deze sandbox), `youtube-playlist`
rendert een echte iframe zonder netwerkaanroep nodig te hebben. Instellingenscherm getest
(save + encryptie-roundtrip, zelfde als Golf 10). Volledige regressiesweep (19 routes, incl.
alle vier OAuth-login-redirects) — alles 200/302, geen enkele breuk.

---

## [1.20.0] — 2026-09-29 — Golf 10: OAuth-login met Google, Discord, Battle.net en Twitch

Aanleiding: "ik wil de mogelijkheid in te loggen met google discord battlenet en twitch
voorbereid en dan api en keys met instructie hoe en waar in te voegen en waar te halen".
Discord en Twitch hadden al een werkende OAuth-basis (sinds Sprint 4-5), maar zonder
Google/Battle.net, zonder "inloggen met Twitch" (alleen "koppelen"), en — belangrijkste gat —
zonder ENIGE admin-UI om een client_id/secret in te vullen: dat moest tot nu toe met kale SQL.
Deze golf lost alle vier tegelijk op, inclusief twee bugs die pas zichtbaar werden tijdens het
bouwen ervan.

### Nieuw — generiek instellingenscherm voor elke OAuth-provider

`/admin/marketplace/package/{slug}/instellingen` (nieuw: `ModuleSettingsController` +
`views/module_settings.php`) leest het `settings`-schema uit `modules/{slug}/module.json` en
rendert er automatisch een formulier voor — werkt dus meteen voor Discord, Twitch, Google
ÉN Battle.net zonder 4x hetzelfde scherm te hoeven bouwen, en voor elke toekomstige module die
een `settings`-blok declareert. Elk scherm toont bovendien provider-specifieke uitleg: waar je
de client-ID/secret vandaan haalt (Discord Developer Portal, Twitch Developer Console, Google
Cloud Console, develop.battle.net) en welke exacte redirect-URI je daar moet whitelisten — het
`redirect_uri`-veld wordt voorgevuld met de berekende waarde (`APP_URL` + `/auth/{slug}/callback`)
zodat je 'm letterlijk kunt kopiëren.

Bereikbaar via een nieuwe "🔑 Providers & API-instellingen"-kaart bovenaan `/admin/marketplace`
(zichtbaar zodra minstens één ingeschakelde module een settings-schema heeft) én via een
⚙️-icoon per rij in het "Geïnstalleerd"-tabblad.

**Bug ontdekt tijdens het bouwen hiervan**: dat ⚙️-icoon zou in de praktijk nooit verschijnen
voor Discord/Twitch/Google/Battle.net, want `MarketplaceController`'s "Geïnstalleerd"-tabblad
leest `cf_marketplace_installed` — een tabel die ALLEEN gevuld wordt door de ZIP-download/
upload-flow van de Marketplace zelf. Modules die via de installer (Step5.php) of rechtstreeks
via `cf_modules` zijn ingeschakeld — de normale weg voor elke module die al in `modules/`
meegeleverd wordt — komen daar nooit in terecht. Vandaar de losstaande "Providers &
API-instellingen"-kaart, die `cf_modules` rechtstreeks uitleest (dezelfde tabel die
`Application::loadModules()` ook echt gebruikt) i.p.v. te vertrouwen op die marketplace-tracking.

### Nieuw — echte encryptie voor opgeslagen instellingen (`Core\Security\Crypto`)

`cf_settings.type` had al een `'encrypted'`-enumwaarde in het schema staan, maar
`SettingsRepository::set()`/`get()` deden er NOOIT iets mee — een client_secret zou dus in
platte tekst in de database hebben gestaan zodra het nieuwe instellingenscherm 'm opsloeg. Een
nieuwe gedeelde helperklasse `Core\Security\Crypto` (AES-256-GCM op basis van `APP_KEY`) lost
dit op; `SettingsRepository::set()` accepteert nu een optioneel `$type`-argument en versleutelt
bij `'encrypted'`, `getGroup()`/`get()` ontsleutelen automatisch terug. `OAuthClient`'s eigen,
tot nu toe gedupliceerde `encrypt()`/`decrypt()` (voor `cf_user_oauth`-tokens) delegeren nu naar
dezelfde klasse i.p.v. een eigen kopie te onderhouden.

**Tweede bug, zelfde familie**: `DiscordOAuthController::getSetting()` en de oude
`TwitchOAuthController::getSetting()` gaven een `'encrypted'`-waarde altijd RUW terug — een
client_secret ingevuld via het nieuwe scherm zou dus letterlijk de versleutelde blob naar
Discord/Twitch gestuurd hebben i.p.v. het echte geheim. Beide `getSetting()`-methodes
ontsleutelen nu terecht via `Crypto::decrypt()` wanneer de kolom `type = 'encrypted'` is
(round-trip live getest, zie onderaan).

### Nieuw — twee losse OAuth-modules: Google en Battle.net

`modules/google/` en `modules/battlenet/` (elk: `module.json`, `*OAuth.php` extends de
bestaande abstracte `OAuthClient`, `*OAuthController.php`, `*Module.php`) — gebouwd door twee
parallelle subagents in geïsoleerde git-worktrees, exact naar het bestaande Discord-patroon
(link/login-intentie via de sessie, `AuthManager::findOrCreateFromOAuth()` voor account-aanmaak
zonder wachtwoord), daarna zelf samengevoegd en aangevuld (`composer.json`'s PSR-4-mapping,
die per module apart staat — geen wildcard-autoloading in deze codebase — misten beide agents
terecht als buiten hun bereik).

- **Google**: OpenID Connect (`accounts.google.com` → `oauth2.googleapis.com` →
  `openidconnect.googleapis.com/v1/userinfo`), scope `openid email profile`,
  `prompt=select_account` zodat een gebruiker met meerdere Google-accounts altijd kan kiezen.
- **Battle.net**: regio-gebonden (`{eu|us|kr|tw}.battle.net/oauth/...`, `region`-instelling,
  default `eu`), geeft bewust geen e-mailadres terug — `AuthManager::uniqueEmailFrom()` had die
  placeholder-fallback al (gebouwd voor Discord's eigen scope-afhankelijke e-mail), dus geen
  extra werk nodig. BattleTag wordt overgenomen als `display_name` als die nog leeg is.

### Fix — Twitch had geen "inloggen met", alleen "koppelen"

`TwitchOAuthController` eiste altijd een ingelogde sessie in zijn callback — er was dus geen
manier om via Twitch een nieuw account te krijgen of in te loggen, in tegenstelling tot Discord
(dat dit al sinds Sprint 4 had). Nu exact hetzelfde link/login-intentiepatroon als Discord
(`oauth_intent_twitch` in de sessie), plus de ontbrekende `/auth/twitch/disconnect`-route (de
controller-methode bestond al, was alleen nooit geregistreerd).

### Login- en profielscherm — alle vier providers, niet meer hardcoded op Discord

`themes/{default,gaming-dark}/templates/auth/login.twig`: knoppen voor alle vier providers
i.p.v. alleen Discord. `.../users/profile.twig`: "gekoppelde accounts"-sectie en "koppelen"-
knoppen zijn generiek gemaakt (loop over `oauth_providers` vanuit `ProfileController`) i.p.v.
een hardcoded if-blok per provider — een vijfde provider in de toekomst vereist dus geen
twig-wijziging meer, alleen een entry in die ene array. `LoginBlock.php` (het compacte
sidebar-loginblok) kreeg een kleine iconenrij met dezelfde vier providers.

### Installer

`installer/templates/step5.php` bouwt de moduleselectie al dynamisch op uit `modules/*/
module.json` (geen wijziging nodig) — Google en Battle.net verschijnen daar dus automatisch;
alleen de icoontjes (🔑 / 🌀) zijn toegevoegd. `InstallerCore.php`'s `config.php`-sjabloon
kreeg entries voor beide nieuwe providers, met een verduidelijkende opmerking dat dit
`'oauth'`-blok NOOIT gelezen wordt door de applicatie (ontdekt tijdens deze golf) — de enige
werkende plek is cf_settings via het nieuwe instellingenscherm. `.env.example` kreeg dezelfde
waarschuwing: de `DISCORD_*`/`TWITCH_*`/`GOOGLE_*`/`BATTLENET_*`-variabelen daar zijn NOOIT
door de applicatie gelezen (bevestigd met een repo-brede grep) en dienen puur als overzicht.

### Live getest (scripted install + PHP-server + curl, zelfde methodiek als Golf 9)

Alle vier `/auth/{provider}/login`-redirects gecontroleerd op de juiste authorize-URL, client_id
en `redirect_uri` (incl. Battle.net regio-wissel eu→us); instellingenscherm getest voor
discord/google/battlenet (GET rendert schema + provider-uitleg, POST slaat op); encryptie-
round-trip bevestigd (DB toont alleen een AES-blob, de echte OAuth-redirect toont het juiste
plaintext client_id/secret-effect); "leeg laten = behouden"-gedrag voor encrypted velden
bevestigd; "Providers & API-instellingen"-kaart en het ⚙️-icoon in Marketplace bevestigd
zichtbaar voor installer-tijd ingeschakelde modules; login-/profielpagina tonen alle vier
knoppen; volledige regressiesweep (20 routes, incl. `/admin/marketplace` in alle vier tabs) —
alles 200/302 zoals verwacht, geen enkele breuk.

### Bekend, bewust buiten scope van deze golf

- Discord/Twitch/Google/Battle.net kunnen nog niet écht tegen de live provider getest worden
  in deze sandbox (geen uitgaand verkeer naar willekeurige domeinen) — de redirect-opbouw, de
  encryptie-roundtrip en de callback-dispatch zijn wel volledig live geverifieerd.
- Geen "meerdere Battle.net-regio's tegelijk"-ondersteuning (één module-instantie = één regio,
  zoals in het instellingenscherm ook uitgelegd wordt).

---

## [1.19.0] — 2026-09-29 — Wave 9: vier losse gaten parallel gedicht — $_ENV['APP_URL'], thema-kleuren, News-categorieën, contact-meldingsadres

Aanleiding: "geef een overzicht van wat nog te doen is" + "iedereen aan het werk" — na het
overzicht (zie CHANGELOG-geschiedenis + README-roadmap) bleken er vier kleine, onafhankelijke
gaten te zitten die zich goed leenden voor parallelle uitvoering: twee zijn zelf gefixt, twee
zijn gedelegeerd aan aparte, geïsoleerde subagents (elk in een eigen git-worktree, zodat ze
niet dezelfde bestanden tegelijk konden wijzigen) en na afloop gereviewd, samengevoegd en
centraal live getest.

### Fix 1 — `$_ENV['APP_URL']` bleek op 4 plekken leeg te zijn, niet 2

Eerder (v1.18.0-noot) waren 2 bekende plekken gedocumenteerd (`ThemeManager::url()`,
`TwitchStreamBlock`'s embed-`parent`). Een `grep` over de hele codebase vond er nog 2 meer, en
serieuzer: `DiscordOAuthController` en `TwitchOAuthController` gebruiken `$_ENV['APP_URL']`
voor hun OAuth `redirect_uri`. Op elke omgeving waar php.ini's `variables_order` geen `E`
bevat — en dat is al sinds PHP 5.4 niet meer de standaardwaarde, dus dit raakt vermoedelijk
niet alleen deze sandbox — is `$_ENV` altijd leeg, dus stond de Discord/Twitch OAuth
`redirect_uri` altijd op een lege basis-URL (`/auth/discord/callback` i.p.v.
`https://site.nl/auth/discord/callback`), wat login via Discord/Twitch met een
redirect_uri-mismatch zou laten mislukken. `Application::boot()` had al een bewezen, identiek
patroon voor exact dit probleem met `APP_KEY`/`JWT_SECRET` (config → `$_ENV` synchroniseren
bij elke boot) — dat patroon is nu met één extra regel uitgebreid naar `APP_URL`, wat alle 4
call sites in één keer fixt zonder die bestanden zelf aan te raken. **Niet live te
verifiëren**: dat vereist een echte Discord/Twitch OAuth-app-registratie met een publiek
bereikbare callback-URL, wat in deze sandbox niet mogelijk is — wel bevestigd dat de
config→\$_ENV-sync zelf werkt (zelfde mechanisme dat `APP_KEY`/`JWT_SECRET` al jarenlang
betrouwbaar doet).

### Fix 2 — Thema-wissel deed tot nu toe LETTERLIJK NIETS zichtbaars

Grondiger dan gedacht: het was niet alleen "kleuren worden niet toegepast" (zoals eerder
gedocumenteerd) — `gaming-dark`'s `templates/` waren al sinds deze sessie's Wave 7 byte-voor-
byte identiek aan `default`'s (zelf zo gemaakt tijdens het zone-werk), `gaming-dark/assets/css/`
bevat alleen een `.gitkeep`, en de `asset()`-Twig-functie verwijst altijd naar het gedeelde
`/assets/`, nooit naar een thema-eigen map. Drie onafhankelijke oorzaken die er samen voor
zorgden dat thema-wissel nooit één pixel veranderde. Oplossing: `theme.json`'s `colors`-blok
werd al wél als Twig-global ingelezen maar nergens gebruikt — `blueprint.css` is al volledig op
CSS custom properties gebouwd, dus in plaats van een aparte stylesheet per thema volstaat een
kleine, geconditioneerde `<style>:root{...}</style>`-override in `layout.twig` (beide thema's,
zelfde bestand toevallig al identiek) die de custom properties overschrijft met de waarden uit
het actieve thema's eigen `theme.json`. Live bewezen: homepage met `default` toont
`--accent:#6c3df4` (paars), na omschakelen naar `gaming-dark` via `cf_settings.active_theme`
toont dezelfde homepage `--accent:#22c55e` (groen) — een geplaatst blok, de header, alles
kleurt daadwerkelijk mee.

### Fix 3 — News-categorieën beheerscherm (nieuw, `/admin/news/categories`)

Zelfde gat dat Wave 4 (v1.14.0) al dichtte voor Forumborden bestond identiek voor News: geen
enkele manier om een categorie aan te maken/hernoemen/herordenen/verwijderen behalve
rechtstreeks SQL. Nieuw: `CategoryAdminController` (1:1 patroon van
`Forum\BoardAdminController`), `NewsRepository`-uitbreiding (`getAllCategoriesForAdmin()`,
`findCategoryById()`, `categorySlugTaken()`, `countArticlesInCategory()`,
`createCategory()`/`updateCategory()`/`deleteCategory()`), twee nieuwe views, en 6 nieuwe
routes onder `news.create`-permissie. **Bewuste afwijking van het Forum-patroon**:
`cf_forum_topics.board_id` staat op `ON DELETE CASCADE` (vandaar dat Forumborden-verwijderen
geblokkeerd wordt zolang er topics zijn), maar `cf_news.category_id` staat al op
`ON DELETE SET NULL` — een categorie verwijderen kan dus nooit artikelen vernietigen, alleen
ontkoppelen. Daarom blokkeert dit scherm verwijderen niet, maar toont wél het aantal
gekoppelde artikelen vooraf én in een JS-bevestiging. Live bewezen: categorie aangemaakt via
het echte formulier, een testartikel eraan gekoppeld via SQL, categorie verwijderd via de
echte UI — het artikel bleef gewoon bestaan met `category_id = NULL`, precies zoals ontworpen.

### Fix 4 — Contactformulier: instelbaar meldingen-e-mailadres

`ContactController::notifyAdmin()` stuurde altijd naar `Mailer::getFromAddress()` — er was geen
apart in te stellen "meldingen naar"-adres, en de code-comment zei letterlijk dat dit kwam
omdat `/admin/settings` een statische pagina was. Dat klopte niet meer sinds v1.18.0 (vorige
wave, dezelfde sessie) — deze fix haalt dat in: nieuwe `cf_settings`-rij (group `contact`, key
`notify_email`), nieuwe sectie "Contactformulier" in het bestaande, al-werkende
`/admin/settings`-formulier (leeg toegestaan, anders `FILTER_VALIDATE_EMAIL`), en
`ContactController` krijgt `SettingsRepository` via DI (auto-resolved door de container, geen
handmatige wiring nodig) en leest het ingestelde adres vóór de oude fallback. Live bewezen:
formulier ingediend met een geldig adres → opgeslagen in `cf_settings`; met een ongeldig adres
→ `?error=`-redirect zonder de oude waarde te overschrijven; het publieke contactformulier zelf
ingediend en het bericht kwam correct in `cf_contact_messages` terecht (mailversturen zelf
faalt stil op `sendmail: not found` — verwacht in deze sandbox zonder MTA, geen regressie).

### Werkwijze — twee parallelle subagents, centraal samengevoegd

Fix 3 en Fix 4 zijn gebouwd door twee losse subagents, elk in een eigen geïsoleerde
git-worktree (zodat ze nooit dezelfde bestanden tegelijk konden wijzigen — hun bestandensets
overlapten toevallig ook niet). Elke agent kreeg het exacte referentiepatroon om te volgen (zie
hierboven), mocht niet aan `CHANGELOG.md`/`README.md` komen en niet zelf committen — alleen
`php -l` en kritische zelf-review. Na afloop is elke diff hier gelezen en beoordeeld vóór
samenvoegen (o.a. gecontroleerd of `insert()`/`update()`/`delete()`-helpers echt de `cf_`-prefix
toevoegen, en of CSRF-velden in élke `<form>` zaten) — beide leverden correcte, goed
gedocumenteerde code af. Daarna is alles in één sessie samen live getest (verse installatie,
inloggen, elk scherm écht bediend via HTTP) en in één keer gedocumenteerd/gecommit, zodat
CHANGELOG/README niet in losse, elkaar overlappende delta's uiteenvallen.

### Nog open

- OAuth-`redirect_uri`-fix (Fix 1) kon niet live tegen echte Discord/Twitch-apps getest worden.
- `composer.lock` blijft geblokkeerd (ongewijzigd — zie eerdere versies).
- SMTP-instelvelden in de installer (Stap 3) — bewust buiten scope gehouden voor Fix 4, blijft
  een apart vervolgpunt.
- S10 (YouTube + Kick-integratie), Rust/Ark gaming-modules (wel in de projectblauwdruk genoemd,
  nooit gepland), S11 (media-galerij), S12 (premium/licenties), S13 (i18n) — zie README-roadmap.

---

## [1.18.0] — 2026-09-29 — Wave 8: site-instellingen écht bewerkbaar — naam, MOTD, favicon, taal, tijdzone

Aanleiding: expliciet verzoek van de gebruiker — sitenaam, website-icoon (favicon), de MOTD/
slogan onder de sitetitel, en de sitetitel zelf moeten via een echt werkend adminscherm
aanpasbaar zijn.

### Bevinding — `/admin/settings` was 100% niet-functioneel

`Settings\AdminController` injecteerde nergens een `SettingsRepository`; `settings()` deed
alleen `include views/settings.php` zonder enige data. De view zelf was volledig statische
HTML zonder `<form>`, en verwees de beheerder naar `config/config.php` (buiten webroot, geen
UI-toegang) en `/installer/` (bestaat na installatie niet meer — zie `Step5.php`, die de map
hernoemt/vergrendelt). Er bestond dus geen enkele manier om sitenaam, taal, tijdzone of
iets anders na installatie te wijzigen zonder rechtstreeks in de database te werken. Een
tweede, onafhankelijke bevinding: `cf_settings` had helemaal geen `site_motd`- of
`site_icon`-sleutel — die functionaliteit bestond nergens in de codebase, niet eens als
kolom-restant. Dit is dus een nieuwe feature, geen bugfix van iets dat ooit werkte.

### Added

- **`Settings\AdminController::settings()`** injecteert nu `SettingsRepository`, laadt de
  volledige `core`-instellingengroep en geeft die door aan de view, samen met `?error=`/
  `?ok=`-querystring-feedback.
- **`Settings\AdminController::updateSettings()`** (nieuw, `POST /admin/settings`, achter
  `auth` + `can:settings.edit` + CSRF): valideert en slaat `site_name` (verplicht),
  `site_motd`, `site_description`, `default_locale` (whitelist nl/en) en `timezone` op via
  `SettingsRepository::set('core', ...)`. Icoon-upload via de bestaande DI-`UploadManager`
  (subdir `branding`, whitelist jpg/png/gif/webp, max 5MB — dezelfde instantie die
  avatar-uploads al gebruikt) opgeslagen als `/media/branding/<hash>.<ext>` in
  `cf_settings.site_icon`. Een `remove_icon`-checkbox wist het veld. In beide gevallen ruimt
  een nieuwe `cleanupOldIcon()`-helper het oude bestand op — maar uitsluitend als het pad
  met `/media/branding/` begint (nooit een extern OAuth-avatar-URL verwijderen, zelfde
  voorzichtigheidspatroon als `ProfileController::updateAvatar()`). Elke wijziging wordt
  gelogd via `AuditLogger::log('settings.update', ...)`.
- **`src/Modules/Settings/views/settings.php`** volledig herschreven als een echt formulier
  (`enctype="multipart/form-data"`, `CsrfProtection::field()`), met de gedeelde
  `admin_sidebar.php`/`admin_styles.php`-partials (zelfde patroon als Rollen/Media/Blokken
  uit eerdere waves) in plaats van de oude, losstaande hand-uitgeschreven sidebar/CSS.
  Velden: sitenaam, MOTD/slogan, SEO-omschrijving, icoon-upload met live voorvertoning +
  "verwijderen"-checkbox, standaardtaal, tijdzone.
- **Favicon + MOTD nu écht zichtbaar op de site.** `themes/default/templates/layout.twig`
  (en de identieke `gaming-dark`-variant) renderen nu `<link rel="icon" href="{{
  settings.site_icon }}">` in de `<head>` (alleen als ingesteld), en het header-logo is
  omgezet van een platte `<a class="cf-logo">` naar `<a class="cf-logo-wrap"><span
  class="cf-logo">...</span><span class="cf-motd">...</span></a>` — de MOTD-regel verschijnt
  alleen als `settings.site_motd` niet leeg is. `settings` is hetzelfde Twig-global dat al
  `getGroup('core')` bevat (`Application.php`, Wave 6/7), dus `site_motd`/`site_icon` waren
  na deze wave direct beschikbaar zonder verdere wiring.
- **CSS**: `#cf-header` van vaste `height: 64px` naar `min-height: 64px` (met
  `padding-block`) zodat een tweeregelige titel+MOTD niet overflowed; nieuwe
  `.cf-logo-wrap` (flex-kolom) en `.cf-motd` (klein, gedempt, geen gradient) regels.

### Getest (live, sandbox-installatie — zie onderstaande noot over vendor/)

- Fris geïnstalleerd (schema-import + admin `BigBoss`/`LiveCheck123!`), ingelogd, formulier
  ingediend met echte waarden + een echt gegenereerde 32×32 PNG als icoon.
- `cf_settings` bevatte na opslaan exact de ingevoerde `site_name`/`site_motd`/
  `site_description`/`default_locale`/`timezone`, en `site_icon` = `/media/branding/<hash>.png`;
  bestand stond op disk in `storage/uploads/branding/`.
- Homepage `<title>`, `<link rel="icon">` en de nieuwe MOTD-regel onder het logo reflecteerden
  alle drie de opgeslagen waarden. `/media/branding/<hash>.png` gaf `200` met
  `Content-Type: image/png`.
- "Icoon verwijderen"-checkbox: `cf_settings.site_icon` werd leeggemaakt EN het bestand op
  disk werd daadwerkelijk verwijderd (geen wees-bestanden in `storage/uploads/branding/`).
- CSRF-gating: POST met een vervalst token → `403` (bevestigt dat de CsrfProtection-fix uit
  v1.15.0 nog steeds correct werkt voor dit nieuwe endpoint).
- Rechten-gating: een los aangemaakte `member`-testgebruiker (geen `settings.edit`) kreeg
  `403` op zowel de GET als impliciet de POST-route.
- Regressie-sweep van 14 publieke + admin-routes (`/`, `/news`, `/blog`, `/forum`,
  `/downloads`, `/contact`, `/admin`, `/admin/news`, `/admin/pages`, `/admin/media`,
  `/admin/blocks`, `/admin/roles`, `/admin/users`, `/admin/modules`) — allemaal `200`,
  behalve `/admin/modules` dat bewust naar `/admin/marketplace` redirect (bestaand gedrag,
  geen regressie).
- **Noot over de sandbox-omgeving**: `composer.lock`/`vendor/` genereren blijft geverifieerd
  onmogelijk in deze sandbox (`repo.packagist.org` staat niet op de proxy-allowlist — zelfde,
  nog altijd openstaande blokkade als eerder gedocumenteerd). Voor deze live-test is een
  minimale `vendor/` handmatig samengesteld (Twig + psr/container + psr/simple-cache via
  `git clone` van hun publieke GitHub-repo's, die in deze sandbox wél bereikbaar zijn, plus
  een 6-regelige losse `trigger_deprecation()`-shim voor `symfony/deprecation-contracts`) —
  geverifieerd via een grep dat dit werkelijk de enige drie externe namespaces zijn die de
  broncode importeert. Niet gecommit (`vendor/` staat al in `.gitignore`); een echte
  installatie moet nog altijd `composer install` draaien.

### Nog open

- `composer.lock` kan nog steeds niet gegenereerd worden in deze sandbox (ongewijzigd, zie
  hierboven en eerdere versies).
- `$_ENV['APP_URL']` wordt nog op twee plekken gebruikt (`ThemeManager::registerFunctions()`'s
  `url()`-functie, `TwitchStreamBlock`'s embed-`parent`-parameter) terwijl `$_ENV` in deze
  omgeving altijd leeg is (`variables_order=GPCS`, geen `E`) — bekend, nog niet gefixt, raakt
  dit scherm niet.

---

## [1.17.0] — 2026-09-29 — Wave 7: de 2 openstaande gaten uit v1.16.0 gedicht — alle 6 zones + rol-zichtbaarheid

Aanleiding: "ga verder met de volgende stappen" — v1.16.0 loste het Blokkensysteem zelf op
maar liet eerlijk twee gaten open: maar 2 van de 6 layout-zones waren echt dynamisch, en
`cf_blocks.visibility_roles` werd nergens gefilterd. Beide nu gedicht.

### Added / Fixed

- **Alle 6 layout-zones zijn nu echte drag&drop-zones**, niet alleen sidebar_left/right.
  `themes/default/templates/layout.twig` (en de identieke `gaming-dark`-variant) hebben
  nu ook `zones.header`, `zones.topmenu` en `zones.footer`-render-loops, náást (niet in
  plaats van) de bestaande vaste site-chrome (logo, hoofdmenu, footer-links).
  `#cf-header` zelf heeft een vaste hoogte van 64px (logo/nav/acties in één rij) — blokken
  daar rechtstreeks inpersen zou overflowen bij willekeurige blok-inhoud, dus header- en
  topmenu-blokken krijgen elk hun eigen, los-hoge balk erónder (`#cf-header-blocks`,
  `#cf-topmenu`). De footer had al flexibele hoogte, dus daar volstond een sectie binnen
  de bestaande `<footer>`. CSS toegevoegd aan `public/assets/css/blueprint.css`.
- **`cf_blocks.visibility_roles` wordt nu daadwerkelijk gefilterd.**
  `BlockRegistry::getZoneBlocks()` cacht het volledige, ongefilterde resultaat per zone
  (120s, gedeeld over alle bezoekers) — filteren op de ingelogde gebruiker vóór die cache
  zou de cache per-gebruiker besmetten. Gefixt door in `Application::boot()`, ná de
  cache-fetch, elke zone te filteren op de rol-IDs van de huidige bezoeker (leeg voor
  gasten — laat automatisch alleen blokken zonder restrictie door).

### Getest (live HTTP + database)

- Een HTML-blok geplaatst in elk van de 3 nieuwe zones (`header`/`topmenu`/`footer`) via
  de echte admin-UI → na cache-clear staan alle drie daadwerkelijk in de homepage-HTML,
  op de verwachte plek (`#cf-header-blocks`, `#cf-topmenu`, `#cf-footer-blocks`).
- Rol-zichtbaarheid: een footer-blok met `visibility_roles = [1]` (super_admin) →
  **onzichtbaar voor een anonieme bezoeker**, **zichtbaar voor de ingelogde super_admin**
  — beide met dezelfde, niet-verlopen cache, wat bevestigt dat de filtering ná de cache
  gebeurt en niet per ongeluk de gedeelde cache zelf raakt.
- Regressietest: `/`, `/news`, `/admin`, `/admin/blocks`, `/forum`, `/admin/roles`,
  `/admin/themes` geven allemaal nog 200 — geen Twig-fouten door de layout-wijziging.

---

## [1.16.0] — 2026-09-29 — Wave 6: het Blokkensysteem (Kernprincipe #3) bleek nog nooit te werken — 3 samenhangende bugs gefixt

Aanleiding: "ga verder" op de standing "maak alles af"-opdracht. Met alle 6 placeholder-
schermen klaar (v1.15.0) leek `/admin/blocks` de volgende logische stap. Bij het live testen
ervan (dezelfde methode als elke eerdere wave: echte HTTP-boot + echte MariaDB, een block
daadwerkelijk plaatsen en op de site proberen terug te zien) bleek het **hele
drag&drop-blokkensysteem — Kernprincipe #3 uit de projectblueprint, aanwezig sinds
Sprint 1 — nog nooit één keer gewerkt te hebben**, door drie los van elkaar ontdekte,
samenwerkende bugs.

### Gevonden en gefixte bugs

1. **`/admin/blocks`'s eigen "blok toevoegen"-knop kon nooit een geldig CSRF-token
   versturen.** De pagina's JavaScript las
   `document.querySelector('meta[name="csrf"]')?.content`, maar nergens op de pagina
   staat een `<meta name="csrf">`-tag — die bestond simpelweg niet. `CSRF` was daardoor
   altijd een lege string en elke fetch()-actie (blok toevoegen/verwijderen/herordenen)
   werd afgewezen door `CsrfProtection::validateRequest()`. Vóór de CSRF-403-bugfix uit
   v1.15.0 kwam dit als een verwarrende generieke 500 naar buiten; ná die fix als een
   (nog steeds onterechte) 403 — in beide gevallen zonder dat de knop ooit iets deed.
   Gefixt door hetzelfde, wél werkende patroon over te nemen dat
   `Marketplace/views/index.php` al gebruikt: een verborgen `_csrf_token`-input
   (`CsrfProtection::field()`) en een JS-selector die daarnaar zoekt.
2. **`BlockRegistry::syncTypesToDatabase()` bestond al sinds Sprint 1, maar werd
   nérgens aangeroepen.** `cf_block_types` — de tabel die `/admin/blocks` nodig heeft om
   een blok-type-slug naar een database-ID te vertalen — bleef daardoor na een verse
   installatie permanent leeg, voor zowel de 6 ingebouwde blocks (Text/Html/News/Login/
   Stats/Ad) als elk block van een module (Discord, Twitch, ...). Zelfs mét een geldig
   CSRF-token faalde `BlockController::store()` dus alsnog, met "Block type niet in DB
   geregistreerd." (eveneens als generieke 500 vóór de v1.15.0-fix). Gefixt: een nieuwe
   kernmodule `blocks` (schema.sql) is nu eigenaar van de 6 ingebouwde block-types, en
   `Application::boot()` roept `syncTypesToDatabase()` nu aan — éénmaal voor de core
   blocks, en opnieuw na het boot()en van elke module (idempotent per slug: `ON DUPLICATE
   KEY UPDATE` raakt `module_id` niet aan, dus herhaald aanroepen over de gedeelde
   registry is veilig).
3. **De ergste van de drie: `ThemeManager::setBlockRegistry()` zette de Twig-global
   `zones` hard op een lege array — en niets overschreef dat ooit.** `layout.twig`'s
   `{% if zones.sidebar_left is defined and zones.sidebar_left %}` was hierdoor
   permanent `false`. Zelfs ná het fixen van bug 1 en 2 — dus met een succesvol in
   `cf_blocks` geplaatst blok — verscheen er nog steeds niets op de site: `zones` was
   simpelweg nooit gevuld met échte data uit `BlockRegistry::getZoneBlocks()`. Dit is
   dezelfde categorie bug als de al gedocumenteerde `auth`/`settings`/`menu_pages`-fix
   (zie `ThemeManager::addGlobal()`'s docblock) — Twig faalt niet hard op een undefined
   of leeg global, dus dit bleef stil. Gefixt door `Application::boot()` de 6
   layout-zones (`header`, `topmenu`, `sidebar_left`, `content`, `sidebar_right`,
   `footer`) nu ook echt te vullen via `BlockRegistry::getZoneBlocks()`, in dezelfde
   plek waar `auth`/`settings`/`menu_pages` al gewired worden.

### Getest (live HTTP + database + daadwerkelijke pagina-inspectie)

- Verse installatie → `cf_block_types` bevat na de eerste request meteen alle 6 core
  blocks, elk gekoppeld aan de nieuwe `blocks`-kernmodule.
- Ingelogd als BigBoss, blok "Tekst" toegevoegd aan `sidebar_right` via de echte
  `/admin/blocks`-pagina-flow (CSRF-token uit de nu wél aanwezige hidden input) → 302,
  rij in `cf_blocks`.
- **Homepage-HTML na cache-clear bevat daadwerkelijk `<aside id="cf-sidebar-right">`
  met het geplaatste blok erin** — het allereerste bewijs ooit dat een via de admin-UI
  geplaatst blok ook echt op de site verschijnt.
- Blok verwijderd via de admin-route (niet rechtstreeks SQL) → cache correct
  geïnvalideerd, blok direct weg van de homepage.
- **Bijvangst, eerlijk gedocumenteerd i.p.v. verzwegen**: van de 6 zones uit de
  projectblueprint zijn in de huidige `layout.twig` alleen `sidebar_left` en
  `sidebar_right` daadwerkelijk dynamische block-zones — `header`, `topmenu` en
  `footer` zijn vaste HTML (logo, hoofdmenu, footer-links) zonder een
  `{% for block in zones.X %}`-lus. Een blok in `header`/`topmenu`/`footer` plaatsen
  slaagt in de database maar verschijnt nergens; dit is geen bug in de drie fixes
  hierboven, maar een aparte, nog openstaande scope-stap om ook die drie zones echt
  drag&drop-baar te maken. Zie README "Bekende beperkingen".

### Nog open

Alle 6 layout-zones uit de blueprint drag&drop-baar maken (nu 2/6: sidebar_left/right).
`visibility_roles` op `cf_blocks` wordt door `BlockRegistry::getZoneBlocks()` nog niet
gefilterd — elk geplaatst blok is voor iedereen zichtbaar, ongeacht de kolom.
`composer.lock` blijft onmogelijk te genereren in deze sandbox (geen internettoegang).

---

## [1.15.0] — 2026-09-29 — Wave 5: laatste 5 placeholder-schermen gebouwd (Roles, Menus, Logs, Themes, Media)

Aanleiding: "vervolg alle logische stappen die nog moeten en maak af" — de 5 resterende
placeholder-admin-schermen uit v1.10.0 (zie README "Bekende beperkingen") waren de laatste
openstaande post op de roadmap. Elk scherm is met dezelfde methode gebouwd en live
geverifieerd als v1.12.0–v1.14.0: echte HTTP-boot tegen een echte MariaDB, geen mocks.

### Added — nieuw: audit-logging

- **`cf_audit_log`-tabel + `AuditLogger`** (`src/Core/Audit/AuditLogger.php`): centrale,
  kleine service (`log()`, `getRecent()`, `count()`, `distinctActions()`) die alle
  gevoelige admin-acties vastlegt (wie, wat, wanneer, IP, JSON-context). Aangesloten op
  `AuthManager` (login/mislukte login — verving een losse `storage/logs/auth.log`-schrijf-
  actie die nergens werd uitgelezen), `UserAdminController`, `BoardAdminController`,
  `ForumController` (pin/lock/verwijderen), `RoleAdminController`, `ThemeAdminController`,
  `MediaAdminController`. Dit is meteen de databron voor het nieuwe `/admin/logs`-scherm.

### Added — `/admin/roles` (Rollenbeheer)

- `RoleRepository` + `RoleAdminController`: rollen aanmaken/bewerken, permissiematrix
  (gegroepeerd) toewijzen, standaardrol instellen, verwijderen.
- **`super_admin` is hard vergrendeld**: de wildcard-permissie (`*`) kan niet via de UI
  weggehaald worden — zowel client-side (checkboxes disabled) als server-side (`update()`
  forceert de permissie-set terug naar `[*]` ongeacht wat is gepost).
- **Cascade-bescherming**: verwijderen wordt geblokkeerd zolang de rol nog gebruikers
  heeft, of als het een van de 5 kernrollen is (`super_admin`/`admin`/`moderator`/
  `member`/`guest`).
- Permissie: `roles.manage`.

### Added — `/admin/menus` (Menubeheer)

- Bestaande, gepubliceerde pagina's toevoegen aan/verwijderen uit het hoofdmenu en hun
  volgorde wijzigen (`PageRepository::addToMenu()`/`removeFromMenu()`/
  `swapMenuPosition()`), zonder de `menu_position`-kolom rechtstreeks in de database te
  hoeven bewerken. Permissie: `menus.manage`.

### Added — `/admin/logs` (Auditlog-viewer)

- Gepagineerde (30/pagina), filterbare (per actie) weergave van `cf_audit_log`, met
  kleurgecodeerde badges per actietype en de JSON-context leesbaar uitgeklapt.
  Permissie: `logs.view`.

### Added — `/admin/themes` (Thema-omschakeling)

- Scant `themes/*/theme.json` en schrijft de keuze naar `cf_settings('core',
  'active_theme')`, die nu voorrang krijgt boven de statische `config/config.php`-waarde
  (`Application.php`'s `ThemeManager`-singleton-factory raadpleegt eerst de database).
  Een tweede, echt thema **"Gaming Dark"** is toegevoegd (`themes/gaming-dark/`) om het
  omschakelmechanisme met iets anders dan het standaardthema te kunnen bewijzen.
  Permissie: `themes.manage`.
- **Eerlijk gedocumenteerde architecturale beperking**: `theme.json`'s `colors`-blok en de
  per-thema `assets/`-map worden nergens door de templates gebruikt — `asset()` in
  `ThemeManager` wijst altijd naar het globale `public/assets/`, thema-onafhankelijk.
  Omschakelen wisselt dus de Twig-**templates** (bewezen via een nieuw
  `data-theme-slug`/`-name`-attribuut op `<body>`), niet (nog) een kleurenschema. Dit
  scherm zegt dat ook met zoveel woorden tegen de beheerder i.p.v. het te verbergen.

### Added — `/admin/media` (Mediabeheer)

- Scant beide onafhankelijke opslag-roots (`storage/uploads/` — avatars, en
  `storage/downloads/` — de Downloads-module, elk met een eigen `UploadManager`-instantie)
  en kruist elk bestand tegen `cf_users.avatar_url` en `cf_downloads.file_path` zodat een
  bestand dat nog in gebruik is, niet per ongeluk verwijderd kan worden — de knop is dan
  geen formulier maar een uitgeschakelde placeholder, en de server blokkeert een
  geforceerde aanvraag ook zelf nog eens (zie "Gevonden bugs" hieronder — dat server-pad
  legde een onafhankelijke bug bloot). Permissie: `media.manage`.

### Gevonden en gefixte bugs (elk scherm legde er minstens één bloot)

- **`installer/InstallerCore.php::importSchema()`**: `explode(';', $schema)` zonder eerst
  SQL-commentaar te strippen — één `-- `-regel met een letterlijke puntkomma erin brak de
  hele import. Fix: volledige commentaarregels worden nu met een regex verwijderd vóórdat
  er gesplitst wordt.
- **RBAC-cache werd niet geïnvalideerd bij rolwijzigingen** — noch in het nieuwe
  `RoleRepository` (permissies aan een rol wijzigen), noch, bleek bij nader onderzoek, in
  het **al bestaande** `UserAdminController::update()` (een gebruiker een andere rol
  geven). Een al ingelogde gebruiker kon hierdoor tot 5 minuten (`RBACManager`'s 300s-TTL)
  met verouderde rechten blijven werken. Beide gefixt door na elke rolwijziging
  `RBACManager::clearUserCache()` aan te roepen voor elke betrokken gebruiker.
- **Rollenformulier schakelde de permissiematrix uit voor álle 5 kernrollen**, niet
  alleen `super_admin` — een `$isProtected`-vlag (die ook "naam niet wijzigbaar" en
  "niet verwijderbaar" betekent) werd hergebruikt voor "checkboxes uitschakelen", waardoor
  een beheerder de rechten van `admin`/`moderator`/`member`/`guest` niet via de UI had
  kunnen aanpassen. Gevonden vóórdat dit live getest werd, via codereview. Gefixt met een
  aparte `$isSuperAdmin`-variabele die alleen voor de checkbox-`disabled`-status gebruikt
  wordt.
- **CSRF-afwijzingen kwamen app-breed als generieke HTTP 500 naar buiten in plaats van
  403** — `CsrfProtection::validateRequest()` gooide een kale `\RuntimeException(...,
  403)`, maar `Application::handleException()` herkent alleen `instanceof HttpException`
  (de class die in v1.14.0/Wave 2 precies voor dit probleem is gebouwd, voor
  `AuthManager::authorize()`) en valt voor al het andere terug op 500. Dit trof **elk**
  formulier in de hele applicatie dat `CsrfProtection::validateRequest()` gebruikt, niet
  alleen Media — gevonden tijdens het live testen van het "verwijderen geblokkeerd
  (bestand nog in gebruik)"-pad van `/admin/media`, dat hierdoor zelf ook een onterechte
  500 gaf. Gefixt door `CsrfProtection` dezelfde `HttpException(..., 403)` te laten gooien
  als `AuthManager::authorize()`; live herbevestigd dat een CSRF-afwijzing nu overal een
  nette 403 geeft.

### Getest (live HTTP + database, alle 5 schermen)

- Permissiegrens: een testgebruiker zonder de betreffende `*.manage`-permissie krijgt
  overal **403**, zowel op het scherm zelf als op de actie-routes.
- Roles: permissiematrix bewerkt voor `admin`, database bevestigt; poging om
  `super_admin`'s wildcard via een geforceerde POST weg te halen → blijft `[*]`; rol met
  gebruikers eraan gekoppeld → verwijderen geblokkeerd met duidelijke foutmelding.
  Cache-invalidatie geverifieerd: rolwijziging direct zichtbaar voor een al ingelogde
  sessie, geen 5-minuten-vertraging meer.
- Menus: pagina toegevoegd/verwijderd/verplaatst, `menu_position` klopt na elke stap.
- Logs: paginering en actie-filter geverifieerd tegen echte `auth.login`/
  `forum.topic.delete`/etc.-rijen die de andere schermen deze wave al genereerden.
- Themes: omgeschakeld naar "Gaming Dark" → `data-theme-slug="gaming-dark"` zichtbaar in
  de HTML-output; terug naar "default" → bevestigd.
- Media: twee echte bestanden (een avatar-upload, een downloads-upload) correct als
  "In gebruik" gemarkeerd; verwijderen van een in-gebruik bestand → geblokkeerd (302 +
  foutmelding, bestand blijft staan — pas werkend ná de CSRF-bugfix hierboven);
  verwijderen van een ongebruikt bestand → 302 + bestand weg van schijf + `media.delete`
  in de auditlog.

### Nog open

Alle 6 oorspronkelijke placeholder-schermen uit v1.10.0 zijn nu echt: Users (v1.13.0),
Forum-bordbeheer (v1.14.0), en nu Roles/Menus/Logs/Themes/Media. Het thema-kleuren-gat
(hierboven) en het ontbrekende News-categorieënscherm (genoemd in v1.14.0) blijven staan
als bekende, eerlijk gedocumenteerde vervolgstappen. `composer.lock` kan nog steeds niet
in deze sandbox gegenereerd worden (geen internettoegang) — zie README.

---

## [1.14.0] — 2026-09-29 — Wave 4: Forum live geverifieerd + bordbeheer gebouwd

Aanleiding: de vraag "kunnen we het forum alvast klaarmaken zodat dat erin zit en werkt"
— het Forum-core-module (gebouwd in Wave 1) was nog nooit echt gebooted of getest.

### Getest (echte HTTP-boot + database, zelfde methode als v1.12.0/v1.13.0)

Het bestaande Forum-module (`ForumController`, `ForumRepository`, 4 Twig-templates) bleek
bij code-review al goed opgezet — en is als **eerste module deze sessie zonder enige bug**
door de live-verificatie gekomen:

- `GET /forum` (anoniem) → 200, toont bord "Algemeen".
- `GET /forum/algemeen` leeg → 200, "Nog geen topics".
- Ingelogd als BigBoss: topic aanmaken via `/forum/algemeen/nieuw` → 302, correcte
  auto-slug (`welkom-op-het-nieuwe-forum`).
- Tweede, echte gebruiker "TestMember" (via `/register`) plaatst een reactie → zichtbaar na
  herladen, `cf_forum_topics.reply_count` correct 0 → 1 (database-check).
- **Moderatie-permissiegrens**: TestMember (rol `member`, geen `forum.moderate`) krijgt
  **403** op pin/lock/verwijderen; BigBoss (super_admin, wildcard-permissie) kan alle drie
  — elk geverifieerd via zowel de HTTP-response als een directe databasecheck
  (`is_pinned`, `is_locked`, `deleted_at`).
- Gesloten topic toont "Dit topic is gesloten" en het reactieformulier verdwijnt.
- Verwijderd topic (soft delete) verdwijnt direct uit de publieke bordlijst.

### Added

- **`/admin/forum/boards` — Bordbeheer**, het enige echte gat dat de Forum-verificatie
  blootlegde: er was geen manier om een tweede forumbord aan te maken behalve met
  rechtstreekse SQL (`schema.sql` seedt alleen "Algemeen").
  - `ForumRepository`: `getAllBoardsForAdmin()`, `findBoardById()`, `boardSlugTaken()`,
    `createBoard()`, `updateBoard()`, `deleteBoard()`.
  - `BoardAdminController` (`src/Modules/Forum/BoardAdminController.php`): lijst,
    aanmaken, bewerken (naam/slug/omschrijving/positie), verwijderen. Permissie:
    `forum.moderate`.
  - **Cascade-bescherming**: `cf_forum_topics.board_id` heeft `ON DELETE CASCADE` naar
    `cf_categories` — zonder ingreep zou een bord verwijderen stilzwijgend alle topics
    én reacties erin meenemen. `deleteBoard()` wordt daarom alleen aangeroepen nadat
    `countTopics($id) === 0` is geverifieerd; anders krijgt de admin een duidelijke
    foutmelding ("bevat nog topics — verplaats of verwijder die eerst") i.p.v. dataverlies.
  - Sidebar-link "Forum" (ging voorheen naar de publieke `/forum`) gewijzigd naar dit
    nieuwe beheerscherm; de publieke forumlink blijft bereikbaar via "Bekijk site".

### Getest (bordbeheer, zelfde live-methode)

- TestMember krijgt 403 op `GET /admin/forum/boards` (geen `forum.moderate`).
- Nieuw bord "Aankondigingen" aangemaakt (auto-slug) → direct zichtbaar op publieke
  `/forum`-index naast "Algemeen".
- Bord hernoemd ("Aankondigingen" → "Mededelingen", slug behouden) → database bevestigt.
- Topic geplaatst in het nieuwe bord, daarna verwijder-poging → **geblokkeerd** met de
  verwachte foutmelding; bord bleef bestaan (database-check).
- Wegwerpbord zonder topics aangemaakt en verwijderd → lukt zoals verwacht, weg uit
  de database.
- TestMember krijgt 403 op de verwijder-route zelf (niet alleen op het scherm).

### Nog open (van de oorspronkelijke 6 placeholder-schermen uit v1.10.0)

`/admin/users` (v1.13.0) en nu de Forum-bordbeheer-aanvulling zijn echt. Nog steeds
placeholder: Media, Roles, Themes, Menus, Logs — zie README "Bekende beperkingen".
Dezelfde ontbrekende-beheerscherm-situatie geldt overigens ook voor News-categorieën
(`cf_categories` met `type='news'`) — niet in scope van dit verzoek, maar wel een
vergelijkbaar gat voor een volgende wave.

---

## [1.13.0] — 2026-09-28 — Wave 3: /admin/users echt gebouwd (eerste van de 6 placeholder-schermen)

### Added

- **`/admin/users` — volledig werkend Gebruikersbeheer**, ter vervanging van de "nog niet
  gebouwd"-placeholder uit v1.10.0 (`src/Modules/Settings/views/users.php`, nu verwijderd).
  - `UserRepository` (`src/Modules/Users/UserRepository.php`): gepagineerde lijst met
    zoeken op username/e-mail/naam, rol-toewijzing lezen/schrijven (`syncRoles()`,
    transactioneel), account activeren/deactiveren, soft-delete. Paginering gaat via
    `Connection::execute()`'s `bindValue()`-pad, dezelfde die de v1.11.0 LIMIT/OFFSET-bug
    fixte — hier dus vanaf het begin goed.
  - `UserAdminController` (`src/Modules/Users/UserAdminController.php`): `index()`
    (lijst+zoeken), `editForm()`/`update()` (rollen-checkboxes + actief-toggle).
    Permissie: `users.manage` (al sinds Wave 1/2 geseed, nooit een scherm voor gehad).
  - **Zelf-lockout-bescherming**: een beheerder kan zichzelf via dit scherm niet
    deactiveren of zijn eigen laatste rol afpakken — anders kan de enige ingelogde
    super_admin zichzelf per ongeluk buitensluiten. Live geverifieerd (zie hieronder).
  - Routes toegevoegd aan `Router.php`, vóór de `/admin/{path}`-catch-all (zelfde
    volgorde-regel als News/Pages in v1.10.0).

### Getest (echte HTTP-boot, zelfde reconstructed-`vendor/`-methode als v1.12.0)

- Login als de echte installer-admin → `GET /admin/users` toont de lijst.
- `GET /admin/users/1/bewerk` (eigen account) → "eigen account"-waarschuwing + rollen-
  checkboxes, `super_admin` al aangevinkt.
- **Zelf-lockout-test**: `POST /admin/users/1/bewerk` met een lege `roles[]` → 302 terug
  naar het formulier met `?error=Je+kan+je+eigen+laatste+rol+niet+verwijderen…` — de
  rol-toewijzing in de database bleef ongewijzigd.
- Een tweede, echte gebruiker geregistreerd via `/register` (`TestMember`) → verschijnt
  in `/admin/users` → als BigBoss gedeactiveerd + rol `member` toegewezen via het
  formulier → geverifieerd rechtstreeks in de database: `is_active = 0`,
  `cf_user_roles` bevat `(user_id=2, role_id=<member>, assigned_by=1)`.

### Nog open (van de oorspronkelijke 6 placeholder-schermen uit v1.10.0)

`/admin/users` is nu echt. Nog steeds placeholder: Media, Roles, Themes, Menus, Logs —
zie README "Bekende beperkingen" voor het stappenplan.

---

## [1.12.0] — 2026-09-28 — Eerste échte end-to-end boot: 2 fatale bugs die géén enkele eerdere test kon vinden

### Context

Tot vandaag was elke verificatie in dit project — `php -l`, de handgeschreven smoke-tests, zelfs de
v1.11.0-verificatie tegen een echte lokale MariaDB — uitgevoerd via Reflection-injectie of losse
scripts die de klassen rechtstreeks aanriepen. **`public/index.php` en `cli/console.php` zijn nog
nooit echt gestart**, omdat `vendor/autoload.php` nooit bestond: `composer.json` vereist o.a.
`twig/twig`, `vlucas/phpdotenv` en `league/route`, maar `packagist.org` is in elke sandbox waarin dit
project tot nu toe is gebouwd geblokkeerd door het netwerkbeleid (bevestigd via de proxy-statuspagina:
`connect_rejected` / HTTP 403 op `repo.packagist.org`).

Om de vraag "werkt dit ook echt?" voor het eerst goed te kunnen beantwoorden is er dit keer wél een
werkende boot opgezet: de daadwerkelijk-gebruikte dependencies (`twig/twig` + zijn eigen runtime-deps,
en de PSR-interface-pakketten `psr/simple-cache` + `psr/container`) zijn via `git clone` van hun
officiële GitHub-repo's opgehaald — **echte, ongewijzigde upstream-broncode**, alleen het
autoload-mechanisme zelf (normaal door Composer gegenereerd) is met de hand als PSR-4-correcte
`spl_autoload_register` geschreven. Dat maakte een echte `php -S ... index.php` en een echte
`php cli/console.php <commando>` voor het eerst mogelijk. Deze workaround-`vendor/` is **niet**
gecommit (staat al in `.gitignore`) — op een server met normale internettoegang doet een gewone
`composer install` precies hetzelfde, alleen dan met een door Composer gegenereerde autoloader.

Die eerste échte boot vond meteen twee fatale bugs die vóór vandaag onmogelijk te ontdekken waren
zonder een werkende autoloader:

### Fixed

- **KRITIEK — `Psr\Container\ContainerInterface` ontbrak volledig in `composer.json`.**
  `src/Core/Container.php` regel 26: `final class Container implements ContainerInterface` met
  `use Psr\Container\ContainerInterface;` — maar `psr/container` stond nergens in `require`. Onder een
  échte `composer install` was dit pakket dus nooit geïnstalleerd, en zou `new Container()` — de
  allereerste regel van `Application::__construct()`, aangeroepen op **elke** HTTP-request én **elk**
  CLI-commando zonder uitzondering — meteen fataal falen met "Interface not found". Dit betekent dat
  de applicatie sinds Sprint 1 nog nooit vanaf een schone `composer install` had kunnen opstarten.
  Fix: `"psr/container": "^2.0"` toegevoegd aan `composer.json` → `require`.
- **KRITIEK — alle vier CLI-commando's waren onbereikbaar.** `cli/commands/QueueWorkerCommand.php`,
  `CacheClearCommand.php`, `MigrateCommand.php` en `ModuleInstallCommand.php` declareren stuk voor stuk
  `namespace CommunityFusion\Cli\Commands;`, maar `composer.json` → `autoload.psr-4` mapte alleen
  `CommunityFusion\` → `src/` (en de module-namespaces) — `cli/commands/` stond nergens in de
  autoload-map. Resultaat: `php cli/console.php migrate` (en elk ander commando, inclusief de twee die
  in v1.11.0 "getest tegen een echte MariaDB" heetten — die test liep via een handgeschreven harness
  die de klasse direct `require`de, niet via de echte autoloader) faalde altijd met
  `Class "CommunityFusion\Cli\Commands\MigrateCommand" not found`. Dit gold al sinds Sprint 1 voor
  `queue:work` en `cache:clear`, niet alleen voor de twee nieuwe commando's uit v1.11.0. Fix:
  `"CommunityFusion\\Cli\\Commands\\": "cli/commands/"` toegevoegd aan `composer.json` → `autoload.psr-4`.

### Getest (echte HTTP-boot + echte CLI-boot, ditmaal via de daadwerkelijke entry points)

Met een reconstructed `vendor/` (zie Context) en een verse lokale MariaDB-database, volledig via
`installer/InstallerCore.php`'s eigen `importSchema()`/`writeConfig()`-logica opgezet:

- `php -S 127.0.0.1:8199 index.php` (het échte `public/index.php`) → `GET /` → HTTP 200, thema
  gerenderd via een echte `Twig\Environment` (niet gemockt).
- `GET /admin` zonder sessie → permissie-gate werkt (v1.10.0's `PermissionMiddleware` bevestigd live).
- Echte login-flow: `GET /login` → CSRF-token uit de live pagina → `POST /login` met de door de
  installer aangemaakte admin (`password_hash`/Argon2id, `cf_user_roles`) → sessie-cookie → `GET /admin`
  toont nu echt "Dashboard"/"Uitloggen".
- Volledige News admin-CRUD via HTTP: `POST /admin/news` (nieuw artikel, CSRF-beveiligd) → verschijnt
  in `GET /admin/news` (met paginering — bevestigt de v1.11.0 LIMIT/OFFSET-fix ook live) én op de
  publieke `GET /news`.
- Contact-formulier: `POST /contact` (CSRF-beveiligd) → 302, geen 500 — Mailer-pad crasht niet.
- Alle vier CLI-commando's via het echte `cli/console.php`: `migrate` (idempotent, 21 tabellen),
  `cache:clear` (4 bestanden gewist), `module:install discord` (bereikt de echte
  catalogus-fallbacklogica, faalt netjes met een duidelijke melding i.p.v. een crash), `queue:work`
  (start zonder fatale fout).
- `GET /dit-bestaat-niet` → HTTP 404 (geen catch-all-lek).

Dit is de eerste keer in de geschiedenis van dit project dat de applicatie via haar eigen, echte entry
points (`public/index.php` en `cli/console.php`) end-to-end is geverifieerd, in plaats van via
Reflection-geïnjecteerde smoke-tests. Test-artefacten (het reconstructed `vendor/`, het testinstallatie-
`config/config.php`, de tijdelijke database) zijn na afloop weer opgeruimd — alleen de twee echte
bronbestand-fixes (`composer.json`) zijn gecommit.

---

## [1.11.0] — 2026-09-28 — Wave 2 vervolg: CLI-commando's + een écht kritieke LIMIT/OFFSET-bug

> Task 13 uit Wave 2 (`migrate`/`module:install` CLI-commando's bouwen) legde een kritieke,
> tot dan toe onopgemerkte bug bloot in de kern-`Connection`-klasse — zie hieronder. Dit is de
> eerste keer in dit hele project dat code daadwerkelijk tegen een echte, lokaal geïnstalleerde
> MariaDB-server is gedraaid in plaats van tegen `php -l` en gemockte/sqlite-gebaseerde
> smoke-tests; die verandering in testmethode is precies wat dit aan het licht bracht.

### Opgelost — kritiek

**`LIMIT ?`/`OFFSET ?` faalde op ELKE query tegen een echte database**
- `Connection::execute()` gaf de bindings-array ongewijzigd door aan `PDOStatement::execute()`.
  Met `PDO::ATTR_EMULATE_PREPARES => false` (al sinds Sprint 1 bewust aan gezet, voor echte
  server-side prepared statements) bindt PDO dan **elke** parameter als `PDO::PARAM_STR`,
  ongeacht het PHP-type. MySQL/MariaDB accepteert geen string-getypeerde parameter op een
  `LIMIT`/`OFFSET`-positie — elke query met `LIMIT ? OFFSET ?` gooide daardoor altijd
  `SQLSTATE[42000]: ... near ''1' OFFSET '0''`, zodra hij tegen een echte database draaide.
  **Dit trof zonder uitzondering alle tien bestanden in de codebase die dit patroon gebruiken**:
  `NewsRepository`, `PageRepository`, `BlogRepository`, `ForumRepository`,
  `DownloadsRepository`, `ContactRepository`, `PackageManager` (marketplace-catalogus),
  `NewsBlock`, en de REST API (`Api\V1\ContentController`, `Api\V1\UsersController`) — dus
  elk paginated overzicht in de hele applicatie, inclusief de News/Pages admin-CRUD van
  eerder in deze wave (v1.10.0). Nooit eerder gezien omdat elke voorgaande verificatie in dit
  project via `php -l` en gemockte `Connection`-objecten liep, nooit tegen een echte
  MySQL/MariaDB-instantie.
- **Fix**: `Connection::execute()` bindt nu elke parameter los via `bindValue()` met het
  juiste `PDO::PARAM_*`-type (`PARAM_INT` voor `int`, `PARAM_BOOL` voor `bool`, `PARAM_NULL`
  voor `null`, anders `PARAM_STR`) i.p.v. de hele array in één keer aan `execute()` te geven.
  Eén fix op één plek — `insert()`/`update()`/`delete()`/`fetchAll()`/`fetchOne()` routeren
  allemaal via deze ene methode, dus alle tien getroffen bestanden zijn hiermee gerepareerd
  zonder dat er per call-site iets hoefde te veranderen.
- **Echt geverifieerd**, niet alleen ge-lint: een lokale MariaDB 10.11-server geïnstalleerd
  in de sandbox (`apt-get install mariadb-server`) en gestart (`service mariadb start`), een
  echte database + gebruiker aangemaakt, en zowel `NewsRepository::getAll($limit, $offset)`
  (paginering, twee pagina's, juiste rijen per pagina) als de volledige `migrate`-flow
  (hieronder) er live tegenaan gedraaid — vóór de fix faalde dit hard, erna niet meer.

**`InstallerCore::importSchema()` was in de praktijk niet idempotent**
- Ontdekt door `migrate` een tweede keer te draaien tegen dezelfde (nu al gevulde) database:
  de `CREATE TABLE`-statements werden terecht overgeslagen (bestaande `42S01`-check), maar de
  RBAC/settings-seed-`INSERT`-statements niet — `SQLSTATE[23000]: ... Duplicate entry
  'super_admin' for key 'uq_name'`. Dit bestond al sinds Sprint 1 in de installer zelf, maar
  kwam nooit aan het licht omdat de installer normaal maar één keer draait.
- **Fix**: naast SQLSTATE `42S01` nu ook MySQL-errorcode `1062` (duplicate entry) negeren —
  bewust op de specifieke errorcode gecheckt, niet op de bredere `23000`-SQLSTATE-klasse (die
  ook FK-violations omvat), zodat een échte integriteitsfout elders niet stilzwijgend wordt
  geslikt. **Geverifieerd**: `migrate` drie keer achter elkaar gedraaid tegen dezelfde
  database — derde keer nog steeds 5 rollen / 18 permissies, geen duplicaten, geen fout.

### Toegevoegd

**`migrate` en `module:install` CLI-commando's (stonden al sinds v1.0.0 in de help-tekst)**
- `cli/commands/MigrateCommand.php` — hergebruikt `InstallerCore::importSchema()` (dezelfde
  code als installer-stap 5) i.p.v. de SQL-split-en-uitvoer-logica te dupliceren. Geeft een
  duidelijke melding + exit code 1 bij een ontbrekende `config/config.php` of een mislukte
  DB-verbinding, i.p.v. een kale fatal error.
- `cli/commands/ModuleInstallCommand.php` — hergebruikt `PackageManager` (dezelfde service
  als `/admin/marketplace`). `--url=` optioneel; zonder die vlag wordt de download-URL uit de
  marketplace-catalogus opgezocht, zelfde fallback als `MarketplaceController::install()`.
  Bewust géén `AuthManager::authorize('marketplace.install')`-check — die permissie hoort bij
  een ingelogde admin-sessie over HTTP; wie dit commando kan draaien heeft al shell-toegang
  tot de server, en dat is hier de vertrouwensgrens.
- `Application::boot()` was `private` — `run()` (de normale HTTP-flow) riep dit intern aan,
  maar de bestaande `QueueWorkerCommand` (al sinds Sprint 1!) bootstrapte via `$app = require
  .../Application.php; $app->make(Connection::class);` zonder ooit `boot()` aan te roepen.
  Omdat niets dan de `Connection`-singleton registreerde, gooide dit altijd "Kan parameter
  '\$config' niet resolven voor Connection" — `queue:work` was dus al sinds Sprint 1 kapot,
  ontdekt als bijvangst tijdens het bouwen van deze twee nieuwe commando's. `boot()` is nu
  `public`; `QueueWorkerCommand` en de twee nieuwe commando's roepen het expliciet aan vóór de
  eerste `$app->make(...)`.

### Getest
- Volledige `php -l`-sweep, schoon.
- **Een echte lokale MariaDB-server** (niet gemockt, niet sqlite): schema-import vanaf nul
  (21 tabellen), drie keer opnieuw draaien zonder fouten of duplicaten, `NewsRepository`
  create/getAll/countAll met echte paginering, en de foutafhandelingspaden van beide nieuwe
  CLI-commando's (ontbrekende `config.php`, ontbrekende slug, onbereikbare DB, geen
  catalogus-match) — elk pad gecontroleerd op een nette melding + exit code 1, geen fatal
  errors. De testdatabase/-gebruiker en `config/config.php` zijn na afloop weer verwijderd
  (dat bestand hoort niet in git — zie `.gitignore`).

---

## [1.10.0] — 2026-09-28 — Wave 2: /admin permissie-gating + de resterende "Bekende beperkingen"

> Vervolg op Wave 1 (v1.9.0): de expliciet als "niet opgelost" gedisclosede punten uit README's
> "Bekende beperkingen" worden hier één voor één afgehandeld, te beginnen met de belangrijkste:
> `/admin` was login-gated maar niet rol-gated.

### Toegevoegd

**Permissie-gating voor /admin (was: alleen login-check)**
- `src/Core/HttpException.php` — deze class bestond niet, terwijl
  `Application::handleException()` er al sinds Sprint 1 naar verwees
  (`$e instanceof HttpException`) en `AuthManager::authorize()` een gewone
  `\RuntimeException(..., 403)` gooide in de veronderstelling dat zoiets
  bestond. `instanceof` tegen een niet-bestaande class faalt in PHP niet
  hard — het evalueert gewoon naar `false` — dus dit crashte nooit, het
  betekende alleen dat **elke** `authorize()`-afwijzing overal in de
  codebase (inclusief het al langer bestaande `MarketplaceController`, dat
  wél consequent `authorize('marketplace.view'/'marketplace.install')`
  aanriep) als een generieke 500 "Er is een fout opgetreden" naar buiten
  kwam in plaats van de bedoelde 403. Nu opgelost door de class alsnog te
  bouwen in de namespace waar hij al werd verwacht.
- `src/Api/Middleware/PermissionMiddleware.php` — nieuwe middleware die ná
  `AuthMiddleware` een specifieke permissie afdwingt. `Router::buildPipeline()`
  ondersteunt nu een `"ClassName:argument"`-syntax in de middleware-array
  (bv. `PermissionMiddleware:settings.edit`) — het deel na `:` wordt als
  derde argument aan `handle()` doorgegeven; bestaande 2-parameter
  middlewares (`AuthMiddleware` e.a.) negeren dat argument gewoon, want PHP
  staat extra argumenten toe zonder foutmelding (geverifieerd met een losse
  smoke-test die dit exact simuleert).
- Alle `/admin`-routes die voorheen alleen `$auth` (ingelogd?) hadden,
  hebben nu ook een permissie: `/admin` → `admin.access`, `/admin/settings`
  → `settings.edit`, `/admin/blocks/*` (5 routes) → `blocks.manage`,
  `/api/v1/blocks/positions` → `blocks.manage` (bleek zelfs helemaal geen
  interne `->can()`-check te hebben — de route-level check was de enige
  gate, en die was login-only), `/admin/marketplace/*` → `marketplace.view`
  / `marketplace.install` als extra, vroege laag bovenop de al bestaande
  interne `authorize()`-calls in de controller zelf.
- `cf_permissions`/`cf_role_permissions` (`schema.sql`) uitgebreid met drie
  permissies die al wél door code werden aangeroepen maar nooit bestonden:
  `marketplace.view`, `marketplace.install` (`MarketplaceController`, sinds
  Sprint 7 — zonder deze fix kon zelfs de `admin`-rol nooit bij
  `/admin/marketplace`, alleen `super_admin` via de `*`-wildcard) en
  `users.view` (`Api\V1\UsersController::index()`, sinds Sprint 6). Plus een
  nieuwe `admin.access`-permissie als basistoegang tot de `/admin`-shell.
  Alle vier toegekend aan de `admin`-rol.
- `/admin/contact` (Wave 1) had dit patroon al goed: een interne
  `requireManager()`-guard met een echte `Response::html(..., 403)` in
  plaats van een exception. Die is ongewijzigd gelaten.

**Mailer — Contact verstuurt nu écht e-mail (was: alleen opslaan)**
- `src/Core/Mail/Mailer.php` — nieuwe, dependency-vrije SMTP-client via raw
  sockets: platte verbinding, STARTTLS (587) en implicit TLS (465, `ssl://`),
  AUTH LOGIN, RFC 2047 encoded-words voor niet-ASCII onderwerpen/namen, RFC
  5321 dot-stuffing, en een `mail()`-fallback wanneer geen host is
  geconfigureerd. Geen nieuwe Composer-dependency (PHPMailer e.d.) nodig —
  packagist is in deze sandbox onbereikbaar, dus `composer require` was
  sowieso geen optie, en de use-case (één plain-text notificatiemail per
  contactbericht) rechtvaardigt geen library.
  **Echt getest**, niet alleen ge-lint: een lokale Python `smtpd`
  debug-server ontving de volledige SMTP-conversatie correct (EHLO, MAIL
  FROM, RCPT TO, DATA), en het ontvangen bericht klopte byte-voor-byte
  terug — inclusief een RFC 2047-gecodeerd onderwerp met é/ë/€ dat correct
  terug-decodeerde, en een berichtregel die met een punt begint (dot-stuffing
  round-trip geverifieerd). Verbindingsfouten (onbereikbare host, ongeldig
  e-mailadres) falen netjes naar `false` i.p.v. te crashen.
- `config/config.php`'s `'mail'`-sectie (door de installer gegenereerd)
  bevatte al sinds Sprint 1 alleen `driver` + `from` — geen `host`/`port`/
  `username`/`password` velden, dus zelfs met een Mailer-klasse was er geen
  weg om SMTP daadwerkelijk te configureren. De installer laadt bewust geen
  Dotenv/Composer (zie eerdere Wave 1-fix in `InstallerCore.php`), dus
  `InstallerCore::readEnvValue()` is een kleine, dependency-vrije KEY=VALUE
  `.env`-parser: als `MAIL_HOST` vóór installatie al in een echt `.env`
  staat, schrijft de installer `driver: 'smtp'` + de volledige SMTP-config
  weg; anders blijft `driver: 'mail'` (ongewijzigd gedrag). Alle waarden
  gaan door `var_export()` de gegenereerde PHP-bron in — geverifieerd met
  een wachtwoord dat zowel `"` als `'` bevat, dat correct en veilig ge-escaped
  terugkwam.
- `Application::boot()` registreert `Mailer` als singleton, met dezelfde
  `config/config.php` → `$_ENV`-fallback-volgorde als de rest van de
  bootstrap. Bij `driver: 'mail'` blijft `host` altijd leeg, ongeacht wat er
  toevallig in `.env` staat, zodat de `mail()`-fallback bewust gekozen kan
  worden.
- `ContactController::store()` verstuurt nu een meldingsmail bij elk nieuw
  bericht (best-effort — een mislukte mail geeft nooit een 500, het bericht
  staat al veilig in de database/inbox). Er is nog geen apart "meldingen
  naar"-adres in te stellen via de admin-UI (`settings.php` is een statische
  pagina, geen key/value-editor — zie Task voor admin-CRUD hieronder), dus
  het bericht gaat vooralsnog naar het geconfigureerde afzenderadres zelf.
- `.env.example` — `MAIL_HOST` was een ingevulde placeholder
  (`smtp.example.com`); nu bewust leeg, zodat een kale `.env`-kopie niet
  stilzwijgend "smtp" kiest en probeert te verbinden met een hostnaam die
  niet bestaat. `MAIL_ENCRYPTION` toegevoegd; het ongebruikte `MAIL_DRIVER`
  verwijderd (driver wordt nu afgeleid van of `MAIL_HOST` gezet is).

**Nieuws + Pagina's admin-CRUD (was: dode links in de sidebar sinds Sprint 2)**
- `dashboard.php` linkt al sinds Sprint 2 naar `/admin/news` en `/admin/pages`
  — geen van beide had een route, controller-methode of view. Elke klik gaf
  een kale 404 zonder verklaring. Nu volledig gebouwd volgens hetzelfde
  patroon als `BlockController` (raw PHP-views via `ob_start()`+`include`,
  geen Twig in `/admin/*`): overzicht met paginatie, aanmaken, bewerken,
  soft-delete.
- `NewsRepository`/`PageRepository` — admin-methoden toegevoegd (`getAll()`,
  `countAll()`, `findById()` zonder de publieke `status='published'`-restrictie,
  `slugExists()`, `update()`, `delete()` als soft delete, `uniqueSlug()` met
  een collision-retry-loop — zelfde patroon als `BlogRepository::uniqueSlug()`).
- `NewsController`/`PageController` — admin-methoden (`adminIndex`,
  `createForm`, `store`, `editForm`, `update`, `delete`) plus een gedeelde
  private `fromRequest()` voor validatie/normalisatie. Een slug wordt alleen
  bij aanmaken gegenereerd en blijft daarna stabiel (bestaande permalinks/SEO
  blijven werken bij een latere bewerking). `published_at` van een
  nieuwsartikel wordt alleen gezet bij de **eerste** keer publiceren (via een
  hidden `_current_published_at`-veld in het bewerk-formulier), niet bij elke
  volgende opslag — anders zou de publicatiedatum bij iedere bewerking
  "nu" worden.
- Nieuwe route-groepen in `Router.php`: `/admin/news*` (6 routes,
  `news.create`) en `/admin/pages*` (6 routes, `pages.manage`) — beide
  permissies bestonden al in `schema.sql` maar werden nog nergens
  aangeroepen. Letterlijke `/create`-routes staan vóór de generieke
  `{id}`-routes, zelfde volgorde-conventie als de bestaande Blog-routes.
- `src/Modules/Shared/views/admin_sidebar.php` + `admin_styles.php` —
  nieuwe gedeelde partials. `dashboard.php` en `Blocks/views/index.php`
  herhaalden de sidebar/topbar-CSS allebei handmatig; vanaf nu gebruiken
  **nieuwe** `/admin/*`-schermen (News, Pages, de placeholders hieronder)
  één partial in plaats van een derde/vierde kopie. De twee bestaande
  bestanden zijn bewust niet aangepast (kleinere diff); ze op de partial
  overzetten is een goede vervolgstap maar viel buiten deze wave.
- `public/assets/css/blueprint.css` — `.cf-table`, `.cf-table-actions`,
  `.cf-table-empty`, `.cf-toolbar`, `.cf-pagination`, `.cf-badge-gray` en
  `.cf-badge-red` toegevoegd. Er bestond nog geen tabel-stijl voor
  admin-overzichten (`BlockController`'s scherm is een drag&drop-builder,
  geen lijst).

**De overige dode admin-sidebar-links (Media, Gebruikers, Rollen, Thema's, Menu's, Logs, Modules)**
- Ook deze zes linkten al sinds Sprint 2 naar niets. Zes volledige
  CRUD-schermen bouwen viel buiten wat deze wave aankon zonder kwaliteit in
  te leveren — in plaats daarvan krijgt elk scherm nu een eerlijk "dit
  bestaat nog niet"-scherm (`Settings/views/_placeholder.php` + zes kleine
  losse bestanden die alleen titel/icoon/toelichting zetten) i.p.v. een kale
  404 zonder uitleg. Elke toelichting noemt specifiek wat er al wél werkt
  (bv. Media: uploads + `/media/{path}` werken, alleen de bladerbare
  bibliotheek ontbreekt nog).
- `/admin/modules` is een **redirect** naar `/admin/marketplace` geworden
  (geen placeholder) — module-installatie/-beheer gebeurt daar al sinds
  Sprint 7, een los "Modules"-scherm zou alleen uit de pas gaan lopen.
- `AdminController::handle(Request $request)` bestond al sinds Sprint 1
  (sanitizeert `path` naar `[a-z0-9/-]` en include't `views/{path}.php`) maar
  was in `Router.php` nooit aan een route gekoppeld — dode code. Nu
  geregistreerd als catch-all `/admin/{path}`, bewust als **allerlaatste**
  `/admin/*`-route: de Router matcht op registratievolgorde, niet op
  specificiteit, dus alles hierboven (incl. Marketplace) moet er vóór staan.
  Dat bleek in de praktijk niet triviaal: de eerste versie van deze regel
  stond per ongeluk vóór het Marketplace-blok en zou `/admin/marketplace`
  hebben gekaapt — gevangen door een losse routing-smoke-test (zie hieronder)
  vóórdat dit gepusht werd, niet door productiegebruik.

### Getest
- Alle gewijzigde/nieuwe bestanden: volledige `php -l`-sweep van de repo,
  schoon.
- Nieuwe admin-CRUD-logica: een losse smoke-test met een **echte** `PDO`
  sqlite in-memory-database (via reflection in de anders-final `Connection`
  geïnjecteerd — de DSN is normaal hardcoded op `mysql:`) en een echte
  `CacheManager`/`Request`: 27 checks tegen echte SQL (`uniqueSlug()`
  collision-retry, `fromRequest()`-validatie inclusief de
  `published_at`-eenmalig-stempel-regel, volledige create→update→delete
  round-trip voor zowel News als Pages). Geen enkele klasse is voor de test
  aangepast.
- Route-volgorde: een losse smoke-test parsete de daadwerkelijke
  registratievolgorde uit `Router.php` en simuleerde `compilePattern()` +
  first-match-wins voor 19 paden (elke nieuwe/gewijzigde `/admin/*`-route
  plus de zes catch-all-paden) — ving de hierboven genoemde
  Marketplace-regressie vóór de push.

### Gewijzigd
- README.md "Bekende beperkingen" — het punt "`/admin`-routes zijn
  login-gated maar niet rol-gated" is verwijderd; het Contact-e-mailpunt is
  bijgewerkt naar "verstuurt nu wél e-mail, maar zonder instelbaar
  ontvanger-adres"; het punt over dode `/admin/news`, `/admin/pages` en de
  overige sidebar-links is verwijderd/bijgewerkt. Zie hieronder voor wat nog
  resteert.

---

## [1.9.0] — 2026-09-28 — Wave 0 audit: WP-contaminatie verwijderd, CI, security, ontbrekende core-modules

> Dit is een BigBoss Wave 0 gap-analyse tegen de echte repo (`Die0uwe/bluprint-cms` @ `6108fec3`),
> gevolgd door de directe fixes. Volledig rapport: `docs/wave-0-gap-analysis.md`.

### Ingetrokken / verwijderd

- **`modules/warcraft/src/roster.php` + `armory.php` verwijderd.** Deze "Sprint 9"-bestanden
  (CHANGELOG v1.8.0) bleken 1-op-1 gekopieerde WordPress-code (`ABSPATH`, `WP_Error`,
  `get_transient()`, `wp_remote_get()`, `$wpdb`, `add_shortcode`) uit de Slayer Alliance Master
  Suite-plugin — functies die in dit PSR-4 framework niet bestaan. `WarcraftModule.php` laadde ze
  nergens (niet in `getBlocks()`), dus ze deden in productie niets; de v1.8.0-claim "Productie" was
  onjuist. De functionaliteit (guild roster, character profiel) was al gedekt door de bestaande
  native blocks `WowGuildRosterBlock` en `WowCharacterBlock`. Bijbehorende assets
  (`roster.css`, `armory.css`) en `INSTALL-roster-armory.md` ook verwijderd.

### Toegevoegd

**CI & testing**
- `.github/workflows/ci.yml` — PHP 8.3 + 8.4 matrix: `composer validate`, `composer install`,
  PHP-lint van alle bronbestanden, PHPUnit, PHPStan level 8, PHPCS PSR-12, plus een aparte job
  die `schema.sql` tegen een echte MariaDB 10.11 service-container importeert.
- `phpunit.xml` + `tests/bootstrap.php`
- Echte, uitvoerbare unit tests (voorheen 0): `CsrfProtectionTest`, `HookManagerTest`,
  `JWTManagerTest` — tokengeneratie, signature-tampering, expiry, hook-prioriteit, filter-chains.
- **Bekende beperking:** dit is geschreven en syntax-gevalideerd (`php -l`) buiten een sandbox
  zonder toegang tot packagist.org — er kon geen `composer.lock` gegenereerd of PHPUnit lokaal
  gedraaid worden. Eerste `composer install` (lokaal of in CI) moet het lockfile committen.

**Security**
- **Kritiek gevonden tijdens deze fix: `AuthManager`/`JWTManager` waren nooit in de DI-container
  gebonden.** `JWTManager`'s constructor heeft een scalar `string $secret`-parameter zonder
  default; de container's auto-resolve-via-reflection kan zo'n parameter niet vullen en gooit
  `RuntimeException`. Omdat niets in de codebase `JWTManager` of `AuthManager` expliciet bindt of
  handmatig `new`'t, crashte **elke route die `AuthManager` nodig heeft** (login, registreren,
  Discord/Twitch OAuth-callback, admin panel) op een schone installatie. `Application::boot()`
  bindt nu beide expliciet als singleton.
- **Sleutelketen was ook onbetrouwbaar los van bovenstaande crash:** de installer genereert
  `app.key` in `config/config.php`, maar `OAuthClient::getAppKey()` las rechtstreeks
  `$_ENV['APP_KEY']` — dat bestaat alleen als de sitebeheerder `.env` handmatig invult.
  `.env.example` leverde `APP_KEY=` leeg, dus zonder handmatige stap versleutelde
  `OAuthClient::encrypt()` OAuth-tokens met een 32-byte nul-sleutel (triviaal omkeerbaar).
  `Application::boot()` synchroniseert nu `config.php`'s `app.key`/`jwt.secret` altijd naar
  `$_ENV` bij elke request.
- Aparte `jwt.secret` naast `app.key` — beide door de installer met eigen willekeurige waarden
  gegenereerd (`installer/InstallerCore.php`), zodat JWT-signing (HS256) en OAuth-token-encryptie
  (AES-256-GCM) niet langer dezelfde sleutel delen.
- Discord OAuth ondersteunt nu **login/registratie voor nieuwe bezoekers** — voorheen kon Discord
  alleen aan een al ingelogd account gekoppeld worden (`redirect()`/`callback()` vereisten
  `auth->check()`). Nieuw: `DiscordOAuthController::loginOrRegister()` maakt bij een onbekende
  Discord-ID automatisch een `cf_users`-rij aan.
- Upload-handler (`src/Core/Storage/UploadManager.php`): schrijft naar `storage/uploads/`
  (buiten webroot, zoals SD v1.0 voorschrijft), MIME-whitelist via `finfo`, willekeurige
  bestandsnamen, geserveerd via een controller-route i.p.v. directe public-toegang. Gekoppeld aan
  `cf_news.featured_image` en `cf_users.avatar_url`. Nieuwe `ProfileController` (`GET`/`POST
  /profiel`) — bestond nog niet, ondanks dat de Discord/Twitch-callbacks er al sinds Sprint 4 naar
  redirecten (dode 404-link).
- **Kritiek gevonden tijdens de Forum-bouw: `cf_permissions`/`cf_role_permissions` werden
  nérgens geseed.** Elke `module.json` (Discord, Twitch, …) declareert al sinds Sprint 5 een
  `"permissions"`-array, maar niets voerde die ooit in de database in — en ook de
  `super_admin`-rol kreeg nooit de `*`-wildcard toegekend. Het gevolg: `RBACManager::userCan()`
  gaf voor **elke** gebruiker, inclusief het door de installer aangemaakte super-admin-account,
  altijd `false` terug. Elke `auth()->can(...)`/`auth()->authorize(...)`-check in de codebase was
  dus dood — permissie-gates bestonden alleen op papier. `schema.sql` seedt nu een basisset
  permissies (`users.manage`, `news.create`, `pages.manage`, `modules.manage`, `blocks.manage`,
  `settings.edit`, `discord.admin`, `discord.sync`, `forum.post`, `forum.moderate`, plus de `*`
  super-admin-wildcard) en kent ze toe per rol conform SD §10.1. **Nog niet meegenomen:** de
  admin-routes zelf (`AdminController`, `BlockController`, `MarketplaceController`) roepen nog
  geen `auth()->authorize(...)` aan — `AuthMiddleware` controleert alleen "is ingelogd", niet
  welke rol. Elk ingelogd lid kan dus vandaag nog altijd bij `/admin` — een aparte
  hardening-taak, hier gedocumenteerd maar niet opgelost.
- **Ook gevonden: `auth`, `settings` en `menu_pages` waren nooit als Twig-variabelen
  beschikbaar.** `layout.twig` — die door elke pagina wordt ge-extend — leest al sinds Sprint 3
  `auth.check()`, `settings.site_name`/`settings.site_description` en `menu_pages`, maar geen
  enkele controller gaf ze door en `ThemeManager` registreerde ze niet als globals. Twig faalt
  niet hard op een undefined global (non-strict mode), dus dit bleef onopgemerkt: de header
  toonde op elke pagina altijd "Inloggen" (nooit "Admin"/"Uitloggen", ook niet voor ingelogde
  gebruikers), de site-titel/meta-omschrijving waren leeg, en het topmenu toonde nooit pagina's.
  `ThemeManager::addGlobal()` toegevoegd; `Application::boot()` injecteert nu `auth`
  (de `AuthManager`-instantie), `settings` (`SettingsRepository::getGroup('core')`) en
  `menu_pages` (`PageRepository::getMenuPages()`) als Twig-globals voor elke request.

**Ontbrekende blueprint-kernmodules**
- **Forum** (`src/Modules/Forum/`) — `ForumRepository` + `ForumController`. Borden hergebruiken
  de bestaande gedeelde `cf_categories`-tabel (`type = 'forum'`, exact zoals SD §3.5 al
  voorschreef voor News+Forum) i.p.v. een nieuwe, aparte bordentabel. Topics/posts krijgen eigen
  tabellen `cf_forum_topics` / `cf_forum_posts` met gecachte reply-count/laatste-bericht-velden.
  Routes: `/forum`, `/forum/{board}`, `/forum/{board}/nieuw`, `/forum/{board}/{topic}`
  (+ `/reageer`, `/pin`, `/lock`, `/verwijder`). RBAC-permissies `forum.post` (member+) en
  `forum.moderate` (moderator+) — zie ook de RBAC-seeding-fix hieronder, zonder welke deze
  permissies nooit iets zouden toestaan. Topic aanmaken + eerste post, en reactie + teller-update,
  lopen elk in één `Connection::transaction()`.
- **Blog** (`src/Modules/Blog/`) — `cf_blog_posts`. Elk lid heeft zijn eigen blog: posts zijn
  uniek per `(author_id, slug)`, niet globaal, dus URL's zijn `/blog/{username}/{slug}`. Géén
  aparte "mag bloggen"-permissie — ieder ingelogd lid mag zijn eigen posts schrijven/bewerken/
  verwijderen (draft/published), `blog.moderate` is alleen nodig voor ANDERMANS posts.
- **Downloads** (`src/Modules/Downloads/`) — `cf_downloads`, gebruikt `UploadManager`. Omdat
  Downloads bredere bestandstypen moet toestaan dan afbeeldingen (zip/pdf/rar/7z/gz) kreeg
  `UploadManager` een optionele MIME-whitelist-parameter + een `forDownloads()`-fabrieksmethode;
  bestanden landen in een eigen `storage/downloads/`, los van `storage/uploads/`. Curated door
  `downloads.manage` (admin). Download-teller + eigen `Content-Disposition`-route
  (`/downloads/{slug}/bestand`) die de originele bestandsnaam teruggeeft ondanks het
  gerandomiseerde opslagpad.
- **Contact** (`src/Modules/Contact/`) — publiek formulier (`/contact`, geen login vereist,
  CSRF + honeypot-veld tegen basic bots) + `cf_contact_messages` + een beheer-inbox
  (`/admin/contact`) achter `contact.manage`. **Verstuurt geen e-mail** — er bestaat in deze
  codebase geen `Mailer`-klasse, ondanks dat `config/config.php` al een volledige SMTP-sectie
  genereert; berichten worden alleen opgeslagen en via de inbox gelezen. Bouwen van een
  Mailer + daadwerkelijke SMTP-verzending is hiermee een nieuw gevonden, nog openstaand gat.

**Installer**
- `installer/templates/step5.php` toont nu de kernmodules (altijd actief, geen schakelaar —
  een uitgeschakelde checkbox die toch niets deed zou een nieuwe dode UI zijn) plus een
  **dynamisch** opgebouwde lijst optionele modules, rechtstreeks gescand uit
  `modules/*/module.json` i.p.v. een handmatig bijgehouden array. Dat array bevatte een
  `'guild'`-entry die niet overeenkwam met de echte map `guild-management/` (checkbox deed dus
  niets) en een `'youtube'`-entry voor een module die helemaal niet bestaat — beide gefikst
  doordat de lijst nu de werkelijke `modules/`-map volgt.
- **Kritiek gevonden: `installer/steps/Step5.php` las `$_POST['modules']` al in, maar deed er
  vervolgens helemaal niets mee** — de module-selectie in de installer-UI had nul effect, elke
  optionele module (Discord, Twitch, …) bleef na installatie permanent uitgeschakeld ongeacht
  wat was aangevinkt. Step5 schrijft de geselecteerde modules nu echt naar `cf_modules`
  (`is_enabled = 1`), de tabel die `Application::loadModules()` elke request uitleest — vanaf de
  eerste pagina na installatie worden ze dus daadwerkelijk geladen en `boot()`'d.
- **Ook gevonden, in dezelfde hoek: `PackageManager::runModuleInstaller()` riep `install()` aan
  zonder eerst `boot()`.** Geen enkele module-klasse heeft een eigen `__construct()`, dus
  `$this->app` wordt uitsluitend gezet door `boot(Application $app)` — zonder die aanroep eerst
  crasht `install()` zodra hij `$this->app` aanraakt (bv. `DiscordModule::install()` haalt er een
  `Connection` uit), een crash die de omringende try/catch tot nu toe stil slikte. Per saldo
  werden module-specifieke tabellen (bv. `cf_discord_role_mapping`) dus **nooit** aangemaakt via
  de marketplace-installflow. Nu roept `runModuleInstaller()` `boot()` vóór `install()` aan.
  **Nog een bekende beperking:** de installer zelf laadt bewust geen Composer-autoloader (zie
  `InstallerCore.php` — de installer draait onafhankelijk van het framework), dus Step5 kan
  `install()` niet direct aanroepen; module-specifieke extra tabellen ontstaan pas zodra een
  beheerder de module later in de Marketplace nogmaals activeert. De module zelf degradeert
  intussen netjes (try/catch) zolang die tabellen nog ontbreken.

- **Gevonden: `cli/console.php` adverteert `migrate` en `module:install`, maar de bijbehorende
  `MigrateCommand`/`ModuleInstallCommand`-klassen bestaan niet** (`cli/commands/` bevat alleen
  `QueueWorkerCommand` en `CacheClearCommand`) — beide commando's eindigden in een kale
  `Class not found`-fatal error. Geeft nu een duidelijke melding met een werkend alternatief
  (`mysql <db> < src/Core/Database/schema.sql`, resp. `/admin/marketplace`). De commando's zelf
  bouwen viel buiten deze doorloop — zie README "Bekende beperkingen".

### Opgelost — fatale parse-fouten (gevonden bij de eindcontrole)

Een volledige `php -l`-sweep over **elk** PHP-bestand in de repo (niet alleen de bestanden die in
deze doorloop zijn aangeraakt) legde vier reeds langer bestaande, 100%-fatale parse-fouten bloot.
Geen van deze bestanden stond in eerdere Wave 0/Wave 1-bevindingen — `php -l` was er kennelijk nog
nooit overheen gehaald. Een parse-fout crasht de hele request zodra het bestand geladen wordt, dus
dit waren geen randgevallen maar keiharde witte-scherm-crashes:

- **`installer/templates/step2.php`, `step3.php` en `step4.php`** openden alle drie een kale,
  nooit-gesloten `<?php`-tag op regel 1, direct gevolgd door rauwe HTML (`<form method="POST">`).
  `layout.php` include't deze stap-templates al vanuit een geopend `<?php`-blok, dus de tweede,
  ongesloten `<?php` liet de parser de HTML eronder als PHP-code proberen lezen — een parse-fout.
  **Resultaat: elke installatie crashte met een wit scherm zodra stap 2 (Database) werd geopend** —
  de installer kwam in de praktijk nooit verder dan stap 1. Gefixed naar hetzelfde patroon als het
  (wel correcte) `step1.php`/`step5.php`: geen tweede openings-tag nodig binnen een reeds open
  PHP-context.
- **`src/Modules/Settings/AdminController.php`** declareerde de methode `handle()` twee keer — een
  fatale "Cannot redeclare"-fout die betekende dat deze klasse **nooit** geladen kon worden, dus elke
  `/admin`-route (`dashboard()`, `settings()`, `handle()` zelf) volledig kapot was. De twee versies
  waren bovendien niet gelijkwaardig: de eerste zette `$path` ongefilterd in het include-pad (een
  path-traversal/LFI-gat), de tweede whitelist't eerst naar `[a-z0-9/-]`. De onveilige, dubbele
  versie is verwijderd; de gesaniteerde versie is behouden.
- **`modules/warcraft/src/WowGuildRosterBlock.php`** gebruikte `<?= count(...) ?>` middenin een
  heredoc-string — heredocs voeren zulke tags niet uit, ze zijn daar letterlijke tekst — én de
  aanhalingstekens rond de array-key (`$roster['members']`) zijn ongeldig in heredoc's
  simpele interpolatie-syntax. Resultaat: een parse-fout zodra dit blok (één van de native
  vervangers voor de verwijderde WordPress-code, zie hierboven) werd gerenderd. Gefixed door de
  telling vooraf in een gewone variabele te zetten en die te interpoleren.

Deze vier bestanden waren stuk voor stuk **niet** aangeraakt door eerdere Wave 1-werk in deze
doorloop — ze zijn nu voor het eerst ontdekt en gefixed, exact omdat de eindcontrole bewust een
volledige repo-sweep deed in plaats van alleen de zelf-gewijzigde bestanden.

### Gewijzigd
- `docs/wave-0-gap-analysis.md` toegevoegd (stond al sinds Sprint 9 als link in README/CHANGELOG,
  maar bestond nog niet in de repo zelf) — het volledige Wave 0-auditrapport, plus een nieuwe
  "Wave 1 — status per bevinding"-tabel die bijhoudt wat sindsdien daadwerkelijk is opgelost en
  wat bewust nog openstaat.
- README.md — Security-sectie, module-tabel en roadmap gecorrigeerd naar de geverifieerde staat;
  nieuwe tabel met de vier core content-modules (Forum/Blog/Downloads/Contact); nieuwe
  "Bekende beperkingen"-sectie die alle in deze doorloop gevonden-maar-niet-opgeloste gaten
  expliciet benoemt (admin-routes niet permissie-gated, dode admin-sidebar-links, geen Mailer,
  ontbrekende CLI-commando's, module-tabellen bij installer-selectie).
- `src/Modules/Settings/views/dashboard.php` — het dode `/admin/forum`-sidebarlink verwijst nu
  naar het echte, publieke `/forum` (moderatie gebeurt daar inline via `forum.moderate`); nieuw
  `/admin/contact`-link toegevoegd, want die pagina bestaat nu daadwerkelijk. De overige dode
  links (`/admin/news`, `/admin/users`, `/admin/media`, …) zijn bewust ongemoeid gelaten — die
  admin-CRUD-schermen bouwen viel buiten deze doorloop.

---

## [1.8.0] — 2026-06-06 — Sprint 9: WoW Module v2 — Guild Roster & Character Armory

> ⚠️ **Ingetrokken in v1.9.0** — zie hierboven. De hieronder beschreven bestanden bevatten
> WordPress-code die in dit framework niet functioneert en zijn verwijderd.

### Toegevoegd

**World of Warcraft Module — Volledige herbouw naar Blizzard API v2**

- `modules/warcraft/src/roster.php` — Guild Roster v2.0
  - Live guild leden via `eu.api.blizzard.com/data/wow/guild/{realm}/{guild}/roster`
  - Namespace: `profile-eu` — token via `Authorization: Bearer` header (aug 2024 verplichting)
  - Avatar per lid via `/character-media` endpoint (gecached 24 uur per karakter)
  - Sortering op rank → naam, class-kleur glow per kaart
  - Live JavaScript zoekfilter + class dropdown (geen pagina reload)
  - Paginering instelbaar via shortcode attribuut (`per_page`)
  - Alts (rank 7+) optioneel verbergen (`show_alts="no"`)
  - Rank labels aanpasbaar via `add_filter('sa_roster_rank_labels')`
  - AJAX cache-flush knop in admin tab (nonce beveiligd)
  - Shortcodes: `[guild_roster]` · `[guild_roster show_alts="no" per_page="20"]`

- `modules/warcraft/src/armory.php` — Character Armory v2.0
  - 5 Blizzard API calls per karakter: summary + media + equipment + achievements/statistics
  - Full 3D character render via `/character-media` (main-raw asset)
  - 18 uitrustingslots met ilvl + kwaliteitskleur (grijs/groen/blauw/paars/oranje/legendarisch)
  - Stat boxes: Item Level · Achievement Points · Laatste Login timestamp
  - Externe links: WoW Armory · Raider.IO · WarcraftLogs (per karakter)
  - Zoekformulier als `[sa_armory]` zonder params
  - URL-parameter aanstuurbaar: `/armory/?char=Naam&realm=sporeggar`
  - Gecached 1 uur per karakter via WP Transients
  - AJAX cache-flush per karakter of alle armory caches tegelijk
  - Shortcodes: `[sa_armory]` · `[sa_armory char="Dieouwe" realm="sporeggar"]`

- `modules/warcraft/assets/roster.css` — Guild Roster dark stylesheet
  - Slayer Alliance dark thema (bg `#0a0a0f`, accent `#a11692`, goud `#ccaa00`)
  - Class-kleur glow per kaart via CSS custom property `--class-color`
  - Responsive grid (auto-fill, minmax 200px)
  - Rank badge styling per rank index (0 = GM goud, 1 = Officer zilver)

- `modules/warcraft/assets/armory.css` — Character Armory dark stylesheet
  - Hero layout met 3D render + profiel naast elkaar
  - Item quality border-left kleuren (WoW standaard: groen/blauw/paars/oranje)
  - Responsive: single column op mobiel

- `modules/warcraft/INSTALL-roster-armory.md` — Volledige installatie handleiding

### Gewijzigd

- **WoW module blocks**: van 3 naar **5** (+ `roster.php` + `armory.php`)
- **README.md**: versie badge bijgewerkt naar `1.8.0`, WoW module sectie uitgebreid met API endpoint tabel, roadmap Sprint 9 toegevoegd als ✅
- Blizzard token authenticatie: token nu via `Authorization: Bearer` header in alle WoW API calls (niet via query string — verplicht na aug 2024 Blizzard gateway wijziging)

### Security

- Alle `$_GET` / `$_POST` input via `sanitize_text_field()` + `sanitize_title()`
- Alle output via `esc_html()` · `esc_url()` · `esc_attr()`
- AJAX handlers: `check_ajax_referer()` + `current_user_can('manage_options')`
- `$wpdb->esc_like()` bij LIKE queries voor cache flush
- Geen credentials hardcoded — alles via `sa_get_valid_token()` core functie

---

## [1.0.0] — 2026-06-06 — Sprint 1: Core Foundation

### Toegevoegd

**Core Framework**
- `Application.php` — Bootstrap klasse met DI Container setup en module loader
- `Container.php` — PSR-11 Dependency Injection Container met auto-resolve via Reflection
- `Router.php` — HTTP router met named parameters, middleware pipeline en core routes
- `Request.php` — HTTP request wrapper (PSR-7 geïnspireerd)
- `Response.php` — HTTP response met automatische security headers

**Hook Systeem**
- `HookManager.php` — WordPress-achtig action/filter systeem met prioriteitsondersteuning

**Database Laag**
- `Connection.php` — PDO wrapper, prepared statements only, transaction helper
- `QueryBuilder.php` — Fluent query builder met method chaining
- `schema.sql` — Volledig database schema (users, roles, permissions, modules, blocks, news, pages, settings)

**Auth & Security**
- `AuthManager.php` — Login, logout, sessie, argon2id wachtwoord hashing
- `JWTManager.php` — JWT generatie + validatie (HS256)
- `RBACManager.php` — Role-based access control met cache-ondersteuning
- `CsrfProtection.php` — CSRF token generatie en validatie

**Cache Systeem**
- `CacheManager.php` — PSR-16 cache facade met `remember()` helper
- `FileCache.php` — File-based cache driver met TTL ondersteuning

**Queue Systeem**
- `Job.php` — Abstract base class voor queue jobs
- `QueueManager.php` — Database-gebaseerde job queue

**Module & Block API**
- `ModuleInterface.php` — Contract interface voor alle modules
- `BlockInterface.php` — Contract interface voor block types
- `AbstractBlock.php` — Abstract base class voor block implementaties

**Repository Laag**
- `NewsRepository.php` — Nieuws CRUD met cache-aside pattern
- `PageRepository.php` — Pagina's ophalen + menu query
- `SettingsRepository.php` — Site-instellingen met type casting

**API & Middleware**
- `AuthMiddleware.php` — Sessie + JWT authenticatie middleware

**Project Setup**
- `composer.json` — PSR-4 autoloading, dependencies definitie
- `.env.example` — Environment variabelen template
- `public/index.php` — Front controller
- `public/.htaccess` — URL rewriting + security rules
- `cli/console.php` — CLI entry point
- `README.md` — Volledige projectdocumentatie
- Volledige mapstructuur aangemaakt

### Technische details

- PHP 8.3+ vereist
- PSR-4, PSR-7, PSR-11, PSR-14, PSR-16 compliant
- Alle bestanden voorzien van GPL-3.0 copyright header + file card

---

<!--
╔══════════════════════════════════════════════════════════════════════╗
║                         FILE CARD                                    ║
╠══════════════════════════════════════════════════════════════════════╣
║  File         : CHANGELOG.md                                         ║
║  Role         : Docs                                                 ║
║  Version      : 1.0.0                                                ║
║  Created      : 2026-06-06                                           ║
║  Last Updated : 2026-06-06  03:00                                    ║
║  Status       : New                                                  ║
║  Notes        : Sprint 1 initiële changelog                          ║
╠══════════════════════════════════════════════════════════════════════╣
║  Created by Dieouwe                                                  ║
║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
║  📦 curseforge.com/members/dieouwe/projects                         ║
║  💬 discord.gg/y8Pu5qsEbQ                                           ║
╚══════════════════════════════════════════════════════════════════════╝
-->

## [1.1.0] — 2026-06-06 — Sprint 2: Installer + Admin + Frontend

### Toegevoegd

**Web Installer (5 stappen)**
- `installer/InstallerCore.php` — Installatie sessie, config schrijver, schema importer
- `installer/index.php` — Installer front controller met step routing
- `installer/steps/Step1.php` — Server requirements check (PHP, extensies, schrijfrechten)
- `installer/steps/Step2.php` — Database verbinding + schema import
- `installer/steps/Step3.php` — Site instellingen (naam, URL, taal, tijdzone)
- `installer/steps/Step4.php` — Admin account aanmaken (argon2id)
- `installer/steps/Step5.php` — Module selectie + config.php genereren
- `installer/templates/layout.php` — Gaming dark wizard UI met stap-indicator
- `installer/templates/step1-5.php` — Visuele formulieren per stap

**Frontend Controllers**
- `src/Modules/News/NewsController.php` — Nieuws index + detail pagina
- `src/Modules/Pages/PageController.php` — Homepage + statische pagina's
- `src/Modules/Users/AuthController.php` — Login, logout, registreren + CSRF
- `src/Modules/Settings/AdminController.php` — Admin dashboard routing

**Admin Dashboard**
- `src/Modules/Settings/views/dashboard.php` — Volledig admin dashboard met sidebar, stat-cards, snelle acties, activiteitsfeed, systeem status

**Template Engine**
- `src/Core/Template/ThemeManager.php` — Twig 3.x integratie, theme.json loader, custom functies (asset, url, csrf_field)

**Default Gaming Dark Thema**
- `themes/default/theme.json` — Thema manifest + kleurenpalette
- `themes/default/templates/layout.twig` — Hoofd layout met zones (header, sidebars, footer)
- `themes/default/templates/home.twig` — Homepage met nieuws grid
- `themes/default/templates/news/index.twig` — Nieuws overzicht
- `themes/default/templates/auth/login.twig` — Login formulier
- `themes/default/templates/auth/register.twig` — Registreer formulier

**Frontend Assets**
- `public/assets/css/blueprint.css` — Volledig gaming dark CSS (CSS variables, layout, cards, forms, responsive)
- `public/assets/js/blueprint.js` — Core JS (nav-highlight, flash messages, scroll animaties)

## [1.2.0] — 2026-06-06 — Sprint 3: Block API + Drag & Drop Layout

### Toegevoegd

**Block Registry (Core)**
- `src/Core/Block/BlockRegistry.php` — Centrale registry voor alle block types. Zone rendering met cache-ondersteuning, createBlock/updateBlock/deleteBlock CRUD, drag & drop positie-opslag via `updatePositions()`, DB-sync van block types

**Block Types (6 stuks)**
- `src/Blocks/Types/TextBlock.php` — Tekst blok met nl2br output, 1u cache
- `src/Blocks/Types/HtmlBlock.php` — Vrij HTML blok voor admins, 30min cache
- `src/Blocks/Types/NewsBlock.php` — Laatste X artikelen als compacte lijst, 5min cache
- `src/Blocks/Types/LoginBlock.php` — Login formulier of welkom-bericht (user-aware, geen cache)
- `src/Blocks/Types/StatsBlock.php` — Site statistieken (leden/artikelen/paginas), 10min cache
- `src/Blocks/Types/AdBlock.php` — Advertentie/banner blok met externe URL, 1u cache

**Block Controller + Admin UI**
- `src/Modules/Blocks/BlockController.php` — CRUD endpoints + drag & drop JSON API (`/api/v1/blocks/positions`, `/api/v1/blocks/zones`)
- `src/Modules/Blocks/views/index.php` — Volledige drag & drop Block Manager admin UI. CSS Grid site-preview met 6 zones (header/topmenu/sidebars/content/footer), block palette met drag-from, zone-droptargets, placed block editing/delete/toggle, Add Block modal, Ajax API-calls met toast feedback

**Router uitgebreid**
- Block admin routes: GET/POST `/admin/blocks`, `/admin/blocks/{id}/update`, `/admin/blocks/{id}/delete`
- Block API routes: GET `/api/v1/blocks/zones`, POST `/api/v1/blocks/positions`

**CSS uitgebreid**
- `public/assets/css/blueprint.css` — Block-specifieke CSS: `.cf-block-*` classes voor Text, News lijst, Login, Stats, Ad blocks

## [1.3.0] — 2026-06-06 — Sprint 4: Discord OAuth + Twitch Integratie

### Toegevoegd

**OAuth2 Architectuur (Abstract Base)**
- `src/Core/Auth/OAuth/OAuthClient.php` — Abstract OAuth2 base class. Bouwt authorization URL op, wisselt auth code in voor tokens, haalt user-data op, slaat tokens AES-256-GCM encrypted op in cf_user_oauth, biedt token refresh, HTTP helpers (POST/GET via cURL)

**Discord Module** (`modules/discord/`)
- `module.json` — Module manifest: slug, class, hooks, permissions, settings schema
- `src/DiscordOAuth.php` — Discord OAuth2 client. Authorization URL, token exchange, user fetch, guild member API, bot token support, widget data, avatar URL helper
- `src/DiscordModule.php` — Module boot: registreert blocks, sync job op login, OAuth routes via hook
- `src/DiscordOAuthController.php` — OAuth flow controller: redirect → callback → rol synchronisatie → DB opslag
- `src/DiscordWidgetBlock.php` — Officiële Discord widget iframe embed (configureerbaar thema/grootte)
- `src/DiscordOnlineBlock.php` — Online leden via Guild Widget API (cached 60s), join-knop, avatar + status

**Twitch Module** (`modules/twitch/`)
- `module.json` — Module manifest
- `src/TwitchOAuth.php` — Twitch OAuth2 (Authorization Code + Client Credentials). Helix API, live status, channel info, follower count, app token ophalen
- `src/TwitchModule.php` — Module boot: blocks registreren, OAuth routes
- `src/TwitchOAuthController.php` — OAuth flow: redirect → callback → koppeling opslaan
- `src/TwitchLiveBlock.php` — Live/offline status block. Thumbnail, viewer count, game naam (cached 90s)
- `src/TwitchStreamBlock.php` — Twitch player embed (optioneel met chat, muted, hoogte)

**Discord Rol Synchronisatie**
- DB schema: `cf_discord_role_mapping` + `cf_discord_sync_log`
- Queue-based sync bij elke login: Discord rollen → CMS rollen (auto-assign + auto-remove)
- RBAC cache invalidatie na sync

**CLI Queue Worker**
- `cli/commands/QueueWorkerCommand.php` — Database queue worker: reserveer, verwerk, retry, fail-markering
- `cli/commands/CacheClearCommand.php` — Cache wissen (file + Twig cache)

**CSS uitgebreid**
- Discord: widget, online leden, avatar, status dot
- Twitch: live badge, thumbnail, channel link, embed wrap

## [1.4.0] — 2026-06-06 — Sprint 5: Guild Management + WoW + Minecraft + FiveM

### Toegevoegd

**Guild Management Module** (`modules/guild-management/`)
- `GuildModule.php` — Boot + installer: DB schema (cf_guild_ranks, cf_guild_members, cf_guild_teams, cf_guild_applications), seed-rangen (GM/Officer/Raider/Trial/Social)
- `GuildController.php` — Publieke pagina's: index, roster (met rang-filter), aanmelding
- `GuildAdminController.php` — Admin: aanmeldingen beheren, approve (→ trial lid), reject
- `GuildMembersBlock.php` — Blok: actieve leden gesorteerd op rang, class-iconen, iLvl
- `GuildInfoBlock.php` — Blok: statistieken (leden/aanmeldingen), teams, aanmeld-knop
- `GuildRecruitmentBlock.php` — Blok: vrije tekst + actief wervende teams
- `templates/apply.php` — Aanmeldingsformulier: karakter/klasse/spec/iLvl/team/motivatie
- `templates/members.php` — Roster met rang-filters, kleur-coded karakterkaarten

**World of Warcraft Module** (`modules/warcraft/`)
- `BlizzardApiClient.php` — Battle.net OAuth2 Client Credentials API client:
  guild roster, guild info, guild activity, character profiel, equipment,
  Mythic+ score via Raider.IO, raid progress via Raider.IO, realm status.
  Volledige response cache (PSR-16). Regio/locale configureerbaar.
- `WarcraftModule.php` — Boot + blok registratie + admin/public routes
- `WarcraftController.php` — Publiek: index (guild + progress), guild roster, character
- `WarcraftAdminController.php` — Admin instellingen: API keys, realm, guild, regio
- `WowGuildRosterBlock.php` — Blok: live roster via Blizzard API, klasse-kleuren, rang-iconen
- `WowMythicProgressBlock.php` — Blok: Normal/Heroic/Mythic progress bars via Raider.IO
- `WowCharacterBlock.php` — Blok: character profiel + iLvl + Raider.IO M+ score
- `templates/index.php` — WoW pagina: guild hero banner, raid progress, top roster
- `templates/admin.php` — Admin settings form met API-instructies

**Minecraft Module** (`modules/minecraft/`)
- `MinecraftModule.php` — Module boot + routes
- `MinecraftStatusBlock.php` — Server status via mcsrvstat.us API: online/offline, versie, spelers-count, MOTD, spelerslijst (60s cache)

**FiveM Module** (`modules/fivem/`)
- `FiveMModule.php` — Module boot + routes
- `FiveMStatusBlock.php` — Server status via FXServer /info.json + /players.json: online/offline, spelerlijst met ping, bezetting-balk (45s cache)

**CSS uitgebreid**
- Guild: member-rows, stats, teams, recruitment
- WoW: guild naam gradient, progress bars, character stats, Raider.IO score kleuren
- Minecraft: server status, MOTD, spelers-chips
- FiveM: status, spelers-chips, ping-badge

## [1.5.0] — 2026-06-06 — Sprint 6: REST API v1 Compleet + Ollama AI Integratie

### Toegevoegd

**REST API v1 — Volledig OAS-compliant**
- `src/Api/V1/StatusController.php` — GET /api/v1/status: versie, PHP, DB status, timestamp
- `src/Api/V1/AuthController.php` — POST /api/v1/auth/login (JWT token), GET /api/v1/auth/me
- `src/Api/V1/UsersController.php` — GET /api/v1/users (paginering, admin only), GET /api/v1/users/{id}
- `src/Api/V1/ContentController.php` — GET /api/v1/news, /api/v1/news/{slug}, /api/v1/pages, /api/v1/blocks/zones

**Middleware**
- `src/Api/Middleware/RateLimitMiddleware.php` — 60 req/min per IP, X-RateLimit headers, 429 response
- `src/Api/Middleware/CorsMiddleware.php` — CORS headers voor alle API routes, OPTIONS preflight

**Router v1.2.0**
- Alle REST API v1 routes met CORS + RateLimit middleware
- GET /api/v1/auth/me met Auth middleware
- Middleware stacking: CORS + Auth + RateLimit combineerbaar

**Ollama AI Module** (`modules/ollama/`)
- `module.json` — Manifest: blocks, settings (host, model, timeout, system_prompt, Open WebUI)
- `OllamaClient.php` — Volledig PHP client voor Ollama REST API:
  - `generate()` — enkelvoudige prompt
  - `chat()` — multi-turn conversatie met geschiedenis
  - `embed()` — embedding vectors
  - `listModels()` — beschikbare modellen
  - `isAvailable()` — health check (3s timeout)
  - `chatViaOpenWebUI()` — OpenAI-compatible API via Open WebUI v0.9.2
  - `summarizeNews()` — nieuws samenvatting met cache
  - `analyzeGuildApplication()` — WoW guild aanmelding AI-beoordeling
  - `communityChat()` — context-aware community chatbot
  - `generateRecruitmentPost()` — AI guild recruitment tekst
- `OllamaModule.php` — Boot: blocks, routes, guild application hook (auto AI analyse)
- `OllamaChatBlock.php` — Live chat widget met AJAX, gesprekgeschiedenis, typing indicator
- `OllamaAssistantBlock.php` — Statische AI content: tips, recruitment, welkomst (1u cache)
- `OllamaApiController.php` — POST /api/ollama/chat, POST /api/ollama/summarize, GET /api/ollama/models
- `OllamaAdminController.php` — Instellingen admin, model overzicht, verbindingstest
- `templates/admin.php` — Admin UI: status badge, model lijst, settings form, Docker instructies

**Open WebUI Analyse** (zie rapport hieronder)
- Geïntegreerd als optionele backend naast directe Ollama
- OllamaClient detecteert automatisch fallback naar directe Ollama als Open WebUI niet beschikbaar

### Gewijzigd
- `src/Core/Router.php` v1.2.0 — REST API + middleware stack uitgebreid

## [1.6.0] — 2026-06-06 — Sprint 7: Marketplace

### Toegevoegd

**Marketplace Database Schema**
- `cf_marketplace_packages` — Package catalogus (slug, type, versie, download URL, tags, downloads, rating, featured, verified, premium)
- `cf_marketplace_installed` — Installatie registry (versie, pad, enabled status, update beschikbaar)
- `cf_marketplace_reviews` — Beoordelingen per package (rating, review tekst)
- Seed-data: 7 modules + 1 thema met realistisch downloadaantal

**PackageManager** (`src/Core/Marketplace/PackageManager.php`)
- `install(slug, url)` — Download ZIP → path-traversal validatie → extractie → manifest validatie → deployen → DB registreren → module installer uitvoeren
- `installFromUpload(tmp, name)` — Installatie vanuit geüpload bestand
- `uninstall(slug)` — Veiligheidscheck (core modules blokkeren) → uninstall hook → bestanden verwijderen → DB cleanup
- `update(slug)` — Bestaande installatie vervangen door nieuwste versie
- `enable(slug)` / `disable(slug)` — Module in/uitschakelen (core blokkeert)
- `checkForUpdates()` — Vergelijk geïnstalleerde vs catalogus versies (gecached 1u)
- `getCatalog(type, search, sortBy, limit, offset)` — Gefilterde/gesorteerde catalogus (gecached 5min)
- `getInstalledPackages()` — Alle geïnstalleerde packages met catalogusinfo

**InstallResult + PackageException** — Type-safe value objects

**MarketplaceController** (`src/Modules/Marketplace/MarketplaceController.php`)
- GET /admin/marketplace — Volledig admin UI (4 tabs)
- POST /admin/marketplace/install — Package installeren via URL
- POST /admin/marketplace/upload — ZIP upload installatie
- POST /admin/marketplace/uninstall — Package verwijderen
- POST /admin/marketplace/toggle — Enable/disable
- POST /admin/marketplace/update — Update naar nieuwste versie
- GET /api/v1/marketplace — Publieke catalogus API
- GET /api/v1/marketplace/installed — Geïnstalleerde packages (auth)
- GET /api/v1/marketplace/updates — Beschikbare updates (auth)

**Marketplace Admin UI** (`src/Modules/Marketplace/views/index.php`)
- Tab 1 — Browsen: package grid met zoeken, type-filters, sortering
- Tab 2 — Geïnstalleerd: toggle switches, update badges, verwijder knoppen
- Tab 3 — Updates: update-beschikbaar lijst, bulk update knop
- Tab 4 — ZIP Upload: drag & drop zone, progress bar, validatie-instructies
- Volledig Ajax (geen page reload) met toast notificaties

**Router v1.3.0** — 10 marketplace routes toegevoegd (web + API)

## [1.6.1] — 2026-06-06 — Documentatie + Analyse + Mockup

### Toegevoegd
- `docs/ANALYSE.md` — Volledig statisch analyse rapport: code metrics, security audit, PSR compliance, routes inventaris, sprint overzicht
- `docs/assets/architecture.md` — Architectuur documentatie: request flow, module structuur, block pipeline
- Frontend mockup visualisatie toegevoegd aan project documentatie

## [1.6.2] — 2026-06-06 — Frontpage Mockup + Docs compleet

### Toegevoegd
- `docs/MOCKUP-FRONTPAGE.html` — Volledig standalone HTML mockup van de Blueprint CMS frontpage. Gaming dark thema, 3-kolom layout (sidebar + content + sidebar), hero sectie, nieuws grid, events, Discord widget, Twitch live, Ollama AI chat block, WoW raid progress, community stats, responsive footer. Voorbeeld guild: Slayer Alliance — EU Sporeggar.

### Zichtbare features in de mockup
- Header met logo, navigatie, Discord + login knoppen
- Hero met guild naam, progression stats (8/8H, 4/8M), CTA knoppen
- Linker sidebar: login form (inclusief Discord OAuth), WoW raid progress bars (Blizzard + Raider.IO), community statistieken
- Main content: featured nieuws artikel + 2-kolom nieuws grid, events met datum-badge + type-tag
- Rechter sidebar: Twitch live stream status, Discord online leden met status-indicator, Ollama AI community chatbot
- Footer met guild naam, links, "Powered by Blueprint CMS v1.6.0"

## [1.7.0] — 2026-06-06 — Sprint 8: Debug + Fixes + Roadmap Update

### Opgelost (15 kritieke bugs)

**Bug fixes**
- `BlockController.php` — `$this->registry->db()` bestond niet → directe `Connection` dependency toegevoegd (KRITIEK)
- `src/Modules/Users/OAuthController.php` — volledig ontbrak terwijl Router ernaar verwees → aangemaakt met Discord + Twitch dispatch (KRITIEK)
- `modules/warcraft/templates/guild.php` — ontbrak → aangemaakt (KRITIEK)
- `modules/warcraft/templates/character.php` — ontbrak → aangemaakt met iLvl + M+ score (KRITIEK)
- `modules/minecraft/src/MinecraftController.php` — ontbrak → aangemaakt (KRITIEK)
- `modules/minecraft/templates/index.php` — ontbrak → aangemaakt (KRITIEK)
- `modules/fivem/src/FiveMController.php` — ontbrak → aangemaakt (KRITIEK)
- `modules/fivem/templates/index.php` — ontbrak → aangemaakt (KRITIEK)
- `modules/guild-management/templates/admin.php` — ontbrak → aangemaakt met volledige admin UI (KRITIEK)
- `src/Modules/Marketplace/views/detail.php` — ontbrak → aangemaakt (KRITIEK)
- `themes/default/templates/news/show.twig` — ontbrak → aangemaakt (KRITIEK)
- `themes/default/templates/pages/default.twig` + `full.twig` — ontbraken → aangemaakt (KRITIEK)
- `PackageManager.php` — CF_ROOT class constants werkten niet vóór Application bootstrap → runtime methoden (WAARSCHUWING)
- `AdminController.php` — settings() + handle() methoden ontbraken → toegevoegd + settings.php view (KRITIEK)
- `ThemeManager.php` — render_block() Twig function niet geregistreerd → setBlockRegistry() + Twig function (KRITIEK)

**Verbeteringen**
- `Application.php` — BlockRegistry singleton + core blocks geregistreerd + ThemeManager↔BlockRegistry verbonden
- `composer.json` — PSR-4 autoload uitgebreid met alle 7 module namespaces (modules/ viel buiten autoload)
- `src/Modules/Settings/views/settings.php` — settings admin view aangemaakt

### Roadmap bijgewerkt
- Sprint 1-8 als voltooid gemarkeerd
- Sprint 9-12 toegevoegd: Forum, YouTube/Kick, Premium ecosysteem, Multi-language
- README.md volledig bijgewerkt met actuele status

## [1.7.1] — 2026-06-06 — Finale analyse + docs

### Opgelost
- `AbstractBlock.php` — `getName()` abstract method ontbrak → toegevoegd

### Bijgewerkt
- `docs/ANALYSE.md` — volledig bijgewerkt met Sprint 8 resultaten, 0 issues resterend
- Analyse bevestigt: 0 broken routes, 0 missing templates, 0 module issues

