# Integraties & widgets — analyse en to-do (2026-09-30)

Aanleiding (Ouwe): de configuratiepagina's voor o.a. de Discord-widget zijn ingevuld, maar de widget blijft leeg en er is nergens een kanaal in te stellen.
Gecontroleerd tegen de Discord-documentatie (docs.discord.com: Guild Widget, OAuth2). Dit is een code-analyse; de live database/site is niet bekeken.

## 1. Waarom de widget leeg blijft — oorzaken

| # | Oorzaak | Waar | Status |
|---|---|---|---|
| 1 | **Blok-instellingen kunnen nergens worden ingevuld.** `/admin/blocks` heeft alleen "toevoegen" (met `config: {}`), verplaatsen, verbergen, verwijderen. Het `getConfigSchema()` van elk block (server_id, thema, breedte, invite-URL, max leden…) wordt door geen enkel scherm gebruikt. | `Blocks/views/index.php`, `BlockController` | ✅ opgelost (stap d): ⚙️-knop per blok met formulier uit het schema |
| 2 | **`BlockController::update()` wist de config** (`json_encode([])`) zodra je alleen een blok verbergt of verplaatst. Ingevulde waarden zijn daarna weg. | `BlockController::update` | ✅ opgelost: `update()` wijzigt alleen wat wordt meegestuurd (+ integratietest) |
| 3 | Module-instellingen (`guild_id` etc.) worden alleen gelezen als de **Discord-module aan staat** (`cf_modules.is_enabled = 1`). Modules worden alleen aangezet in installer-stap 5 of via Marketplace. De instellingenpagina werkt ook voor een module die niet aan staat — dan lijkt alles opgeslagen, maar de blocks bestaan niet. | `Application::loadModule`, `ModuleSettingsController::loadSchema` | ✅ waarschuwing "module staat uit" in de instellingenpagina (`ModuleSettingsController` + view); Discord-beheer toont dezelfde waarschuwing |
| 4 | **Discord-kant:** `widget.json` geeft niets terug (HTTP 403/404) zolang de widget niet aanstaat; volgens de docs bestaan `enabled` en `channel_id` (het widget-/uitnodigingskanaal) als instellingen. *(Correctie 30-09: het menupad in de Discord-app is niet officieel gedocumenteerd en kan verplaatst zijn — niet als feit noemen. Betrouwbare route: via de API, zie #13.)* Zonder kanaal is `instant_invite` leeg. Dit is het "kanaal" dat nergens staat. | Discord-docs | uitleg + foutmelding in block (to-do) |
| 5 | `DiscordOnlineBlock` cachet 60 s en cachet ook de foutmelding; na instellen bleef die tot een minuut staan. | `DiscordOnlineBlock` | ✅ alleen succes wordt gecachet, per oorzaak (403/404/429) een duidelijke melding |
| 6 | `DiscordWidgetBlock` leest `$config['server_id']` zonder `??` (PHP-warning, onzichtbaar omdat `error_reporting(0)`), `$inviteUrl` is een ongebruikte placeholder. | `DiscordWidgetBlock` | ✅ opgelost |

## 2. Discord — login en rolsynchronisatie

| # | Bevinding | Fix |
|---|---|---|
| 7 | `prompt=none` in de authorize-URL: volgens de Discord-docs slaat dit het toestemmingsscherm over. Een **nieuwe** gebruiker die nog nooit toestemming gaf krijgt dan een fout en kan dus niet voor het eerst inloggen. | ✅ `prompt=none` verwijderd |
| 8 | De callback moet **exact** gelijk zijn aan de Redirect-URI in het Developer Portal. Fallback is `APP_URL + /auth/discord/callback`; staat `APP_URL` leeg of http i.p.v. https, dan werkt login niet. | Controle/testknop op instellingenpagina |
| 9 | **Geen beheerscherm voor rolkoppeling** (`cf_discord_role_mapping` wordt nergens beheerd): Discord-rol-ID → CMS-rol kan alleen via SQL. | ✅ **Beheer → Discord → Rolkoppeling** (`/admin/discord/rollen`): CRUD op `cf_discord_role_mapping`, Discord-rol kiezen uit de lijst (bot) of ID invullen, `super_admin`/`admin` zijn beschermd |
| 10 | Bij login wordt een job `discord-sync` in `cf_queue_jobs` gezet met `serialize()`; er is **geen handler** die hem afhandelt, en `serialize` is een risico als er ooit `unserialize` op gebeurt. | ✅ handler `DiscordRoleSync` + queue-job `DiscordRoleSyncJob` (zie "Wachtrij" hieronder). Gekozen route: **geen JSON** maar een `Job`-object dat alleen het user-ID (int) serialiseert, omdat `QueueManager::push()` `serialize(Job)` schrijft en de worker `unserialize()` doet; een JSON-payload zou door de worker als ongeldig worden afgewezen |
| 11 | Bot-token is optioneel maar geeft betrouwbaardere rol-sync; de bot moet in de server zitten. Geen test/"verbind"-knop die dit controleert. | ✅ **Beheer → Discord → Status** met knop "Test verbinding" (token geldig? bot in server? widget aan? kanaal? rechten) |
| 13 | **Widget aanzetten via de API:** `PATCH /guilds/{id}/widget` met `{"enabled":true,"channel_id":"…"}` (bot-token met *Manage Server*) en `GET /guilds/{id}/widget` om de stand te lezen (docs.discord.com → Guild → Widget). Bouw in de Discord-instellingen een "Widget-status" met knop "Inschakelen" + kanaalkeuze (kanalen via `GET /guilds/{id}/channels`). Dat lost ook "nergens een kanaal" op. | ✅ **Beheer → Discord → Widget**: stand lezen, kanaalkeuze (tekst-/aankondigingskanalen), "Inschakelen"/"Uitschakelen" via `PATCH /guilds/{id}/widget`; Discord's eigen foutmelding wordt getoond, hint op `MANAGE_GUILD`, bot-invite-URL (`permissions=1056`, `scope=bot`) zodra het Client ID bekend is |
| 12 | Er is **geen instelling voor een kanaal** voor meldingen (bijv. nieuwsartikel → Discord-kanaal via webhook). Hoort bij wat Ouwe mist. | ✅ **Beheer → Discord → Meldingen**: webhook (versleuteld, nooit teruggetoond), "testbericht sturen", aan/uit "nieuws melden"; hook `news.published` (eerste publicatie) → embed met titel, link en samenvatting |

## 3. Overige logins

- **Google**: module aanwezig; `prompt=select_account` is goed. Controle nodig: Redirect-URI in Google Cloud Console, en dat Google-module aan staat.
- **Twitch, Battle.net**: modules aanwezig; zelfde controlepunten.
- **GitHub**: **bestaat niet** in Blueprint-CMS (wel in ScriptSpace). Toevoegen als nieuwe module.
- **Loginpagina** toont alle knoppen altijd, ook voor providers die niet zijn ingesteld → klik geeft foutredirect. Verbergen wat niet geconfigureerd/aan is.
- **Profielpagina** "gekoppelde accounts" bestaat; overzicht voor meer socials (YouTube, Facebook…) ontbreekt in Blueprint (is wel gebouwd in ScriptSpace, `includes/socials.php`).

## 3b. Extra gevonden tijdens stap d

- Het beheerscherm toonde via `getZoneBlocks()` **alleen zichtbare blokken**: een verborgen blok verdween uit de lijst en kon niet meer worden teruggezet. ✅ opgelost (`getZoneBlocksForAdmin`).
- Blok-instellingen worden nu server-side gevalideerd en begrensd (`BlockSettings`: onbekende sleutels weg, typen/min/max/select/URL-schema http(s)); 7 unit- en 5 integratietests.

## 4. Plan (volgorde)

1. ✅ Stap d (deel 1) — schema-renderer + config bewaren (lost #1 en #2 op). Rest van stap d: Markup-tab/override en zone-preview.
2. ✅ (Discord-deel) Instellingenpagina modules: waarschuwing "module staat uit", "Test verbinding" (Discord bot/guild, widget aan? kanaal?), tonen van de juiste callback-URL, kanaalvelden (invite-kanaal, meldingenkanaal/webhook).
3. Discord: `prompt=none` weg, widget-block robuust (defaults, duidelijke foutmeldingen per oorzaak #4), cache-fix.
4. ✅ Rolkoppeling-beheerscherm + sync-handler.
5. Loginpagina alleen geconfigureerde providers; GitHub-module; gekoppelde-accounts-overzicht uitbreiden.
6. ✅ Webhook "nieuw bericht → Discord-kanaal".

Zie ook `docs/editors-plugins-plan.md`.

## 5. Discord-beheer — wat er is gebouwd (module `modules/discord`)

Schermen onder `/admin/discord` (permissie `discord.admin`, alle POSTs CSRF-beschermd, routes via de `router.routes`-hook met `AuthMiddleware` + `PermissionMiddleware:discord.admin`, menu-item via filter `admin.menu`):
Status · Widget · Meldingen · Rolkoppeling. Ook de **callback-URL** (`APP_URL` + `/auth/discord/callback`) staat op het Status-scherm, met waarschuwing bij lege `APP_URL` of `http://` (#8).

| Onderdeel | Bestand |
|---|---|
| Bot-client (`Authorization: Bot …`, API v10, timeouts, geen redirects, NL-foutmeldingen incl. Discord's `message`, 401/403/404/429 apart, snowflake-validatie) | `DiscordApi`, `DiscordTransport` (interface), `CurlDiscordTransport` |
| Webhook (strikte URL-validatie, herbouwen, `allowed_mentions: {parse: []}`, limieten 2000/256/4096) | `DiscordWebhook` |
| Nieuwsmelding (hook `news.published`, fouten loggen, publicatie faalt nooit) | `DiscordNewsAnnouncer` |
| Rol-sync | `DiscordRoleSync`, `DiscordRoleSyncJob` |
| Blok `discord-status` (widget-vrij: ledenaantal/online via bot-token, 5 min cache alleen bij succes) | `DiscordStatusBlock` |
| Admin | `DiscordAdminController`, `views/admin.php` |
| Instellingen/rollen/log | `DiscordStore` |

**Nieuwe instellingen** (`module.json`): `webhook_url` (encrypted) en `announce_news` (bool). De webhook-URL is een geheim: versleuteld opgeslagen, in het scherm alleen "ingesteld ✔ …laatste 4 tekens". Ook bij het versturen wordt de URL opnieuw gevalideerd, dus een via het generieke instellingenscherm ingevulde kwaadaardige URL wordt nooit gebruikt.

**Wachtrij / rol-sync.** Bij `user.login` wordt een `DiscordRoleSyncJob` in de queue `discord-sync` gezet (alleen als de gebruiker Discord heeft gekoppeld en server-ID + bot-token bekend zijn). Verwerken: `php cli/console.php queue:work --queue=discord-sync`. De job serialiseert uitsluitend het user-ID. Beschermde rollen (`super_admin`, `admin`) worden nooit via sync toegekend of ingetrokken (ook niet bij een handmatige SQL-koppeling; dat wordt gelogd als `role_blocked`). Niet-lid van de server (404) of een Discord-fout laat de rollen ongewijzigd; tijdelijke fouten (429/5xx/netwerk) laten de job opnieuw proberen. De login-callback (`DiscordOAuthController::syncRoles`) gebruikt nu dezelfde `DiscordRoleSync::applyRoles()`, dus ook daar zijn admin-rollen beschermd.

**Niet geverifieerd tegen echte Discord** (sandbox had geen toegang; alles getest met een nep-transport):
- Dat `GET /users/@me/guilds` met een bot-token het veld `permissions` van de bot teruggeeft (gebruikt voor "rechten van de bot"); ontbreekt het, dan toont het scherm "niet te bepalen" en werkt de rest gewoon.
- Dat `GET /guilds/{id}/members/{user}` met alleen een bot-token werkt zonder extra intent-instellingen (Discord kan hiervoor de *Server Members Intent* vragen).
- Exacte foutcodes/-teksten van Discord bij een ontbrekend recht (403) — de tekst wordt letterlijk doorgegeven.

**Bekende beperking.** Het versturen van de nieuwsmelding gebeurt synchroon tijdens het opslaan van het artikel (time-out 5 s). Mislukt het, dan wordt dat gelogd en gaat het publiceren gewoon door; een wachtrij voor meldingen is een mogelijke vervolgstap. De worker (`cli/commands/QueueWorkerCommand.php`) doet `unserialize()` op elke payload zonder `allowed_classes`; dat is alleen veilig zolang niemand buiten ons in `cf_queue_jobs` kan schrijven — aanbeveling: daar `['allowed_classes' => [...]]` of een JSON-jobformaat van maken.
