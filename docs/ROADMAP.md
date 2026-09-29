<!--
============================================================================
Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
GPL-3.0-or-later
============================================================================
-->

# Stappenplan — Blueprint CMS na de totale codebase-audit (v1.25.5–v1.25.9)

> Opgesteld op verzoek van de gebruiker: *"analiseer de hele github en zet iedereen aan het werk
> voor een totale analise van de code tot nu toe... maak de github compleet de installer werkend
> en maak dan een stappenplan hoe verder te gaan met de cms en uitbreiding ervan."*
>
> Dit document is de opvolging van die opdracht. Voor de volledige technische details per fix,
> zie `CHANGELOG.md` (v1.25.4 t/m v1.25.9). Voor de oorspronkelijke productroadmap (Sprint/Wave/S-
> fasering), zie de roadmap-tabel in `README.md`.

---

## 1. Wat er is gebeurd

Zeven onafhankelijke deelaudits, elk uitgevoerd tegen de actuele codebase (niet tegen
verouderde documentatie — zie het `docs/ANALYSE.md`-archiveringspunt hieronder):

| # | Deelaudit | Status | Belangrijkste vondst |
|---|---|---|---|
| 1 | Security (1e pas) | ✅ Afgerond, gefixt | KRITIEK: rol-priority-escalatie via `/admin/roles` |
| 2 | Security (herscan) | ✅ Afgerond, gefixt | KRITIEK: guild/ollama-adminpanels open voor elk lid |
| 3 | Architectuur/code-kwaliteit | ✅ Afgerond, 1 gefixt | HOOG: `cache.driver=redis` crashte; rest is backlog |
| 4 | Database/schema | ✅ Afgerond, backlog | HOOG: geen echte migratierunner; forum-cascade-landmine |
| 5 | Frontend/thema's/i18n | ✅ Afgerond, backlog | KRITIEK: i18n-dekking zeer beperkt buiten 4 templates |
| 6 | Module-volledigheid | ✅ Afgerond, geen actie nodig | Alle 11 modules "Compleet"; Rust/Ark bevestigd nooit gepland |
| 7 | Documentatie/GitHub-compleetheid | ✅ Afgerond, gefixt | CHANGELOG-ordeningsbreuk, ontbrekende standaardbestanden |

**Al gefixt, live getest en gepusht** (zie CHANGELOG voor het volledige verhaal per entry):

- **v1.25.5** — KRITIEK: rol-priority-escalatie (`/admin/roles` liet een admin zijn eigen rol
  boven `super_admin` optillen, en zo de v1.25.0-privilegefix omzeilen).
- **v1.25.6** — "GitHub compleet": CHANGELOG-ordeningsbreuk, `docs/ANALYSE.md` gearchiveerd,
  CONTRIBUTING/CODE_OF_CONDUCT/SECURITY.md + issue-/PR-templates, composer.json-metadata.
- **v1.25.7** — HOOG: stored XSS via blogposts (`|raw` op ledencontent zonder contentpermissie).
- **v1.25.8** — HOOG: `cache.driver=redis` gaf een kale fatal error i.p.v. een duidelijke fout.
- **v1.25.9** — KRITIEK: guild/ollama-adminpanels — dezelfde "permissie gedeclareerd, nooit
  geseed"-bugklasse als vier eerdere keren in dit project, nu ook hier gefixt; plus rate
  limiting op de voorheen onbeperkte publieke `/api/ollama/*`-endpoints.

Alle vijf zijn individueel live getest tegen een echte MariaDB-instantie (exploit-poging vóór de
fix bevestigd werkend, ná de fix geblokkeerd; legitiem gebruik expliciet gecontroleerd op
regressie) en opgeruimd na afloop — zie elke CHANGELOG-entry voor het exacte testscenario.

**Nog niet gefixt — bewust, om scope behapbaar te houden.** Fase 0 hieronder zet deze
resterende bevindingen op volgorde.

---

## 2. Fase 0 — Resterende audit-bevindingen (aanbevolen: eerst, vóór nieuwe features)

Elke regel hieronder is een **concrete, geverifieerde** bevinding uit de audits — geen
vermoedens. Geprioriteerd op combinatie van ernst en hoe makkelijk hij te misbruiken/raken is.

### 2.1 Beveiliging (Hoog/Gemiddeld — resterend na v1.25.5/v1.25.9)

| Bevinding | Ernst | Waar | Aanbevolen aanpak |
|---|---|---|---|
| Geen brute-force-bescherming op login | Hoog | `AuthManager::logFailedAttempt()` (`src/Core/Auth/AuthManager.php:316`) logt alleen, met een `// TODO: rate limiting` — README's "max 5 pogingen/15min"-claim is niet geïmplementeerd | Per identifier+IP een pogingenteller met lockout in `attempt()` |
| Rate limiting dekt bijna niets | Hoog | Alleen `/api/v1/users` (GET) en `/api/v1/news` (GET) hebben `RateLimitMiddleware` — `/api/v1/auth/login`, `/login`, `/register`, `/contact`, `/guild/apply` hebben geen enkele limiet | `$rate` toevoegen aan alle publieke POST/auth-routes, niet alleen twee GET's |
| CSRF ontbreekt op OAuth-disconnect | Gemiddeld | `disconnect()` in Discord/BattleNet/Google/Twitch-OAuth-controllers (bv. `modules/discord/src/DiscordOAuthController.php:143`) | `CsrfProtection::validateRequest()` toevoegen, zelfde patroon als de rest van die controllers |

