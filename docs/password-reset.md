# Wachtwoord vergeten en herstellen

## Voor bezoekers
1. Op `/login` staat de link **Wachtwoord vergeten?** (ook in het login-blok).
2. Op `/wachtwoord-vergeten` vul je e-mailadres of gebruikersnaam in.
3. Bestaat er een account, dan komt er een mail met een link naar `/wachtwoord-herstellen/{token}`.
   De link is **60 minuten** geldig en werkt **één keer**.
4. Je kiest een nieuw wachtwoord (minimaal 8 tekens) en logt daarna in.

Het scherm na stap 2 is altijd hetzelfde, ook als er geen account bestaat. Zo kan niemand uitzoeken wie een account heeft.

## Voor beheerders
- **Afzender en verzending:** de mail gaat via `Core\Mail\Mailer` (config `mail`). Zonder SMTP-host valt hij terug op PHP `mail()`.
- **Basis-URL:** de link wordt gebouwd uit `app.url` in `config/config.php`, nooit uit de Host-header. Is `app.url` leeg, dan wordt er niets verstuurd en staat er een regel in het PHP-errorlog.
- **Bestaande installatie:** draai `php cli/console.php migrate` (maakt `cf_password_resets` aan; idempotent).
- **Audit-log** (`/admin/logs`): `auth.password_reset_requested` (met `found` ja/nee) en `auth.password_reset_completed`.
- **Limieten:** 3 aanvragen per account per uur en 5 per IP-adres per uur (ook voor onbekende accounts). Boven de limiet krijgt de bezoeker dezelfde melding, maar er gaat geen mail uit.
- **Reverse proxy:** de IP-limiet gebruikt `REMOTE_ADDR`. Staat de site achter een proxy die dat adres vervangt, dan telt de limiet voor alle bezoekers samen. Laat de proxy dan het echte adres als `REMOTE_ADDR` doorgeven.

## Beveiligingskeuzes
| Onderwerp | Keuze |
|---|---|
| Token | 256 bit uit `random_bytes`; in de database staat alleen de SHA-256-hash (`cf_password_resets.token_hash`) |
| Eenmalig en verlopen | `used_at` en `expires_at`; een nieuwe aanvraag laat het vorige token direct verlopen; `SELECT … FOR UPDATE` tegen dubbel gebruik |
| Geen accountonthulling | Zelfde antwoord voor bekend en onbekend; de mail wordt pas na het antwoord verstuurd (`fastcgi_finish_request`), zodat de antwoordtijd niets verraadt |
| Uitgesloten accounts | Uitgeschakeld, verwijderd of met een OAuth-placeholder-adres (`*.invalid`) krijgen geen mail |
| Sessies | Na een geslaagde reset verliest elke sessie die ouder is dan de reset zijn geldigheid (`PasswordResetService::sessionRevoked()`, gecontroleerd in `AuthManager`). Kan dat niet (tabel nog niet gemigreerd), dan blijft de site gewoon werken |
| API-tokens (JWT) | Stateless; bestaande tokens blijven tot hun eigen verloop geldig (standaard 1 uur) |
| Token in de URL | Herstelpagina's sturen `Referrer-Policy: no-referrer` en `Cache-Control: no-store` |
| Wachtwoord | min. 8, max. 1024 tekens, argon2id met dezelfde parameters als registratie; een geweigerd wachtwoord verbruikt het token niet |
| CSRF | Op beide POST-routes (`CsrfProtection::validateRequest()`) |

## Tests
- `tests/Unit/Core/Auth/PasswordResetRulesTest.php`: wachtwoordregels, tokenvorm.
- `tests/Integration/PasswordResetServiceTest.php`: tegen een echte MariaDB (zet `CF_TEST_DB_NAME`, `CF_TEST_DB_USER`, `CF_TEST_DB_PASS`; zonder die variabelen worden ze overgeslagen). CI start hiervoor een MariaDB-service.
