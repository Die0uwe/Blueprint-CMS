<!--
============================================================================
Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
============================================================================
-->

# Bijdragen aan Blueprint CMS

Fijn dat je wil bijdragen! Dit document beschrijft hoe dat het soepelst gaat.

## Voordat je begint

- Lees [README.md](README.md) voor een overzicht van de architectuur, kernprincipes en de
  huidige stand van zaken (roadmap-tabel + "Bekende beperkingen").
- Lees [CHANGELOG.md](CHANGELOG.md) — met name de recentste entries — voor de laatst opgeloste
  en nog openstaande punten. Veel bugs/keuzes zijn daar met opzet uitgebreid beargumenteerd.
- Voor een kwetsbaarheid: zie [SECURITY.md](SECURITY.md) — **niet** via een publieke issue.

## Ontwikkelomgeving

- PHP 8.3+, MariaDB 10.11+.
- `composer install` (composer.json bevat alleen aantoonbaar-gebruikte dependencies — zie
  CHANGELOG v1.25.4 voor waarom dat expliciet zo gehouden wordt).
- `composer test` (PHPUnit), `composer stan` (PHPStan level 8, `src/`), `composer cs` (PHPCS
  PSR-12, `src/`) — alle drie draaien ook in CI (`.github/workflows/ci.yml`) op elke push/PR.
- Voor een lokale database: importeer `src/Core/Database/schema.sql`, daarna elke
  `modules/*/install.sql` die bestaat (niet elke module heeft er een — sommige maken hun eigen
  tabellen aan in `install()`).

## Code-stijl

- PSR-4 autoloading, PSR-12 codestijl (zie `composer cs`).
- `declare(strict_types=1);` in elk PHP-bestand.
- PDO **uitsluitend** via prepared statements — nooit string-concatenatie in SQL.
- Alle output die naar HTML gaat: `htmlspecialchars()` (of Twig's auto-escaping in thema's).
- Nieuwe modules implementeren `CommunityFusion\Core\Module\ModuleInterface` volledig — zie
  `src/Modules/*/` voor voorbeelden en `modules/*/module.json` voor het manifest-formaat.
- Commit-/PR-teksten mogen in het Nederlands (de bestaande CHANGELOG/commit-geschiedenis is dat
  ook), maar code-commentaar dat een niet-vanzelfsprekende beslissing toelicht (waarom, niet
  wat) is zeer welkom — dit project documenteert bewust "waarom is dit zo" naast de code zelf.

## Pull requests

1. Fork + branch vanaf `main`.
2. Eén logische wijziging per PR — makkelijker te reviewen, makkelijker te reverten.
3. Voeg een `CHANGELOG.md`-entry toe onder een nieuwe patch/minor-versie (volg het bestaande
   formaat: aanleiding, wat, waarom, en — waar relevant — hoe het live getest is).
4. Zorg dat `composer test`, `composer stan` en `composer cs` lokaal slagen voordat je een PR
   opent — CI draait dezelfde checks.
5. Beschrijf in de PR wat het probleem was en hoe je het hebt getest (een curl-sessie, een
   screenshot, een testscenario — wat maar aantoont dat het werkt).

## Modules & thema's voor de Marketplace

Wil je een module of thema bouwen los van deze repo (voor de toekomstige Marketplace)? Zie de
Module/Block API-secties in de project-documentatie voor de interface-contracten
(`ModuleInterface`, `BlockInterface`) en het `module.json`/`theme.json`-manifestformaat. Die
API's zijn bewust stabiel gehouden zodat externe modules niet bij elke core-release breken.

## Gedragscode

Zie [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).