### 2.2 Architectuur/code-kwaliteit (resterend na v1.25.8)

| Bevinding | Ernst | Waar | Aanbevolen aanpak |
|---|---|---|---|
| Queue-systeem is volledig ongebruikte scaffolding | Hoog | `src/Core/Queue/*` + `cli/commands/QueueWorkerCommand.php` bestaan compleet, maar geen enkele concrete `Job`-subklasse bestaat en niets roept `::dispatch()` aan; geen cron/systemd-entry voor `queue:work` | Óf de eerste echte job bouwen (Discord-rolsync is de voor de hand liggende kandidaat) + een cron-entry, óf het queue-systeem expliciet als "nog niet in gebruik" documenteren |
| Geen circular-dependency-guard in de DI-container | Gemiddeld | `Container::autoResolve()` (`src/Core/Container.php:107-132`) recursief zonder een "in-behandeling"-stack | Een `$resolving`-array bijhouden en een duidelijke `RuntimeException` gooien i.p.v. een stack-overflow-fatal |
| Inconsistente error-handling tussen controllers | Gemiddeld | `Application::handleException()` respecteert `APP_DEBUG` correct, maar bv. `MarketplaceController::install()` (`src/Modules/Marketplace/MarketplaceController.php:141`) toont `$e->getMessage()` ongeacht debug-modus — enkele andere controllers doen dit ook per-actie op hun eigen manier | Eén gedeelde "safe error message"-helper die overal via hetzelfde `APP_DEBUG`-gedrag gaat |
| Router-conventie ("specifieke route vóór generieke") is alleen een commentaar, geen test | Gemiddeld | `src/Core/Router.php` — de v1.25.0-routing-bugklasse kan zich herhalen zodra een nieuwe module een generieke route vóór een specifieke registreert in zijn eigen `boot()` | Een lichte route-collision-test die dit afvangt vóór het live gaat |
| `FileCache` heeft geen lock rond de miss-then-recompute-flow | Laag | `src/Core/Cache/FileCache.php` | Alleen relevant bij hoge concurrency; nu geen actie nodig |

### 2.3 Database/schema

| Bevinding | Ernst | Waar | Aanbevolen aanpak |
|---|---|---|---|
| Geen echte migratierunner — `schema.sql` is one-shot | Hoog | `cli/commands/MigrateCommand.php:64-65` roept alleen `InstallerCore::importSchema()` aan opnieuw — kan nieuwe tabellen toevoegen, maar geen `ALTER TABLE` op een bestaande site | Een versiegestuurde `cf_migrations`-tabel + een echte up/down-migratierunner — **dit is de belangrijkste structurele blocker voor "ongoing updates" op een live site** |
| `cf_forum_topics.author_id` cascadeert naar andermans replies | Hoog | `src/Core/Database/schema.sql:346` — `ON DELETE CASCADE` op `fk_ft_author`; een toekomstige "account permanent verwijderen"-feature zou hele forumtopics + alle reacties van ANDERE gebruikers wegvagen | `SET NULL` (met "[verwijderde gebruiker]"-fallback) of `RESTRICT` i.p.v. `CASCADE` |
| `cf_guild_applications` mist FK's op `user_id`/`team_id`/`reviewed_by` | Gemiddeld | `modules/guild-management/src/GuildModule.php:86-103` — enige module waar dit ontbreekt | Expliciete `FOREIGN KEY ... ON DELETE SET NULL` toevoegen, zoals overal elders |
| N+1 op elke Discord-rolsync | Gemiddeld | `modules/discord/src/DiscordOAuthController.php:185-205` — 2-3 queries per role-mapping per sync | Rollen één keer ophalen, in PHP diffen tegen de mappings, batch-schrijven in één transactie |
| Admin-gebruikerszoekopdracht kan geen index gebruiken | Gemiddeld | `src/Modules/Users/UserRepository.php:38-64` — `LIKE '%term%'` op username/email/display_name | `FULLTEXT`-index, of beperken tot prefix-search (`term%`) |

### 2.4 Frontend/thema's/i18n

