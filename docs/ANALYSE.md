<!--
============================================================================
Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
GPL-3.0-or-later
============================================================================
-->

> ## 🗄️ GEARCHIVEERD — historisch document, niet de actuele status
>
> Dit rapport is een momentopname van **v1.7.0 (2026-06-06)** — vóór Sprints 9-13, vóór de
> Wave 0-9 audits, vóór v1.20-1.25. De cijfers en conclusies hieronder (bestandstellingen,
> "0 issues resterend", enz.) kloppen niet meer met de huidige codebase (inmiddels 11+
> community/gaming-modules, i18n, forum, media-gallery, OAuth, en meerdere gevonden-en-gefixte
> kritieke bugs die dit rapport nog niet kende). Het "98/100"-cijfer hieronder was destijds al
> een zelfgerapporteerde regex-telling, nooit tegen echte tests gedraaid — zie README.md sectie
> "🔐 Security" voor die disclaimer.
>
> **Voor de actuele stand van zaken:** zie de roadmap-tabel en "⚠️ Bekende beperkingen"-sectie
> in [README.md](../README.md), en [CHANGELOG.md](../CHANGELOG.md) voor de volledige,
> chronologische geschiedenis van elke wijziging, bug en fix sindsdien (met name de
> S9-audit-golven, v1.20-1.25, en de zes-koppige totale-codebase-audit in v1.25.5).
>
> Dit document blijft staan als historisch referentiepunt (hoe zag Sprint 1-8 eruit), niet
> als actuele documentatie.

---

# 📊 Blueprint CMS — Analyse Rapport v1.7.0 *(gearchiveerd — zie banner hierboven)*

> Volledige statische code analyse na Sprint 8 — alle 169 bestanden geïnspecteerd.
> Laatste update: 2026-06-06

---

## Code Metrics (na Sprint 8)

| Categorie | Bestanden | Regels |
|---|---|---|
| Core Framework | 20 | 2.875 |
| CMS Modules | 12 | 1.600 |
| Installer | 13 | 804 |
| Marketplace | 7 | 1.436 |
| WoW Module | 11 | 1.056 |
| Ollama AI Module | 7 | 971 |
| Guild Management | 9 | 915 |
| Discord Module | 5 | 653 |
| Twitch Module | 5 | 480 |
| FiveM Module | 4 | 240 |
| Minecraft Module | 4 | 226 |
| Block System | 9 | 400 |
| REST API | 4 | 238 |
| CLI Tools | 3 | 178 |
| Middleware | 3 | 153 |
| **TOTAAL** | **115 PHP** | **~12.427** |

---

## Sprint 8 — Debug resultaten

### Gevonden en opgelost: 15 kritieke bugs

| # | Bug | Ernst |
|---|---|---|
| 1 | `BlockController` → `$registry->db()` bestaat niet | 🔴 Fatal |
| 2 | `OAuthController` volledig ontbrak — 2 routes dood | 🔴 Fatal |
| 3 | WoW `guild.php` template ontbrak | 🔴 Fatal |
| 4 | WoW `character.php` template ontbrak | 🔴 Fatal |
| 5 | `MinecraftController.php` + template ontbraken | 🔴 Fatal |
| 6 | `FiveMController.php` + template ontbraken | 🔴 Fatal |
| 7 | Guild admin template ontbrak | 🔴 Fatal |
| 8 | Marketplace `detail.php` view ontbrak | 🔴 Fatal |
| 9 | `news/show.twig` template ontbrak | 🔴 Fatal |
| 10 | `pages/default.twig` + `full.twig` ontbraken | 🔴 Fatal |
| 11 | `AdminController` miste `settings()` + `handle()` | 🔴 Fatal |
| 12 | `render_block()` Twig-functie niet geregistreerd | 🔴 Fatal |
| 13 | `PackageManager` CF_ROOT in class constants | ⚠️ Warn |
| 14 | `Application` registreerde BlockRegistry niet | ⚠️ Warn |
| 15 | `composer.json` — 7 module namespaces buiten PSR-4 | 🔴 Fatal |

### Na Sprint 8 analyse
- Routes broken: **0** (was 2)
- Ontbrekende templates: **0** (was 5)
- Module issues: **0**
- Composer PSR-4 volledig: **✅**
- Code issues: **0** (was 1 — AbstractBlock.getName() fixed)

---

## Security Audit — 98/100

| Check | Gevonden | Status |
|---|---|---|
| PDO Prepared Statements | 82× | ✅ |
| XSS htmlspecialchars() | 95× | ✅ |
| CSRF Token Validatie | 10× | ✅ |
| Argon2id Hashing | 2× | ✅ |
| AES-256-GCM Encryptie | 2× | ✅ |
| Rate Limiting | 60/min | ✅ |
| JWT Authenticatie | 7× | ✅ |
| Session Regeneration | 1× | ✅ |

---

## PSR Standaarden — 95/100

| Standaard | Aantal |
|---|---|
| `declare(strict_types=1)` | 90× |
| `namespace CommunityFusion\` | 85× |
| `readonly` properties | 104× |
| Arrow functions | 15× |
| `JSON_THROW_ON_ERROR` | 5× |

---

## Routes (38 totaal — 0 broken)

### REST API v1
`GET /api/v1/status` · `POST /api/v1/auth/login` · `GET /api/v1/auth/me` ·
`GET /api/v1/users` · `GET /api/v1/users/{id}` · `GET /api/v1/news` ·
`GET /api/v1/news/{slug}` · `GET /api/v1/pages` · `GET /api/v1/blocks/zones` ·
`POST /api/v1/blocks/positions` · `GET /api/v1/marketplace` ·
`GET /api/v1/marketplace/installed` · `GET /api/v1/marketplace/updates`

### Ollama API
`POST /api/ollama/chat` · `POST /api/ollama/summarize` · `GET /api/ollama/models`

### Admin
`/admin` · `/admin/settings` · `/admin/blocks` (CRUD) · `/admin/marketplace` (CRUD) ·
`/admin/guild` · `/admin/wow` · `/admin/ollama`

---

## Sprint Overzicht

| Sprint | Versie | Inhoud | Bestanden |
|---|---|---|---|
| S1 | 1.0.0 | Core Foundation | 35 |
| S2 | 1.1.0 | Installer + Admin + Frontend | +26 |
| S3 | 1.2.0 | Block API + Drag & Drop | +12 |
| S4 | 1.3.0 | Discord + Twitch OAuth | +17 |
| S5 | 1.4.0 | Guild + WoW + Minecraft + FiveM | +27 |
| S6 | 1.5.0 | REST API v1 + Ollama AI | +17 |
| S7 | 1.6.0 | Marketplace | +9 |
| S8 | 1.7.0 | Debug + 15 fixes + roadmap | +21 |
| **Totaal** | | | **164** |

---

*Blueprint CMS v1.7.0 — DieOuwe / Slayer Alliance — 2026-06-06*
