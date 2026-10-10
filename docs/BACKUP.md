# Back-ups (Blueprint CMS 1.36.0)

Beheer: **Admin → Systeem → Back-ups** (`/admin/backup`).

## Wat zit er in een back-up?
Eén zipbestand in `storage/backups/` (niet via het web bereikbaar):

| Bestand | Inhoud |
|---|---|
| `database.sql` | alle tabellen met je tabelprefix (`cf_…`), pure PHP-dump — geen `mysqldump`/SSH nodig |
| `manifest.json` | CMS-versie, tijdstip, type, aantallen |
| `uploads/…` | optioneel: geüploade bestanden (foto's, media) uit `storage/uploads` |

Bestandsnaam: `backup-JJJJ-MM-DD-UUMMSS-{auto|manual|pre-restore|upload}.zip`.

## Automatisch om 05:00
Standaard staat dagelijks 05:00 aan (tijd en aan/uit instelbaar). De laatste **7 dagen** blijven bewaard,
per dag één automatische back-up; oudere automatische back-ups worden opgeruimd.
Handmatige en geüploade back-ups worden nooit automatisch verwijderd; "vóór herstel"-back-ups: laatste 3.

Op gewone shared hosting (Strato) is er geen eigen scheduler. Er zijn drie triggers; ze mogen naast elkaar,
want er wordt hoogstens één automatische back-up per dag gemaakt:

1. **Webcron** (exact op tijd, aanbevolen): maak bij bv. cron-job.org een dagelijkse taak om 05:00 op de
   URL die op de back-uppagina staat: `https://jouwsite.nl/cron/backup/<token>`. Het token is geheim; met
   "Nieuw token" maak je een nieuwe aan. Achter Cloudflare: zet voor dit pad geen "Under Attack"/challenge aan.
2. **Server-cron** (als je hosting dat biedt): `php /pad/naar/site/cli/console.php backup:auto` om 05:00.
3. **Lazy** (altijd actief, geen instelling nodig): het eerste paginabezoek *na* 05:00 start de back-up ná het
   versturen van de pagina. Niet exact 05:00, maar je mist nooit een dag zolang er bezoekers zijn.

## Terugzetten
Alleen voor wie `backup.restore` heeft (standaard **alleen super_admin**; `backup.manage` voor admins dekt maken,
downloaden, verwijderen en planning).

* **Per weekdag:** de tegels "Afgelopen week" tonen maandag t/m zondag met de nieuwste back-up van die dag →
  *Terugzetten*. Of kies een rij in de volledige lijst.
* **Via upload:** `.zip` uit dit scherm of een `.sql`-dump uploaden. Groter dan de uploadlimiet van de server?
  Zet het bestand via FTP in `storage/backups/` (naam `backup-JJJJ-MM-DD-UUMMSS-upload.zip`), dan staat het in de lijst.

Veiligheid bij terugzetten:
* bevestigen door `HERSTEL` te typen;
* **vooraf wordt automatisch een "vóór herstel"-back-up gemaakt** — een vergissing is dus terug te draaien door die te herstellen;
* de dump wordt eerst gecontroleerd (eindmarkering aanwezig, alleen `DROP/CREATE/INSERT`-regels); een afgekapt of vreemd
  bestand wijzigt niets;
* uploads uit de zip worden zip-slip-veilig teruggezet; `.php`, `.phtml`, `.phar`, `.htaccess` e.d. worden altijd overgeslagen;
  bestaande bestanden blijven staan (er wordt aangevuld/overschreven, niet leeggemaakt).

Na terugzetten van een *oude* back-up: draai eventueel de nieuwste migraties (`database/sql/…` via phpMyAdmin) als die
back-up van een oudere CMS-versie is; het versienummer staat in de lijst.

## Instellingen en state
`storage/backups/state.json` (niet in de database, zodat een restore de planning niet terugzet):
`auto_enabled`, `time`, `include_uploads`, `token`, `last_success`, `last_error`.

## CLI
```
php cli/console.php backup:create [--uploads]
php cli/console.php backup:auto [--force]
php cli/console.php backup:list
```

## Eenmalig bij een bestaande installatie
Permissies `backup.manage`/`backup.restore` toevoegen: plak `database/sql/20261010_backup_permissions.sql` in
phpMyAdmin (of `php cli/console.php migrate`). super_admin werkt direct (wildcard).

## Grenzen
* De back-up draait in PHP: bij zeer grote databases/uploads kan `max_execution_time` knellen. Laat "bestanden meenemen"
  dan uit en kopieer `storage/uploads` via FTP.
* Een back-up op dezelfde server beschermt niet tegen het kwijtraken van die server — download af en toe een zip.
