# Integraties & widgets — analyse en to-do (2026-09-30)

Aanleiding (Ouwe): de configuratiepagina's voor o.a. de Discord-widget zijn ingevuld, maar de widget blijft leeg en er is nergens een kanaal in te stellen.
Gecontroleerd tegen de Discord-documentatie (docs.discord.com: Guild Widget, OAuth2). Dit is een code-analyse; de live database/site is niet bekeken.

## 1. Waarom de widget leeg blijft — oorzaken

| # | Oorzaak | Waar | Status |
|---|---|---|---|
| 1 | **Blok-instellingen kunnen nergens worden ingevuld.** `/admin/blocks` heeft alleen "toevoegen" (met `config: {}`), verplaatsen, verbergen, verwijderen. Het `getConfigSchema()` van elk block (server_id, thema, breedte, invite-URL, max leden…) wordt door geen enkel scherm gebruikt. | `Blocks/views/index.php`, `BlockController` | ✅ opgelost (stap d): ⚙️-knop per blok met formulier uit het schema |
| 2 | **`BlockController::update()` wist de config** (`json_encode([])`) zodra je alleen een blok verbergt of verplaatst. Ingevulde waarden zijn daarna weg. | `BlockController::update` | ✅ opgelost: `update()` wijzigt alleen wat wordt meegestuurd (+ integratietest) |
| 3 | Module-instellingen (`guild_id` etc.) worden alleen gelezen als de **Discord-module aan staat** (`cf_modules.is_enabled = 1`). Modules worden alleen aangezet in installer-stap 5 of via Marketplace. De instellingenpagina werkt ook voor een module die niet aan staat — dan lijkt alles opgeslagen, maar de blocks bestaan niet. | `Application::loadModule`, `ModuleSettingsController::loadSchema` | controle + waarschuwing in instellingenpagina (to-do) |
| 4 | **Discord-kant:** `widget.json` geeft niets terug (HTTP 403/404) tot *Serversinstellingen → Widget → "Server-widget inschakelen"* aanstaat **en een uitnodigingskanaal** is gekozen. Zonder kanaal is `instant_invite` leeg. Dit is het "kanaal" dat nergens staat. | Discord-docs | uitleg + foutmelding in block (to-do) |
| 5 | `DiscordOnlineBlock` cachet 60 s en cachet ook de foutmelding; na instellen bleef die tot een minuut staan. | `DiscordOnlineBlock` | ✅ alleen succes wordt gecachet, per oorzaak (403/404/429) een duidelijke melding |
| 6 | `DiscordWidgetBlock` leest `$config['server_id']` zonder `??` (PHP-warning, onzichtbaar omdat `error_reporting(0)`), `$inviteUrl` is een ongebruikte placeholder. | `DiscordWidgetBlock` | ✅ opgelost |

## 2. Discord — login en rolsynchronisatie

| # | Bevinding | Fix |
|---|---|---|
| 7 | `prompt=none` in de authorize-URL: volgens de Discord-docs slaat dit het toestemmingsscherm over. Een **nieuwe** gebruiker die nog nooit toestemming gaf krijgt dan een fout en kan dus niet voor het eerst inloggen. | ✅ `prompt=none` verwijderd |
| 8 | De callback moet **exact** gelijk zijn aan de Redirect-URI in het Developer Portal. Fallback is `APP_URL + /auth/discord/callback`; staat `APP_URL` leeg of http i.p.v. https, dan werkt login niet. | Controle/testknop op instellingenpagina |
| 9 | **Geen beheerscherm voor rolkoppeling** (`cf_discord_role_mapping` wordt nergens beheerd): Discord-rol-ID → CMS-rol kan alleen via SQL. | Admin-pagina |
| 10 | Bij login wordt een job `discord-sync` in `cf_queue_jobs` gezet met `serialize()`; er is **geen handler** die hem afhandelt, en `serialize` is een risico als er ooit `unserialize` op gebeurt. | JSON + handler of weghalen |
| 11 | Bot-token is optioneel maar geeft betrouwbaardere rol-sync; de bot moet in de server zitten. Geen test/"verbind"-knop die dit controleert. | "Test verbinding"-knop |
| 12 | Er is **geen instelling voor een kanaal** voor meldingen (bijv. nieuwsartikel → Discord-kanaal via webhook). Hoort bij wat Ouwe mist. | Webhook-URL-instelling + hook op `news.published` |

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
2. Instellingenpagina modules: waarschuwing "module staat uit", "Test verbinding" (Discord bot/guild, widget aan? kanaal?), tonen van de juiste callback-URL, kanaalvelden (invite-kanaal, meldingenkanaal/webhook).
3. Discord: `prompt=none` weg, widget-block robuust (defaults, duidelijke foutmeldingen per oorzaak #4), cache-fix.
4. Rolkoppeling-beheerscherm + sync-handler.
5. Loginpagina alleen geconfigureerde providers; GitHub-module; gekoppelde-accounts-overzicht uitbreiden.
6. Webhook "nieuw bericht → Discord-kanaal".

Zie ook `docs/editors-plugins-plan.md`.
