<!--
============================================================================
Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
============================================================================
-->

# Security Policy — Blueprint CMS

## Ondersteunde versies

Blueprint CMS is nog pre-1.0 in de zin van "stabiele publieke API" — er is op dit moment
maar één ondersteunde lijn: de laatste `main`-branch / laatste tag in [CHANGELOG.md](CHANGELOG.md).
Er wordt (nog) geen aparte LTS- of patch-branch onderhouden.

| Versie | Ondersteund |
|---|---|
| Laatste tag op `main` | ✅ |
| Alles daarvoor | ❌ |

## Een kwetsbaarheid melden

**Meld een kwetsbaarheid niet via een openbare GitHub Issue.** Openbare issues zijn voor bugs
en features die geen directe risico's opleveren voor draaiende installaties; een
beveiligingslek in het openbaar posten geeft aanvallers een voorsprong op beheerders die nog
niet hebben kunnen updaten.

Meld een kwetsbaarheid in plaats daarvan via een van deze kanalen:

- **GitHub Security Advisories** (aanbevolen): via het "Security" tabblad van deze repository →
  "Report a vulnerability". Dit is privé tussen jou en de maintainer(s) totdat er een fix is.
- **Discord**: een direct bericht naar de maintainer op [discord.gg/y8Pu5qsEbQ](https://discord.gg/y8Pu5qsEbQ).
- **E-mail**: via de contactroute op [www.dieouwe.nl](https://www.dieouwe.nl).

Geef in je melding waar mogelijk aan:

- Welke versie/commit je hebt getest.
- Reproductiestappen (of een PoC) — concreet genoeg om het probleem te bevestigen, zonder
  onnodig een compleet exploit-recept te publiceren.
- De ingeschatte impact (bijv. privilege-escalatie, data-lek, RCE, XSS, CSRF).

## Wat je kan verwachten

- Een bevestiging van ontvangst binnen redelijke termijn.
- Een beoordeling van impact en, indien bevestigd, een fix — bij een kritieke kwetsbaarheid
  (bijv. privilege-escalatie, auth-bypass, RCE) wordt die met voorrang behandeld en als eigen
  CHANGELOG-entry gedocumenteerd zodra de fix live staat, in lijn met hoe eerdere
  beveiligingsfixes in dit project zijn afgehandeld (zie bijv. CHANGELOG v1.10.0, v1.25.0,
  v1.25.5).
- Eventuele credit in de CHANGELOG-entry, als je dat op prijs stelt — laat dat in je melding weten.

## Bekende architectuurkeuzes (geen kwetsbaarheden, wel relevant)

- Wachtwoorden: Argon2id (`password_hash()`).
- OAuth-tokens: AES-256-GCM versleuteld opgeslagen, met een eigen `JWT_SECRET` los van `APP_KEY`.
- SQL: uitsluitend PDO prepared statements.
- CSRF: token-validatie op alle POST/PUT/DELETE-requests.
- RBAC: rolgebaseerde rechten met een expliciete "je kan geen rol/rechten toekennen boven je
  eigen niveau"-regel (zie CHANGELOG v1.25.0 en v1.25.5 voor de geschiedenis van deze regel).

Zie de sectie "🔐 Security" in [README.md](README.md) voor het volledige overzicht.
