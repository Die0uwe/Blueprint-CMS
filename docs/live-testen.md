# Checklist — live testen na release 1.30.0

Wat in de sandbox niet kon (geen echte Discord/GitHub-verbinding, geen echte PHPUnit). Doe dit op je eigen machine/server.

## 1. Voorbereiding
- [ ] `git checkout plan/editors-plugins` en `php cli/console.php migrate` (voegt `password_set` toe, maakt `content_markup` MEDIUMTEXT).
- [ ] `composer test` — verwacht ±250 tests groen. Meld afwijkingen (de sandbox gebruikte een mini-runner, geen PHPUnit).
- [ ] `node --test tests/js/tokenizer.test.mjs` — 9 tests.
- [ ] `APP_URL` in `.env` staat op de echte https-URL (anders klopt de callback-URL niet).

## 2. Discord (Beheer → Discord)
- [ ] Module aan, Client ID/Secret, Guild ID en Bot Token ingevuld.
- [ ] Status → "Test verbinding": token geldig, bot in server, permissies.
- [ ] Widget → kanaal kiezen → "Inschakelen". Mislukt het, dan toont de pagina Discord's eigen melding (bot heeft Serverbeheer/MANAGE_GUILD nodig).
- [ ] Blok `discord-widget` en `discord-status` plaatsen en op de site bekijken.
- [ ] Meldingen: webhook-URL plakken → testbericht → nieuw artikel publiceren → verschijnt in het kanaal (één keer).
- [ ] Rolkoppeling: Discord-rol ↔ CMS-rol; log in met Discord en controleer de rol. Open vraag: werkt `GET /guilds/{id}/members/{user}` met alleen een bot-token, of moet de *Server Members Intent* aan?
- [ ] Callback-URL in het Developer Portal exact gelijk aan wat de statuspagina toont.

## 3. Rol-sync worker
- [ ] `php cli/console.php queue:work --queue=discord-sync` als cron of supervisor draaien.

## 4. Logins
- [ ] Loginpagina toont alleen ingestelde providers.
- [ ] GitHub: OAuth App aanmaken, callback `…/auth/github/callback`, in Marketplace → GitHub invullen, inloggen en koppelen.
- [ ] Profiel → Gekoppelde accounts: koppelen/ontkoppelen; je laatste inlogmethode kan niet worden ontkoppeld.

## 5. Editors/plugins
- [ ] Bericht-editor in Firefox en Safari (alleen Chromium is getest).
- [ ] `/admin/plugins`: voorbeeldplugin activeren/deactiveren. Upload blijft uit tenzij `ALLOW_PLUGIN_UPLOAD=true`.

## Bekende vervolgpunten
- Nieuwsmelding gaat synchroon (max. 5 s bij een Discord-storing); queue is een vervolgstap.
- Rate limiting achter een proxy: `X-Forwarded-For` nog niet meegenomen.
- Legacy-routes `/auth/{google,twitch,battlenet}/disconnect` hebben nog geen CSRF-check (profiel gebruikt ze niet meer).
