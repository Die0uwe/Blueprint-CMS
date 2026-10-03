# Inloggen met GitHub, Google, Discord (en Twitch, Battle.net)

Bezoekers kunnen inloggen of zich registreren met een extern account. De knoppen staan op `/login`, `/register` en in het login-blok; leden koppelen of ontkoppelen een provider op `/profiel`.

Een knop verschijnt pas als **beide** waar zijn:

1. de module van de provider staat aan, en
2. Client ID **en** Client Secret zijn ingevuld.

## Instellen (per provider, eenmalig)

Zet eerst `app.url` in `config/config.php` op het echte adres van je site (`https://jouwsite.nl`, zonder slash). De callback-URL wordt daaruit opgebouwd en moet **letterlijk** overeenkomen met wat je bij de provider invult.

| Provider | Waar | Callback-URL |
|---|---|---|
| GitHub | github.com/settings/developers, OAuth Apps, New OAuth App | `https://jouwsite.nl/auth/github/callback` |
| Google | console.cloud.google.com/apis/credentials, OAuth client ID, Web application | `https://jouwsite.nl/auth/google/callback` |
| Discord | discord.com/developers/applications, OAuth2, Redirects | `https://jouwsite.nl/auth/discord/callback` |
| Twitch | dev.twitch.tv/console/apps | `https://jouwsite.nl/auth/twitch/callback` |
| Battle.net | develop.battle.net/access/clients | `https://jouwsite.nl/auth/battlenet/callback` |

Daarna in de admin: **Marketplace, Providers & API-instellingen**, kies de provider, vul Client ID en Client Secret in en sla op. Het opslaan schakelt de module meteen in (het scherm meldt dat). Het secret wordt versleuteld opgeslagen. Elk scherm toont zijn eigen uitleg en de exacte callback-URL.

Google: stel het OAuth-consentscherm in. Zolang de app op "Testen" staat, kunnen alleen accounts die je als testgebruiker toevoegt inloggen.

Zonder admin-scherm (bijvoorbeeld bij een eerste installatie): kies de provider in de installer bij "Modules" en vul de sleutels daarna in via dezelfde pagina.

## Hoe een login verloopt

- `/auth/{provider}/login` begint een login of registratie. Is de bezoeker al ingelogd, dan wordt het koppelen.
- `/auth/{provider}` koppelt de provider aan het ingelogde account (knop op het profiel).
- De provider stuurt terug naar `/auth/{provider}/callback`. Daar worden state, code, token en profiel gecontroleerd.
- Is de identiteit al gekoppeld, dan logt het systeem dat lid in. Anders ontstaat een nieuw account met de standaardrol (`member`). De gebruikersnaam komt van de provider en wordt zo nodig uniek gemaakt.
- Een `?redirect=/pad` wordt na het inloggen gevolgd, maar alleen voor paden op deze site.

## Beveiligingskeuzes

- **Sessiecookie `SameSite=Lax`.** Met `Strict` stuurt de browser het cookie niet mee bij de terugkeer van de provider, waardoor de state verloren gaat en elke login faalt. `Lax` stuurt het wel mee bij een GET-navigatie van een andere site, nooit bij cross-site POST's.
- **State** is willekeurig, 32 tekens, eenmalig en wordt ook bij een mislukte poging gewist. Een lege state wordt altijd geweigerd.
- **Geen automatische koppeling op e-mailadres.** Registratie controleert e-mailadressen niet: iemand kan het adres van een ander met een eigen wachtwoord hebben aangemaakt. Zou een Google-login dat account overnemen, dan had die persoon toegang tot het account van het slachtoffer. Is het adres al in gebruik, dan krijgt de nieuwe OAuth-gebruiker een eigen account met een placeholder-adres (`…@users.noreply.invalid`). Bestaande leden koppelen een provider bewust via hun profiel.
- **GitHub:** alleen het primaire, door GitHub bevestigde adres uit `/user/emails` wordt gebruikt, nooit het publieke profieladres.
- **Geblokkeerde accounts** (`is_active = 0` of verwijderd) kunnen niet inloggen en krijgen ook geen nieuw account.
- **Eén account per provider-identiteit.** Een provider-account dat al aan een ander lid hangt, wordt niet overgenomen (`already_linked`). Een lid kan per provider één account koppelen (`other_linked`).
- **Ontkoppelen** vraagt een CSRF-token en wordt geweigerd als het de enige inlogmethode is van een account zonder echt e-mailadres (`last_method`). Met een echt adres is "Wachtwoord vergeten" altijd het vangnet.
- **Tokens** (access en refresh) staan versleuteld (AES-256-GCM) in `cf_user_oauth`.
- Foutcodes in de URL komen uit een vaste lijst; tekst uit de URL wordt nooit getoond.

## Foutmeldingen en wat ze betekenen

| Code | Betekenis | Meestal oplossen met |
|---|---|---|
| `cancelled` | Gebruiker annuleerde of de provider gaf een fout terug | opnieuw proberen |
| `state` | Sessie verlopen of callback niet door ons gestart | opnieuw beginnen; cookies toestaan |
| `failed` | Token of profiel ophalen mislukte | callback-URL, Client ID/Secret, serverlog (`error_log`) controleren |
| `disabled` | Het gekoppelde account is gedeactiveerd | account in `/admin/users` activeren |
| `not_configured` | Client ID of Secret ontbreekt | admin, Providers & API-instellingen |
| `not_enabled` | Module staat uit | sleutels opslaan schakelt de module in |
| `already_linked` | Dit provider-account hangt aan een ander lid | eerst bij dat lid ontkoppelen |
| `other_linked` | Het lid heeft al een account van deze provider | eerst ontkoppelen |
| `last_method` | Dit is de enige manier om in te loggen | eerst een andere provider koppelen |

De server logt de technische reden (HTTP-status en begin van het antwoord van de provider) met `error_log`, nooit het secret.

## Problemen zoeken

- **"redirect_uri_mismatch" bij de provider:** de callback-URL bij de provider is niet identiek aan `app.url` + `/auth/{provider}/callback` (let op `http` of `https`, `www`, een slash aan het eind).
- **Na terugkeer weer op de loginpagina met "verlopen":** de sessiecookie komt niet terug. Controleer dat de site op één domein draait en dat `app.url` dat domein is.
- **Knop ontbreekt:** module uit, of Client ID of Secret leeg. De pagina Providers & API-instellingen toont per provider "aan" of "uit".
- **Nieuwe module (GitHub) wordt niet gevonden na een FTP-upload:** modules registreren sinds nu hun eigen autoloader; een `composer dump-autoload` is niet nodig.

## Voor ontwikkelaars

- `Core\Auth\OAuth\OAuthLoginFlow` bevat alle beslissingen; een provider-controller is een dunne laag (`begin`, `complete`, `disconnect`) met een functie die het ruwe profiel naar `id` en `profile` omzet.
- Een nieuwe provider: `modules/{slug}/` met `module.json` (incl. `settings`), een `OAuthClient`-subklasse, een controller en een module-klasse die de vier routes registreert; voeg de slug toe aan `OAuthProviders::CATALOG`.
- Testnaad: met `APP_ENV=testing` en `OAUTH_MOCK_BASE=http://127.0.0.1:9100` gaan de server-naar-server-aanroepen naar een lokale nep-provider (`https://host/pad` wordt `{BASE}/host/pad`). Op productie heeft dit nooit effect.