| Bevinding | Ernst | Waar | Aanbevolen aanpak |
|---|---|---|---|
| i18n-dekking zeer beperkt | Kritiek | `trans()` wordt gebruikt in slechts 4 van ~25 thema-templates en 3 admin-views; alle content-templates (blog/news/forum/downloads/contact/gallery/home) en alle admin-CRUD-views zijn nog hardcoded Nederlands | Systematisch `trans()`/`Trans::get()` uitrollen over content-templates en admin-views, per module — grootste, langst lopende backlog-item |
| `gaming-dark`-thema is byte-voor-byte identiek aan `default` | Hoog | Elke template onder `themes/gaming-dark/templates/` is identiek aan `themes/default/` — differentiatie komt uitsluitend uit `theme.json`'s kleuren-CSS-variabelen | Ofwel bewust documenteren als "palette-only theming", ofwel `gaming-dark` echte eigen templates/assets geven |
| Mobiele navigatie verdwijnt zonder vervanging | Hoog | `public/assets/css/blueprint.css:379-385` — `@media (max-width: 768px) { .cf-nav { display: none; } }` zonder hamburger-toggle | Een mobiel-menu-toggle bouwen vóórdat de nav verdwijnt |
| Geen echte dark-mode-toggle | Gemiddeld | `layout.twig:40` hardcodet `cf-theme-dark`; beide thema's zijn donker, er is geen licht thema | Een echte licht/donker-toggle bouwen, of de `dark_mode`-capability-claim uit `theme.json` halen |
| Toegankelijkheid: logo/avatars met lege `alt=""` | Gemiddeld | O.a. `layout.twig:47` (logo), meerdere avatar-templates | Betekenisvolle alt-tekst voor het logo; `aria-label` op icon-only-knoppen |

### 2.5 Module-volledigheid en documentatie

Geen actie nodig: alle 11 modules zijn beoordeeld als "Compleet" (echte API-calls, echte
blocks, bereikbare adminpanelen — de eerder bekende routing-bug is al in v1.25.0 gefixt).
Rust- en Ark-modules uit de oorspronkelijke blueprint zijn bevestigd afwezig, maar dat is al
expliciet gedocumenteerd in `CHANGELOG.md` als "genoemd in de blauwdruk, nooit gepland" — geen
nieuwe bevinding. De documentatie-bevindingen zijn allemaal al verwerkt in v1.25.6.

---

## 3. Fase 1 t/m 3 — Verdere ontwikkeling van de CMS

Onderstaande fasering bouwt voort op de bestaande roadmap in `README.md` (Sprint 1 t/m S13 zijn
al afgerond; S12 Premium ecosysteem is bewust uitgesteld — *"premium is nu niet belangerijk"*).

### Fase 1 — Fundament verstevigen (vóór nieuwe features)
1. Migratierunner bouwen (§2.3) — zonder dit wordt elke toekomstige schema-wijziging een
   handmatige `ALTER TABLE`-actie per site, wat niet opschaalt zodra er meerdere installaties
   in het wild draaien.
2. Rate limiting + brute-force-bescherming afronden (§2.1) — een CMS die publiek registreert
   en OAuth aanbiedt hoort dit dicht te hebben vóór er echt verkeer op staat.
3. i18n-dekking uitbreiden (§2.4) — grootste losse-eindjes-item, en raakt bijna elke module die
   hierna gebouwd wordt (nieuwe content-templates zonder `trans()` vergroten de schuld alleen
   maar).

### Fase 2 — S12: Premium ecosysteem (nu weer oppakken, was uitgesteld)
Licenties, betalingen, premium modules/thema's via de Marketplace. Dit was expliciet na S13
gepland; met Fase 1 afgerond (met name de migratierunner) is de technische basis solide genoeg
om betaalstromen — die per definitie schema-evolutie nodig hebben (facturen, abonnementen,
licentiesleutels) — veilig toe te voegen.

### Fase 3 — Ontbrekende blueprint-modules heroverwegen
Rust en Ark stonden in de oorspronkelijke blueprint maar zijn nooit gebouwd. Voordat hieraan
begonnen wordt: bepalen of hier nog vraag naar is (de huidige gaming-module-line-up —
Minecraft/FiveM/WoW — dekt al de kernbehoefte "server-status + speler-overzicht"; Rust/Ark
zouden dezelfde patronen hergebruiken, dus de marginale bouwkost is laag zodra er concrete
vraag is).

### Doorlopend, niet fase-gebonden
- Thema-consistentie (`gaming-dark` differentiatie), mobiele navigatie, en dark-mode-toggle
  (§2.4) — kunnen onafhankelijk van de fasering opgepakt worden, zodra er ontwikkelcapaciteit
  voor front-end-werk is.
- Architectuur-opruiming (§2.2) — geen van deze is urgent, maar de queue-scaffolding-keuze
  (bouwen of expliciet afschrijven) verdient een bewuste beslissing vóórdat er nog een module
  bovenop gebouwd wordt die er stilzwijgend van uitgaat dat er een werkende queue is.

---

## 4. Hoe dit document te gebruiken

Dit is geen wet van Meden en Perzen — het is een geprioriteerde, onderbouwde lijst op basis van
wat er daadwerkelijk in de codebase is aangetroffen op 2026-09-29. Bij elke volgende
ontwikkelsessie: begin met §2 (Fase 0) afvinken vóór nieuwe features, tenzij een specifieke
feature-vraag van de gebruiker voorrang verdient — dat is aan de gebruiker om te bepalen, niet
aan dit document om af te dwingen.
