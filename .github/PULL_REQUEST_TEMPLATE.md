## Wat doet deze PR?
Korte beschrijving van de wijziging en het probleem dat het oplost.

## Type wijziging
- [ ] Bugfix
- [ ] Nieuwe feature/module
- [ ] Documentatie
- [ ] Refactor (geen functionele wijziging)
- [ ] Security fix (zie ook [SECURITY.md](../SECURITY.md) — een kritieke kwetsbaarheid hoort
      idealiter eerst privé gemeld te zijn, niet direct als publieke PR)

## Hoe is dit getest?
Beschrijf hoe je hebt gecontroleerd dat de wijziging werkt (bijv. een curl-sessie tegen een
lokale server, een testscenario, screenshots). Dit project hecht sterk aan écht live-testen
tegen een draaiende PHP + MariaDB-omgeving, niet alleen "de code ziet er goed uit" — zie de
CHANGELOG voor het gebruikelijke niveau van detail.

## Checklist
- [ ] `composer test`, `composer stan` en `composer cs` slagen lokaal
- [ ] CHANGELOG.md-entry toegevoegd (nieuwe versie boven de vorige, met aanleiding/wat/waarom)
- [ ] README.md bijgewerkt indien relevant (roadmap-tabel, "Bekende beperkingen", versie-badge)
- [ ] Geen secrets/wachtwoorden/API-keys in de diff

## Gerelateerde issues
Fixes #
