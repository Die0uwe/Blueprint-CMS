# Editors — bericht-editor en blok-editor

Vanaf v1.29.0 heeft Blueprint CMS twee eigen editors, zonder externe JavaScript-libraries.

## Bericht-editor (pagina's, nieuws, blog)

Open via **✍️ Open in editor** in het pagina- of nieuwsformulier, of direct: `/admin/editor/{page|news|blog}/{id}`.
Recht: `editor.use` (en daarnaast het gewone recht voor dat item: `pages.manage`, `news.create`, bij blog de eigenaar of `blog.moderate`).

| Onderdeel | Hoe het werkt |
|---|---|
| **Markup / Bron** | Markup kleurt HTML, Twig (`{{ }}`, `{% %}`, `{# #}`) en PHP-tags. Bron toont alleen tekst. Beide hebben regelnummers. De kleuring bouwt alleen tekstnodes (`textContent`), nooit `innerHTML`. |
| **Knoppen** | Vet, cursief, link, lijsten, code, citaat, `{{ }}`, `{% %}`. Plugins kunnen knoppen toevoegen (filter `editor.toolbar.register`, alleen platte invoeg-tekst). |
| **Voorbeeld** | `POST /admin/editor/preview` → een HTML-document in `<iframe sandbox="">` (geen scripts, geen same-origin) met een strikte CSP. |
| **Autosave** | Elke 30 s een concept per gebruiker en item (`cf_editor_drafts`). Bij terugkomen kun je het herstellen of verwerpen. `Ctrl+S` slaat echt op. |
| **Opslaan** | De bron gaat naar `content_markup`, de gerenderde HTML naar `content` (de publieke pagina leest alleen `content`). Blog is platte tekst. |
| **Tab** | Tab springt in; `Esc` laat Tab weer los (toetsenbord-toegankelijkheid). |

### Wat de markup wel en niet mag

* **Twig** draait in een sandbox met een vaste lijst (`config/markup-block.php`): tags `if`, `for`, `set`; een korte lijst filters; functies `max`, `min`, `date`. Variabelen: alleen `title` en `today`.
* Niet mogelijk: `include`, `raw`, `range` en `..`, callbacks, objecten, bestanden, netwerk. Limieten: 200 KB, 3 `for`-lussen, 3 s rekentijd.
* **PHP-tags worden nooit uitgevoerd.** Ze worden als zichtbare tekst bewaard. Wie ze wil gebruiken heeft het recht `editor.markup.php`; zonder dat recht wordt opslaan geweigerd.
* Previews, concepten en opslaan zijn begrensd per minuut per gebruiker.

## Blok-editor (`/admin/blocks`)

* **Slepen**: blokken uit het palet naar een zone, of verplaatsen tussen zones (HTML5 drag and drop).
* **⚙️ Instellingen** per blok: het formulier wordt gebouwd uit `getConfigSchema()` van het blocktype (types: string, textarea, code, url, integer, boolean, select). De server controleert en begrenst alles; onbekende sleutels vallen weg. Verbergen of verplaatsen wist de config niet meer.
* **👁 Voorbeeld** per zone: zo zien bezoekers de zichtbare blokken (sandbox-iframe).
* **Markup** (alleen met recht `blocks.override_template`):
  * bij het blocktype **Markup-blok**: eigen HTML + Twig per blok (`cf_block_content`);
  * bij een ander blocktype: een **sjabloon voor alle blokken van dat type** in `storage/block-overrides/{type}.twig`. Context: `title`, `today`, `config.<naam>` (alleen tekst en getallen). Leeg opslaan = terug naar standaard. Een kapotte override valt terug op het standaardsjabloon; de pagina blijft heel.
* Elke opslag van markup komt in het auditlog (`block.markup.save`).

## Rechten

| Recht | Doel | Standaard |
|---|---|---|
| `editor.use` | Bericht-editor gebruiken | rollen met `news.create` of `pages.manage` |
| `editor.markup.php` | PHP-tags bewaren in markup (nooit uitgevoerd) | niemand behalve super_admin (`*`) |
| `blocks.override_template` | Markup-blok en sjabloon-overrides | niemand behalve super_admin (`*`) |
| `blocks.manage` | Blokken plaatsen en instellen | bestaand |

## Hooks

| Hook | Soort | Wanneer |
|---|---|---|
| `editor.toolbar.register` | filter | lijst knoppen van de bericht-editor |
| `editor.markup.sanitize` | filter | markup vóór het renderen in de editor (PHP-recht, sandbox en limieten gelden daarna nog) |
| `content.before_render` | filter | zelfde moment, algemene naam |
| `content.after_render` | filter | de HTML van een pagina of nieuwsartikel vlak voor het tonen |

Niet opgenomen: een `editor.block.register`-hook. Plugins registreren blocktypes met `PluginContext::registerBlock()` of het manifest (`blocks`); die verschijnen vanzelf in het palet.

## Bekende beperkingen

* De editor is getest met PHP/Node-tests; een handmatige browsertest (Chrome, Firefox, Safari) staat nog open.
* Het oude formulier blijft bestaan. Opslaan via het formulier wist `content_markup`, zodat de editor nooit verouderde bron toont.
