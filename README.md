<!--
============================================================================
Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
GPL-3.0-or-later
============================================================================
-->

<div align="center">

# 🔮 Blueprint CMS

**Modulair PHP 8.3+ Community CMS voor gaming, streamers & gilden**

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php)](https://php.net)
[![MariaDB](https://img.shields.io/badge/MariaDB-10.11%2B-003545?style=flat-square&logo=mariadb)](https://mariadb.org)
[![License](https://img.shields.io/badge/License-GPL--3.0-blue?style=flat-square)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.9.0-brightgreen?style=flat-square)](CHANGELOG.md)
[![CI](https://img.shields.io/github/actions/workflow/status/Die0uwe/bluprint-cms/ci.yml?branch=main&style=flat-square&label=CI)](.github/workflows/ci.yml)

*Geïnspireerd door PHP-Fusion · Down Under Fusion · ImpressCMS*

[📊 Analyse Rapport](docs/ANALYSE.md) · [🔍 Wave 0/1 Gap-analyse](docs/wave-0-gap-analysis.md) · [🖼️ Frontpage Mockup](docs/MOCKUP-FRONTPAGE.html) · [🏗️ Architectuur](docs/assets/architecture.md)

</div>

---

## ✨ Wat is Blueprint CMS?

Blueprint CMS is een open-source, **modulair PHP-framework** speciaal gebouwd voor:

- 🎮 **Gaming communities** — guild management, server status, roster, raid progress
- 📺 **Streamers** — Discord / Twitch / YouTube / Kick integratie out-of-the-box
- ⚔️ **WoW Gilden** — Blizzard API v2 + Raider.IO integratie, guild roster, character armory, teams
- 🤖 **AI-powered** — Gratis lokale AI via Ollama (llama3.2, mistral, gemma2) + Open WebUI
- 🏢 **Verenigingen & bedrijven** — volledige website binnen 5 minuten

---

## 🏗️ Architectuur

```
public/               ← Enige publieke map (document root)
│  index.php          ← Front controller — enige entry point
│
src/Core/             ← Framework kernel
│  Application.php    ← Bootstrap + DI Container + module loader
│  Container.php      ← PSR-11 Dependency Injection
│  Router.php         ← URL routing + middleware pipeline
│  Request.php / Response.php
│  Hook/              ← Action/filter systeem
│  Cache/             ← PSR-16 (File + Redis)
│  Database/          ← PDO wrapper + Fluent QueryBuilder
│  Auth/              ← Login + JWT + RBAC
│  Block/             ← BlockRegistry + zone rendering
│  Queue/             ← Database job queue
│  Marketplace/       ← Package manager (download, extract, deploy)
│  Security/          ← CSRF + rate limiting
│  Template/          ← Twig 3.x ThemeManager
│
src/Modules/          ← Core modules
src/Api/              ← REST API v1 + middleware
src/Blocks/Types/     ← 6 core block types
modules/              ← 7 community/gaming modules
themes/               ← Twig dark gaming thema
```

---

## ⚡ Kernprincipes

| Principe | Implementatie |
|---|---|
| **Modulair** | Elke functionaliteit is een losstaande Module + BlockRegistry |
| **Drag & Drop** | Blokken positie-instelbaar via admin UI (Ajax, geen reload) |
| **Gaming/Streamer** | Discord, Twitch, WoW, Guild, Minecraft, FiveM ingebouwd |
| **AI-first** | Ollama AI chat + Open WebUI + guild analyse + nieuws samenvatting |
| **API-first** | REST API v1 met OAuth2 + JWT + CORS + rate limiting |
| **Secure by default** | RBAC, CSRF, prepared statements, argon2id, AES-256-GCM |
| **PSR-compliant** | PSR-4, PSR-7, PSR-11, PSR-14, PSR-16 |

---

## 🔧 Vereisten

| Component | Minimum |
|---|---|
| PHP | 8.3+ |
| MariaDB / MySQL | 10.11+ / 8.0+ |
| Extensions | PDO, pdo_mysql, GD, cURL, mbstring, openssl, json, zip |
| Composer | 2.x |

---

## 🚀 Installatie

```bash
git clone https://github.com/Die0uwe/Bluprint-CMS.git
cd Bluprint-CMS
composer install --optimize-autoloader
cp .env.example .env
# Navigeer naar http://jouwsite.nl/installer/
```

---

## 🧩 Modules (7 beschikbaar)

| Module | Blocks | Highlights |
|---|---|---|
| Discord | 2 | OAuth login/registratie, rollen sync, widget, online leden |
| Twitch | 2 | Live status, stream embed, Helix API |
| World of Warcraft | 3 | Guild roster, Mythic+/raid progress, character profiel — via `BlizzardApiClient` + Raider.IO |
| Guild Management | 3 | Aanmeldingen, leden, teams, rangen, admin |
| Minecraft | 1 | Server status via mcsrvstat.us |
| FiveM | 1 | Server status via FXServer endpoint |
| Ollama AI | 2 | Chat widget, assistent, guild analyse, nieuws samenvatting |

Het WoW-module bevat drie native blocks (`WowGuildRosterBlock`, `WowMythicProgressBlock`,
`WowCharacterBlock`) die via `src/Core/Auth`-stijl PDO/Connection-code werken — geen externe
CMS-functies. Een eerdere v1.8.0-changelog-entry claimde een "Guild Roster v2 / Character Armory
v2" uitbreiding; die bestanden bleken 1-op-1 gekopieerde WordPress-code (`$wpdb`, `WP_Error`,
shortcodes) uit de Slayer Alliance Master Suite-plugin, werden nergens door dit framework geladen,
en zijn in v1.9.0 verwijderd. Zie `docs/wave-0-gap-analysis.md` voor de volledige audit.

### Core content-modules (`src/Modules/`, altijd actief)

| Module | Sinds | Highlights |
|---|---|---|
| Users | v1.0.0 | Login/registratie, profiel + avatar-upload, Discord/Twitch OAuth (ook voor nieuwe bezoekers) |
| News | v1.0.0 | Artikelen, categorieën (lezen; admin-CRUD ontbreekt nog, zie Bekende beperkingen) |
| Pages | v1.0.0 | Statische CMS-pagina's + menu |
| Forum | v1.9.0 | Borden (gedeelde `cf_categories`), topics, reacties, `forum.post`/`forum.moderate` RBAC |
| Blog | v1.9.0 | Eén blog per lid (`/blog/{username}/{slug}`), draft/published, `blog.moderate` voor moderatie |
| Downloads | v1.9.0 | Bestandsbeheer via `UploadManager::forDownloads()` (zip/pdf/rar/7z/gz), `downloads.manage` |
| Contact | v1.9.0 | Publiek formulier + CSRF + honeypot, admin-inbox — **verstuurt geen e-mail** (geen Mailer-klasse) |

---

## 🗺️ Roadmap

| Sprint | Status | Inhoud |
|---|---|---|
| **S1** | ✅ v1.0.0 | Core Foundation — DI, Router, Auth, Cache, Queue, RBAC |
| **S2** | ✅ v1.1.0 | Web-installer + Admin Dashboard + Frontend |
| **S3** | ✅ v1.2.0 | Block API + Drag & Drop layout |
| **S4** | ✅ v1.3.0 | Discord OAuth + Twitch integratie |
| **S5** | ✅ v1.4.0 | Guild Management + WoW + Minecraft + FiveM |
| **S6** | ✅ v1.5.0 | REST API v1 compleet + Ollama AI + Open WebUI |
| **S7** | ✅ v1.6.0 | Marketplace (install, upload, update, toggle) |
| **S8** | ✅ v1.7.0 | Debug & fixes — 15 bugs opgelost, composer PSR-4 |
| **S9** | ⚠️ v1.8.0 | ~~WoW Module v2 — Guild Roster + Character Armory~~ — **ingetrokken in v1.9.0**: bleek WordPress-code, nooit geladen. Zie CHANGELOG v1.9.0. |
| **S9-audit** | ✅ v1.9.0 | Wave 0 gap-analyse tegen de echte repo, WP-code verwijderd, CI/tests toegevoegd, JWT/APP_KEY gescheiden, Discord-registratie, upload-handler, Forum/Blog/Downloads/Contact core-modules, installer Step5 dynamisch |
| **S10** | 📋 Gepland | YouTube + Kick integratie |
| **S11** | 📋 Gepland | Media-galerij (los van de generieke upload-handler) |
| **S12** | 📋 Gepland | Premium ecosysteem + licenties + betalingen |
| **S13** | 📋 Gepland | Multi-language / i18n volledige implementatie |

---

## 🔐 Security

- PDO prepared statements overal — geen raw SQL string-concatenatie
- XSS escaping (`htmlspecialchars`) op alle output
- CSRF-token validatie op alle POST requests
- Argon2id wachtwoord hashing
- AES-256-GCM OAuth token encryptie, met een **eigen `JWT_SECRET`** los van `APP_KEY` (sinds v1.9.0)
- Rate limiting (60 req/min per IP) op de REST API
- JWT authenticatie (HS256 + exp validatie)

> Het eerdere "98/100"-cijfer in `docs/ANALYSE.md` was een zelfgerapporteerde regex-telling,
> nooit gedraaid tegen echte tests. Sinds v1.9.0 draait CI (`.github/workflows/ci.yml`) PHPUnit,
> PHPStan level 8 en PHPCS PSR-12 op elke push — zie de CI-badge bovenaan voor de actuele status.

---

## ⚠️ Bekende beperkingen (stand v1.9.0)

Eerlijk overzicht van wat deze Wave 1-doorloop wél en niet heeft opgelost — zie
`docs/wave-0-gap-analysis.md` en `CHANGELOG.md` voor de volledige context per punt.

- **`/admin`-routes zijn niet permissie-gated.** `AuthMiddleware` controleert alleen "is
  ingelogd", niet welke rol of permissie. Ondanks dat RBAC nu wél echt geseed is (zie
  CHANGELOG), roept geen enkele admin-controller `auth()->authorize(...)` aan — elk ingelogd
  lid kan vandaag bij `/admin`, `/admin/blocks`, `/admin/marketplace`, enz.
- **De admin-sidebar bevat nog dode links.** `/admin/news`, `/admin/pages`, `/admin/media`,
  `/admin/users`, `/admin/roles`, `/admin/themes`, `/admin/menus`, `/admin/modules`,
  `/admin/logs` staan in `dashboard.php` maar hebben geen route/controller. News en Pages
  hebben zelfs géén admin-CRUD UI, ondanks dat `NewsRepository::create()` al bestaat.
- **Contact verstuurt geen e-mail.** Er is geen `Mailer`-klasse in de codebase, ondanks een
  volledige SMTP-configuratiesectie in `config/config.php`. Berichten worden alleen opgeslagen.
- **`migrate`/`module:install` CLI-commando's ontbreken.** Stonden al sinds v1.0.0 in de
  help-tekst; sinds v1.9.0 geeft `console.php` een duidelijke melding i.p.v. een fatal error.
- **Module-specifieke extra tabellen** (bv. `cf_discord_role_mapping`) worden niet direct
  aangemaakt wanneer je een module in installer-stap 5 selecteert — de installer laadt bewust
  geen framework-klassen. Ze ontstaan zodra een beheerder de module later via
  `/admin/marketplace` (opnieuw) activeert.
- **Geen `composer.lock`.** `packagist.org` was niet bereikbaar in de sandbox waarin dit werk
  is gedaan — PHPUnit/PHPStan/PHPCS zijn dus nooit lokaal gedraaid. Verificatie liep via
  `php -l` op elk bestand plus handmatige smoke-test scripts tegen de echte klassen
  (`JWTManager`, `HookManager`, `CsrfProtection`, `UploadManager`). CI draait dit wél echt
  (zie hierboven) zodra de eerste `composer install` het lockfile committed.

---

## 🖥️ CLI Tools

```bash
php cli/console.php queue:work     # Queue worker starten
php cli/console.php cache:clear    # Cache wissen
```

> `migrate` en `module:install` staan al sinds v1.0.0 in de `console.php`-help, maar
> `MigrateCommand`/`ModuleInstallCommand` zijn nooit gebouwd (`cli/commands/` bevat alleen
> `QueueWorkerCommand` en `CacheClearCommand`) — sinds v1.9.0 geeft `console.php` hiervoor een
> duidelijke melding i.p.v. een kale fatal error. Schema importeren kan ondertussen via
> `mysql <db> < src/Core/Database/schema.sql` (of de installer); modules activeren via
> `/admin/marketplace` of installer-stap 5.

---

## 📄 Licentie

GPL-3.0-or-later — © 2026 [DieOuwe](https://www.dieouwe.nl) / [Slayer Alliance](https://www.slayeralliance.com)

<div align="center">

🌐 [www.dieouwe.nl](https://www.dieouwe.nl) &nbsp;·&nbsp;
⚔️ [www.slayeralliance.com](https://www.slayeralliance.com) &nbsp;·&nbsp;
📦 [CurseForge](https://curseforge.com/members/dieouwe/projects) &nbsp;·&nbsp;
💬 [Discord](https://discord.gg/y8Pu5qsEbQ)

</div>

<!--
╔══════════════════════════════════════════════════════════════════════╗
║  File: README.md | Role: Docs | Version: 1.9.0                       ║
║  Updated: 2026-09-28 — Wave 1: Forum/Blog/Downloads/Contact, RBAC-   ║
║           seeding, Twig-globals, installer Step5 dynamisch           ║
╚══════════════════════════════════════════════════════════════════════╝
-->
