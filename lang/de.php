<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);

// Deutsch. Angeboten seit dem Installer (installer/templates/step3.php bot
// dit-en-de al vóór S13, maar zonder échte vertaalfunctie erachter — zie
// CHANGELOG v1.24.0). Ontbrekende sleutels vallen terug op lang/nl.php.

return [
    'common' => [
        'save'     => 'Speichern',
        'cancel'   => 'Abbrechen',
        'delete'   => 'Löschen',
        'edit'     => 'Bearbeiten',
        'back'     => 'Zurück',
        'language' => 'Sprache',
    ],

    'nav' => [
        'news'      => 'Neuigkeiten',
        'blog'      => 'Blog',
        'forum'     => 'Forum',
        'downloads' => 'Downloads',
        'gallery'   => 'Galerie',
        'contact'   => 'Kontakt',
        'admin'     => 'Admin',
        'login'     => 'Anmelden',
        'logout'    => 'Abmelden',
    ],

    'auth' => [
        'login' => [
            'title'            => 'Anmelden',
            'identifier_label' => 'Benutzername oder E-Mail',
            'password_label'   => 'Passwort',
            'submit'           => 'Anmelden →',
            'or'               => 'oder',
            'with_discord'     => 'Mit Discord anmelden',
            'with_twitch'      => 'Mit Twitch anmelden',
            'with_google'      => 'Mit Google anmelden',
            'with_battlenet'   => 'Mit Battle.net anmelden',
            'with_github'     => 'Mit GitHub anmelden',
            'no_account'       => 'Noch kein Konto?',
            'register_link'    => 'Registrieren',
            'register_hint'    => '— oder nutze einen der Buttons oben, dann wird automatisch eins erstellt.',
        ],
        'register' => [
            'title'                  => 'Neues Konto',
            'username_label'         => 'Benutzername',
            'email_label'            => 'E-Mail-Adresse',
            'password_label'         => 'Passwort',
            'password_confirm_label' => 'Passwort bestätigen',
            'submit'                 => 'Konto erstellen →',
            'have_account'           => 'Schon ein Konto?',
            'login_link'             => 'Anmelden',
        ],
    ],

    'profile' => [
        'title'              => 'Mein Profil',
        'avatar_label'       => 'Neuer Avatar (jpg/png/gif/webp, max. 5MB)',
        'avatar_submit'      => 'Avatar aktualisieren',
        'linked_accounts'    => 'Verknüpfte Konten',
        'no_linked_accounts' => 'Noch keine externen Konten verknüpft.',
        'disconnect'         => 'Trennen',
        'connect_suffix'     => 'verknüpfen',
        'status_connected' => 'Verknüpft',
        'status_not_connected' => 'Nicht verknüpft',
        'no_providers_available' => 'Es gibt noch keine Konten zum Verknüpfen. Ein Administrator kann Login-Anbieter in den Modulen aktivieren und einrichten.',
        'disconnect_blocked' => 'Trennen nicht möglich: danach könntest du dich nicht mehr anmelden. Verknüpfe zuerst ein anderes Konto.',
        'flash_connected' => 'Konto verknüpft.',
        'flash_disconnected' => 'Konto getrennt.',
        'flash_already_linked' => 'Dieses Konto ist bereits mit einem anderen Benutzer verknüpft.',
        'flash_last_login' => 'konnte nicht getrennt werden: es ist deine einzige Anmeldemöglichkeit.',
        'connect_unavailable' => 'Derzeit nicht zum Verknüpfen verfügbar',
        'language_label'     => 'Sprache für diese Seite',
        'language_hint'      => 'Bestimmt, in welcher Sprache du die Seite und das Admin-Panel siehst, unabhängig von der Standardsprache der Seite.',
        'language_submit'    => 'Sprache speichern',
        'language_updated'   => 'Sprache aktualisiert.',
        'bio_label'          => 'Über mich',
        'bio_hint'           => 'Kurze Vorstellung, sichtbar auf deinem öffentlichen Mitgliedsprofil. Max. 500 Zeichen.',
        'bio_submit'         => 'Bio speichern',
        'bio_updated'        => 'Bio aktualisiert.',
    ],

    'members' => [
        'since'         => 'Mitglied seit',
        'recent_topics' => 'Letzte Themen',
        'recent_posts'  => 'Letzte Antworten',
        'no_topics'     => 'Noch keine Themen erstellt.',
        'no_posts'      => 'Noch keine Antworten geschrieben.',
        'replies'       => 'Antworten',
        'not_found'     => 'Benutzer nicht gefunden.',
    ],

    'admin' => [
        'sidebar' => [
            'section_content'    => 'Inhalt',
            'dashboard'          => 'Dashboard',
            'news'               => 'Neuigkeiten',
            'pages'              => 'Seiten',
            'media'              => 'Medien',
            'gallery'            => 'Galerie',
            'section_community'  => 'Community',
            'users'              => 'Benutzer',
            'roles'              => 'Rollen',
            'forum'              => 'Forenbereiche',
            'contact'            => 'Kontakt',
            'section_appearance' => 'Aussehen',
            'blocks'             => 'Blöcke',
            'themes'             => 'Designs',
            'menus'              => 'Menüs',
            'section_system'     => 'System',
            'modules'            => 'Module',
            'settings'           => 'Einstellungen',
            'logs'               => 'Protokolle',
            'marketplace'        => 'Marktplatz',
            'plugins'            => 'Plugins',
            'view_site'          => 'Seite ansehen',
            'logout'             => 'Abmelden',
        ],
        'settings' => [
            'language_label' => 'Standardsprache der Seite',
            'language_hint'  => 'Sprache für Besucher, die selbst keine Sprache gewählt haben (Gäste oder über ihr Profil).',
        ],
        'dashboard' => [
            'title'          => 'Dashboard',
            'settings_btn'   => 'Einstellungen',
            'stat_users'     => 'Benutzer',
            'stat_users_sub' => 'Registriert',
            'stat_news'      => 'Neuigkeiten-Artikel',
            'stat_news_sub'  => 'Veröffentlicht',
            'stat_pages'     => 'Seiten',
            'stat_pages_sub' => 'Aktiv',
            'stat_modules'     => 'Module',
            'stat_modules_sub' => 'Aktiv',
            'quick_actions'    => 'Schnellaktionen',
            'action_new_article'  => 'Neuer Artikel',
            'action_new_page'     => 'Neue Seite',
            'action_users'        => 'Benutzer',
            'action_blocks'       => 'Blöcke',
            'action_modules'      => 'Module',
            'action_themes'       => 'Designs',
            'action_marketplace'  => 'Marktplatz',
            'action_logs'         => 'Protokolle',
            'recent_activity'     => 'Letzte Aktivität',
            'activity_installed'  => 'Blueprint CMS v1.0.0 installiert',
            'activity_admin_created'  => 'Admin-Konto erstellt',
            'activity_schema_imported' => 'Datenbankschema importiert',
            'activity_now'        => 'Jetzt',
            'activity_just_now'   => 'Soeben',
            'system_status'       => 'Systemstatus',
            'check_php_version'   => 'PHP-Version',
            'check_database'      => 'Datenbank',
            'check_database_val'  => 'MariaDB / MySQL',
            'check_cache'         => 'Cache',
            'check_cache_val'     => 'File-Treiber aktiv',
            'check_queue'         => 'Warteschlange',
            'check_queue_val'     => 'Datenbank-Treiber aktiv',
            'check_debug_mode'    => 'Debug-Modus',
            'check_debug_on'      => 'AN',
            'check_debug_off'     => 'AUS',
        ],
    ],
];

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: lang/de.php | Role: I18n | Version: 1.0.0                    ║
// ║  Created: 2026-09-29 | Status: New — S13 (Multi-language/i18n)      ║
// ╚══════════════════════════════════════════════════════════════════════╝
