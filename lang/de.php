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
            'forgot_link'       => 'Passwort vergessen?',
            'or'               => 'oder',
            'with_discord'     => 'Mit Discord anmelden',
            'with_twitch'      => 'Mit Twitch anmelden',
            'with_google'      => 'Mit Google anmelden',
            'with_battlenet'   => 'Mit Battle.net anmelden',
            'with_provider'    => 'Mit :provider anmelden',
            'no_account'       => 'Noch kein Konto?',
            'register_link'    => 'Registrieren',
            'register_hint'    => '— oder nutze einen der Buttons oben, dann wird automatisch eins erstellt.',
        ],
        'oauth' => [
            'error' => [
                'cancelled' => 'Die Anmeldung mit :provider wurde abgebrochen oder ist fehlgeschlagen. Bitte erneut versuchen.',
                'state' => 'Der Anmeldeversuch ist abgelaufen oder ungültig. Bitte erneut versuchen.',
                'failed' => 'Die Anmeldung mit :provider hat nicht funktioniert. Bitte später erneut versuchen.',
                'disabled' => 'Dieses Konto wurde deaktiviert und kann sich nicht anmelden.',
                'not_configured' => 'Die Anmeldung mit :provider wurde vom Administrator noch nicht eingerichtet.',
                'not_enabled' => 'Die Anmeldung mit :provider ist nicht aktiviert.',
                'already_linked' => 'Dieses :provider-Konto ist bereits mit einem anderen Konto verknüpft.',
                'other_linked' => 'Du hast bereits ein :provider-Konto verknüpft. Trenne es zuerst.',
                'last_method' => 'Dies ist deine einzige Anmeldemethode. Lege zuerst ein Passwort fest oder verknüpfe ein anderes Konto.',
            ],
        ],
        'forgot' => [
            'title' => 'Passwort vergessen',
            'intro' => 'Gib deine E-Mail-Adresse oder deinen Benutzernamen ein. Wenn ein Konto dazu passt, senden wir dir einen Link zum Festlegen eines neuen Passworts.',
            'submit' => 'Reset-E-Mail senden →',
            'sent' => 'Wenn zu diesen Angaben ein Konto existiert, wurde eine E-Mail mit einem Reset-Link gesendet. Der Link ist 60 Minuten gültig. Prüfe auch deinen Spam-Ordner.',
            'back_to_login' => 'Zurück zum Login',
        ],
        'reset' => [
            'title' => 'Neues Passwort wählen',
            'password_label' => 'Neues Passwort (mindestens 8 Zeichen)',
            'submit' => 'Passwort speichern →',
            'done' => 'Dein Passwort wurde geändert. Du kannst dich jetzt einloggen.',
            'invalid_title' => 'Link ungültig',
            'invalid' => 'Dieser Reset-Link ist ungültig, abgelaufen oder wurde bereits verwendet.',
            'request_new' => 'Neuen Link anfordern',
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
