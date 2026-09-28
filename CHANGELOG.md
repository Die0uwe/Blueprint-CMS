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

