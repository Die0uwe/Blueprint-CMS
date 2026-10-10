<!--
============================================================================
Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
GPL-3.0-or-later
============================================================================
-->

# Galerij — taxonomie & structuur (Blueprint CMS ≥ 1.35.0)

Gebaseerd op het concept *Gallery Taxonomie & Structuur* (Scriptbase & Blueprint CMS).
De regels staan in code in `src/Modules/Gallery/GalleryTaxonomy.php`.

## 1. Hoofdcategorieën (max. 5)

Top-level albums (`cf_categories`, `type='gallery'`, `parent_id IS NULL`). Ze worden bij een
nieuwe installatie door `schema.sql` aangemaakt en op bestaande sites door migratie
`20261010_01_gallery_taxonomy` of via **Admin → Galerij → "Standaard hoofdcategorieën aanvullen"**.

| Slug | Naam | Denk aan |
|---|---|---|
| `3d-art` | 3D-Art | 3D-renders, avatars, isometric |
| `digital-paintings` | Digital-Paintings | concept art, olieverf, aquarel, fantasy |
| `illustrations` | Illustrations | cartoons, vector, line art, comics |
| `photorealistic` | Photorealistic | foto-stijl, landschappen, portretten |
| `ui-graphics` | UI-Graphics | logo's, banners, iconen, website-elementen |

## 2. Stijl-tags i.p.v. extra subcategorieën

Een item heeft **één stijl** (`cf_gallery_items.style`) en **vrije tags** (`tags`, opgeslagen als
`,orc,avatar,` zodat `LIKE '%,orc,%'` exact één tag matcht; max. 12 tags van 40 tekens).

| Categorie | Stijlen |
|---|---|
| 3D-Art | Chibi, Pixar, Isometric, Low-Poly, Voxel, Claymation, Unreal-Engine |
| Digital-Paintings | Fantasy, Cyberpunk, Steampunk, Anime, Oil-Painting, Watercolor, Concept-Art |
| Illustrations | Flat-Design, Line-Art, Comic, Vector, Retro, Doodle |
| Photorealistic | Cinematic, Macro, Portrait, Landscape, Urban, Studio, HDR |
| UI-Graphics | UI-Icons, Logo, Banner, Texture, HUD |

Bij een hoofdcategorie (of een subalbum ervan) kiest de beheerder uit deze lijst; eigen albums
accepteren elke stijl-slug. Bezoekers filteren op `/galerij/{album}?stijl=pixar` of `?tag=orc`.

## 3. Bestandsnaam-conventie

`[categorie]_[stijl]_[onderwerp]_[nn].ext`, bv. `3d_pixar_orc-warrior_01.jpg`.
Bij een upload met stijl of titel wordt `original_filename` zo gezet (volgnummer = aantal items in
het album + 1). Zonder stijl én titel blijft de naam van het geüploade bestand staan.

## 4. Subalbums

`parent_id` verwijst naar het bovenliggende album; **één niveau diep** (hoofdcategorie → subalbum).
Op de albumindex telt een hoofdcategorie ook de items van zijn subalbums mee. Een album met
subalbums kan niet verwijderd worden en kan zelf geen subalbum worden.

## 5. Dubbele categorieën

*Dubbel* = zelfde ouder + zelfde genormaliseerde naam (`3D Art` = `3d-art` = `3D_ART`, hoofdletters,
accenten, spaties en streepjes tellen niet).

* **Voorkomen** — een nieuw/hernoemd album met een naam die al bestaat op hetzelfde niveau wordt
  geweigerd. Voorheen kreeg zo'n album stilletjes een slug met willekeurig achtervoegsel
  (`3d-art-a1b2`), de bron van de dubbele categorieën.
* **Opruimen** — migratie en **Admin → Galerij → "Automatisch samenvoegen"** voegen duplicaten samen
  (oudste album blijft; items en subalbums verhuizen mee). Per album kan dit ook handmatig
  via *Samenvoegen* op het beheerscherm.

## 6. JSON-structuur

`GET /admin/gallery/export.json` (permissie `gallery.manage`):

```json
{ "items": [ {
  "id": "img-0104", "title": "Orc Warrior Avatar", "filename": "3d_pixar_orc-warrior_01.jpg",
  "media_type": "image", "category": "3D-Art", "parent_album_id": "album-1",
  "sub_album": "Character Renders", "style": "pixar", "tags": ["orc", "avatar"],
  "path": "/media/gallery/….jpg"
} ] }
```

## Upgraden (hosting met FTP + phpMyAdmin)

1. Upload de bestanden en maak `storage/cache` (incl. `twig/`) leeg.
2. Plak `database/sql/20261010_gallery_taxonomy.sql` in phpMyAdmin (of `php cli/console.php migrate`).
3. Admin → Galerij → controleer de waarschuwing voor dubbele albums en klik *Automatisch samenvoegen*.

Zonder stap 2 werkt de galerij gewoon door; stijl/tags verschijnen pas nadat de kolommen bestaan
(de admin toont daarvoor een melding).
