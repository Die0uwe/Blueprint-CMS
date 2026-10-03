# Beveiligingsnotities — Blueprint AI Studio

Per regel uit de opdracht: hoe is hij ingevuld, en waar is bewust de veilige i.p.v. de makkelijke
keuze gemaakt. Alles hieronder is door tests gedekt (`modules/ai-studio/tests`), tenzij anders vermeld.

## 1. Harde regels

| Regel | Invulling |
|---|---|
| Geen externe JS-libs, geen nieuwe composer-pakketten | Alleen eigen `studio.js`, `editor.js`, `sse.js`. Alleen `composer.json`/`autoload_psr4.php` kregen een PSR-4-regel. |
| Geen `eval`/`create_function`/`assert(string)`/`preg_replace /e` | Niet aanwezig in module, sanitizer of CLI-command. |
| Geen iframes naar externe AI-sites | CSP `frame-src 'none'`, `frame-ancestors 'none'`, `default-src 'none'`, `connect-src 'self'`. Alle providercalls lopen server-side. |
| Output-escaping via `Core\Security\ContentSanitizer` | Bestond niet; nieuw gebouwd op `DOMDocument` met whitelist (geen HTMLPurifier). Het bouwt een NIEUW document op uit toegestane knopen (anti-mutation-XSS). Elke view-uitvoer gaat door `ContentSanitizer::escape()`. |
| AI-output nooit direct in de editor | Server stuurt alleen een unified diff (`proposal`-event). Pas na klik op "Toepassen" roept de client `apply-diff` aan; de server past de diff strikt toe op de editorinhoud en geeft het resultaat terug. |
| AI-output nooit als HTML in chat | `studio.js` gebruikt uitsluitend `textContent`/`createElement`; code komt in `<pre><code>`. Test: `testMaliciousReplyIsJustTextInEvents`. |
| API-keys versleuteld | `Core\Security\Crypto` (AES-256-GCM), `cf_settings` groep `aistudio`, key `provider.{slug}.api_key`, type `encrypted`. Nooit in de view, JSON, logs of audit (`SecretRedactor` + tests die de key overal zoeken). |
| CSRF + PermissionMiddleware + RateLimitMiddleware op elke POST | Zie `routes.php` en `RoutesTest`. CSRF zit in de controllers (`CsrfGuard`), de rest als route-middleware. |
| Idempotente migratie | `001_create_tables.sql`: `CREATE TABLE IF NOT EXISTS` + `ALTER` alleen na `INFORMATION_SCHEMA`-check (PREPARE/EXECUTE). Handmatig op echte MariaDB twee keer gedraaid, en gerepareerd vanaf een tabel zonder voorstelkolommen. |
| SSRF Ollama | `SsrfGuard`: alleen http(s), geen credentials in de URL, DNS wordt opgelost en het IP wordt vastgepind (`CURLOPT_RESOLVE`, tegen DNS-rebinding), 169.254.0.0/16 (cloud-metadata) en link-local IPv6 geblokkeerd, geen redirects. |

## 2. Afwijkingen en afwegingen (veilig boven makkelijk)

1. **CSRF-token voor SSE in een header i.p.v. een query-parameter.** `EventSource` kan geen POST of
   headers sturen, en een token in de URL komt in access-logs, referrers en browsergeschiedenis terecht.
   Daarom: `fetch()` + `ReadableStream` (`sse.js`, met `EventSource`-achtige API) met `X-CSRF-Token`.
   `CsrfGuard` weigert een token in de query-string zelfs als hij klopt.
2. **Eigen `SettingsStore` naast `SettingsRepository`.** `SettingsRepository` cachet ontsleutelde geheimen
   een uur lang in `storage/cache` (bestaande zwakte in de core, buiten deze PR). De module gebruikt die
   daarom niet voor API-keys. Aanbeveling: de cache in de core apart oppakken.
3. **Ollama mag naar loopback/private IP's.** Een lokale Ollama draait op `localhost`/LAN; blokkeren zou de
   functie breken. Daarom is alleen metadata/link-local verboden, de rest is beheerderskeuze (de URL komt uit
   de bestaande Ollama-module-instellingen, die alleen een beheerder kan wijzigen). Cloud-providers zijn
   vaste HTTPS-hosts.
4. **Keys gelden site-breed**, niet per gebruiker. Wie `aistudio.use` heeft, verbruikt dus de keys van de
   site. De permission wordt standaard alleen aan de rol `admin` gegeven; `aistudio.admin` beheert de keys.
5. **Diff strikt, zonder fuzz.** `DiffService::apply` weigert bij elke afwijking. Het voorstel is gekoppeld
   aan een sha256 van de editorinhoud waarop het gebaseerd was; wijzigt de gebruiker intussen de editor,
   dan volgt HTTP 409 en gebeurt er niets. Maximaal 512 KB / 20.000 regels; bij enorm veel verschillen valt de
   diff terug op "vervang alles" in plaats van onbegrensd rekenen.
6. **Per-gebruiker limiet bovenop `RateLimitMiddleware`.** De bestaande middleware is 60 req/min per IP
   en wordt gedeeld met de rest van de site. Chat: 20/min per gebruiker, apply-diff: 30/min, instellingen:
   10/min.
7. **Prompt-injectie.** Editorinhoud gaat in een omheining met een willekeurige grens per request en wordt
   expliciet als data gemarkeerd. Dit vermindert, maar elimineert niet: daarom is de menselijke bevestiging
   (diff + "Toepassen") de echte beveiliging, niet de prompt.
8. **Key-validatie bij opslaan.** Een nieuwe key wordt eerst live gecontroleerd; bij afwijzing wordt hij niet
   opgeslagen. Foutmeldingen van providers gaan door `SecretRedactor` voordat ze worden getoond of gelogd.
9. **Request wordt beëindigd na de stream** (injecteerbare `terminate`), omdat `Response::send` headers na de
   body zet en dat bij SSE zou breken.
10. **Extra routes** (alleen lezen): `GET /conversation/{id}` (eigenaar-gecontroleerd) en `GET /assets/{file}`
    (vaste whitelist van vier bestanden, geen padinvoer).
11. **Extra klassen** `AiStudioModule` en `routes.php`: nodig om de module via `module.json` te laden en
    routes declaratief (en testbaar) te houden.
12. **Instellingenschema heet `settings_schema`**, niet `settings`, om niet door de generieke
    instellingenpagina van de core te worden opgepakt (die zou geheimen anders tonen).
13. **Geen DROP bij uninstall.** Gespreksdata blijft bewaard; verwijderen is een bewuste handmatige actie.
14. **Audit-log** bevat alleen id's, aantallen en provider-slug + actie; nooit inhoud van gesprekken of keys.

## 3. Bekende beperkingen

- Geen link in de admin-sidebar (gedeelde partial niet aangeraakt); bereikbaar via `/admin/ai-studio`.
- `vendor/composer/autoload_static.php` bestaat in deze repo niet; alleen `autoload_psr4.php` is bijgewerkt.
- De 6 providers zijn alleen getest tegen een nep-transport; er is geen echte API-call gedaan.
- Browsergedrag van `editor.js`/`studio.js` is niet in een echte browser getest; alleen de tokenizer is met
  Node 22 gecontroleerd.
- PHPStan (level 8) draait op `modules/ai-studio/src` en `ContentSanitizer`; de tests zijn niet geanalyseerd
  omdat PHPUnit niet in `vendor/` zit.
