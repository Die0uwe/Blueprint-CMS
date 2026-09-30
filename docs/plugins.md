# Plugins

Een plugin is een map in `plugins/{slug}/` met een `plugin.json`. Plugins breiden de site uit (blocks, routes, hooks, eigen tabellen, instellingen) zonder `src/Core/` te wijzigen. **Een plugin is uitvoerbare PHP-code met de rechten van de site: installeer alleen wat je vertrouwt.** Een voorbeeld staat in `plugins/example-hello-world/`.

## plugin.json

```json
{
  "slug": "mijn-plugin",
  "name": "Mijn plugin",
  "version": "1.0.0",
  "author": "Ik",
  "description": "Wat hij doet",
  "requires": { "blueprint": ">=1.29.0", "php": ">=8.3" },
  "class": "CommunityFusion\\Plugins\\MijnPlugin\\Plugin",
  "autoload": { "CommunityFusion\\Plugins\\MijnPlugin\\": "src/" },
  "permissions": ["mijn-plugin.beheer"],
  "settings": [ { "key": "api_url", "label": "API-URL", "type": "string" } ],
  "blocks": ["MijnBlock"],
  "routes": ["routes.php"],
  "hooks": ["content.after_render"]
}
```

* `slug` = mapnaam: alleen `a-z`, `0-9`, `-`, tot 64 tekens. Sleutels die met `_` beginnen worden genegeerd.
* `class` en alle klassen moeten in `CommunityFusion\Plugins\…` staan en `PluginInterface` implementeren (`boot(PluginContext $context)`).
* `autoload` mapt een namespace naar een map binnen de plugin (PSR-4). Paden buiten de plugin worden geweigerd.
* `permissions` moeten beginnen met `{slug}.`; ze komen in de groep `plugins`.
* `settings`-types: `string`, `int`, `bool`, `json`, `encrypted` (versleuteld opgeslagen, nooit teruggetoond).
* `blocks`: korte klassenamen in de autoload-namespace; ze worden geregistreerd als blocktype.
* `routes`: PHP-bestanden die `$router` en `$plugin` (de `PluginContext`) krijgen.

## PluginContext

| Lid | Doel |
|---|---|
| `$context->hooks` | `addAction()` / `addFilter()` |
| `$context->setting($key, $default)` | instelling van deze plugin |
| `$context->registerBlock($block)` | blocktype toevoegen |
| `$context->routes(callable)` | routes toevoegen |
| `$context->tablePrefix()` | `cf_plg_{slug}_` (streepjes worden underscores) |

## Database en migraties

`migrations/001_naam.sql`, `002_…`: bestandsnaam `^\d{3,}_[a-z0-9_]+\.sql$`, in volgorde en één keer uitgevoerd (`cf_plugin_migrations`). Regels (`PluginSqlGuard`, vóór uitvoeren gecontroleerd):

* alleen `CREATE/ALTER/DROP/INSERT/UPDATE/DELETE` op tabellen met het voorvoegsel `cf_plg_{slug}_`;
* een `FOREIGN KEY … REFERENCES` naar kerntabellen mag (alleen verwijzen);
* geen `LOAD_FILE`, `INTO OUTFILE`, `information_schema`, `/* */`-commentaar of meerdere statements die buiten de regels vallen.

Alle migraties worden eerst gecontroleerd; pas daarna wordt er iets uitgevoerd.

## Beheer

**`/admin/plugins`** (recht `plugins.manage`): activeren, deactiveren, migreren, instellingen, verwijderen (typ de naam; kies of de gegevens ook weg moeten). Elke actie komt in het auditlog (`plugin.*`).

**ZIP-upload** staat standaard uit. Aanzetten: `ALLOW_PLUGIN_UPLOAD=true` in `.env`; dan kan alleen de rol `super_admin` uploaden. De ZIP wordt vóór uitpakken gecontroleerd (max. 2000 bestanden, 50 MB, 10 MB per bestand, geen symlinks, geen `..`, geen verborgen of dubbele extensies, bestandstype-lijst) en atomisch op zijn plek gezet met terugdraaien bij een fout. Een geüploade plugin blijft **uit** tot je hem activeert.

**CLI** (shell-toegang = vertrouwensgrens): `php cli/console.php plugin:list | plugin:install <zip> | plugin:activate <slug> | plugin:deactivate <slug> | plugin:migrate <slug>`.

## Hooks voor plugins

| Hook | Soort | Waarde |
|---|---|---|
| `editor.toolbar.register` | filter | lijst knoppen: `id`, `label`, `title`, `before`, `after` (alleen tekst; wordt gecontroleerd en gekort) |
| `editor.markup.sanitize`, `content.before_render` | filter | markup-tekst vóór het renderen |
| `content.after_render` | filter | HTML van pagina/nieuws (`['type'=>…,'id'=>…]` als context) |
| `admin.menu` | filter | extra menu-items: `['key','href','label','icon']`; `href` moet `/admin/…` zijn |
| `router.routes` | actie | de Router |

Een plugin die een fout gooit wordt overgeslagen (gelogd); de site blijft werken. Een plugin die bij `content.after_render` iets anders dan tekst teruggeeft wordt genegeerd.

## Isolatie — wat een plugin niet kan (en wat wel)

Niet via het systeem: `src/Core/` wijzigen, tabellen buiten `cf_plg_{slug}_` aanmaken of wijzigen, de Twig-sandbox van gebruikersmarkup verruimen, JavaScript in de editor-toolbar zetten. **Wel:** omdat het gewone PHP is, kan een kwaadwillende plugin alles wat PHP kan. De regels hierboven beschermen tegen fouten en tegen kwaadaardige *pakketten* (ZIP-aanvallen, verkeerde paden), niet tegen een plugin die je bewust activeert.
