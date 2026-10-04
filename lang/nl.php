<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);

// Nederlands — de taal waarin dit project native geschreven is en de
// fallback voor elke ontbrekende sleutel in elke andere taal (zie
// Translator::$fallbackLocale). Dit bestand is dus altijd de meest
// complete — en de bron van waarheid bij het toevoegen van nieuwe sleutels.

return [
    'common' => [
        'save'     => 'Opslaan',
        'cancel'   => 'Annuleren',
        'delete'   => 'Verwijderen',
        'edit'     => 'Bewerken',
        'back'     => 'Terug',
        'language' => 'Taal',
    ],

    'nav' => [
        'news'      => 'Nieuws',
        'blog'      => 'Blog',
        'forum'     => 'Forum',
        'downloads' => 'Downloads',
        'gallery'   => 'Galerij',
        'contact'   => 'Contact',
        'admin'     => 'Admin',
        'login'     => 'Inloggen',
        'logout'    => 'Uitloggen',
    ],

    'auth' => [
        'login' => [
            'title'            => 'Inloggen',
            'identifier_label' => 'Gebruikersnaam of E-mail',
            'password_label'   => 'Wachtwoord',
            'submit'           => 'Inloggen →',
            'forgot_link'       => 'Wachtwoord vergeten?',
            'or'               => 'of',
            'with_discord'     => 'Inloggen met Discord',
            'with_twitch'      => 'Inloggen met Twitch',
            'with_google'      => 'Inloggen met Google',
            'with_battlenet'   => 'Inloggen met Battle.net',
            'with_provider'    => 'Inloggen met :provider',
            'no_account'       => 'Nog geen account?',
            'register_link'    => 'Registreren',
            'register_hint'    => '— of gebruik een van de knoppen hierboven, dan wordt er automatisch één aangemaakt.',
        ],
        'oauth' => [
            'error' => [
                'cancelled' => 'Inloggen met :provider is geannuleerd of mislukt. Probeer het opnieuw.',
                'state' => 'De inlogpoging is verlopen of ongeldig. Probeer het opnieuw.',
                'failed' => 'Inloggen met :provider is niet gelukt. Probeer het later opnieuw.',
                'disabled' => 'Dit account is gedeactiveerd en kan niet inloggen.',
                'not_configured' => 'Inloggen met :provider is nog niet ingesteld door de beheerder.',
                'not_enabled' => 'Inloggen met :provider is niet ingeschakeld.',
                'already_linked' => 'Dit :provider-account is al gekoppeld aan een ander account.',
                'other_linked' => 'Je hebt al een :provider-account gekoppeld. Ontkoppel dat eerst.',
                'last_method' => 'Dit is je enige inlogmethode. Stel eerst een wachtwoord in of koppel een ander account.',
            ],
        ],
        'forgot' => [
            'title' => 'Wachtwoord vergeten',
            'intro' => 'Vul je e-mailadres of gebruikersnaam in. Als er een account bij hoort, sturen we een link om een nieuw wachtwoord te kiezen.',
            'submit' => 'Herstelmail versturen →',
            'sent' => 'Als er een account bij deze gegevens hoort, is er een e-mail met een herstellink verstuurd. De link is 60 minuten geldig. Kijk ook in je spammap.',
            'back_to_login' => 'Terug naar inloggen',
        ],
        'reset' => [
            'title' => 'Nieuw wachtwoord kiezen',
            'password_label' => 'Nieuw wachtwoord (minimaal 8 tekens)',
            'submit' => 'Wachtwoord opslaan →',
            'done' => 'Je wachtwoord is gewijzigd. Je kunt nu inloggen.',
            'invalid_title' => 'Link ongeldig',
            'invalid' => 'Deze herstellink is ongeldig, verlopen of al gebruikt.',
            'request_new' => 'Nieuwe link aanvragen',
        ],
        'register' => [
            'title'                   => 'Nieuw Account',
            'username_label'          => 'Gebruikersnaam',
            'email_label'             => 'E-mailadres',
            'password_label'          => 'Wachtwoord',
            'password_confirm_label'  => 'Bevestig Wachtwoord',
            'submit'                  => 'Account Aanmaken →',
            'have_account'            => 'Al een account?',
            'login_link'              => 'Inloggen',
        ],
    ],

    'profile' => [
        'title'                => 'Mijn profiel',
        'avatar_label'         => 'Nieuwe avatar (jpg/png/gif/webp, max 5MB)',
        'avatar_submit'        => 'Avatar bijwerken',
        'linked_accounts'      => 'Gekoppelde accounts',
        'no_linked_accounts'   => 'Nog geen externe accounts gekoppeld.',
        'disconnect'           => 'Ontkoppelen',
        'connect_suffix'       => 'koppelen',
        'language_label'       => 'Taal van deze site',
        'language_hint'        => 'Bepaalt in welke taal je de site en het admin-paneel ziet, ongeacht de sitestandaard.',
        'language_submit'      => 'Taal opslaan',
        'language_updated'     => 'Taal bijgewerkt.',
        'bio_label'            => 'Over mij',
        'bio_hint'             => 'Korte introductie, zichtbaar op je publieke ledenprofiel. Max. 500 tekens.',
        'bio_submit'           => 'Bio opslaan',
        'bio_updated'          => 'Bio bijgewerkt.',
    ],

    'members' => [
        'since'         => 'Lid sinds',
        'recent_topics' => 'Laatste topics',
        'recent_posts'  => 'Laatste reacties',
        'no_topics'     => 'Nog geen topics gestart.',
        'no_posts'      => 'Nog geen reacties geplaatst.',
        'replies'       => 'reacties',
        'not_found'     => 'Gebruiker niet gevonden.',
    ],

    'admin' => [
        'sidebar' => [
            'section_content'    => 'Content',
            'dashboard'          => 'Dashboard',
            'news'               => 'Nieuws',
            'pages'              => "Pagina's",
            'media'              => 'Media',
            'gallery'            => 'Galerij',
            'section_community'  => 'Community',
            'users'              => 'Gebruikers',
            'roles'              => 'Rollen',
            'forum'              => 'Forumborden',
            'contact'            => 'Contact',
            'section_appearance' => 'Uiterlijk',
            'blocks'             => 'Blokken',
            'themes'             => "Thema's",
            'menus'              => "Menu's",
            'section_system'     => 'Systeem',
            'modules'            => 'Modules',
            'settings'           => 'Instellingen',
            'logs'               => 'Logs',
            'api_status'          => 'API-overzicht',
            'marketplace'        => 'Marketplace',
            'view_site'          => 'Bekijk Site',
            'logout'             => 'Uitloggen',
        ],
        'settings' => [
            'language_label' => 'Standaardtaal van de site',
            'language_hint'  => 'Taal voor bezoekers die zelf geen taal hebben gekozen (gast of profiel-instelling).',
        ],
        'dashboard' => [
            'title'          => 'Dashboard',
            'settings_btn'   => 'Instellingen',
            'stat_users'     => 'Gebruikers',
            'stat_users_sub' => 'Geregistreerd',
            'stat_news'      => 'Nieuws Artikelen',
            'stat_news_sub'  => 'Gepubliceerd',
            'stat_pages'     => "Pagina's",
            'stat_pages_sub' => 'Actief',
            'stat_modules'     => 'Modules',
            'stat_modules_sub' => 'Actief',
            'quick_actions'    => 'Snelle Acties',
            'action_new_article'  => 'Nieuw Artikel',
            'action_new_page'     => 'Nieuwe Pagina',
            'action_users'        => 'Gebruikers',
            'action_blocks'       => 'Blokken',
            'action_modules'      => 'Modules',
            'action_themes'       => "Thema's",
            'action_marketplace'  => 'Marketplace',
            'action_logs'         => 'Logs',
            'recent_activity'     => 'Recente Activiteit',
            'activity_installed'  => 'Blueprint CMS v1.0.0 geïnstalleerd',
            'activity_admin_created'  => 'Admin account aangemaakt',
            'activity_schema_imported' => 'Database schema geïmporteerd',
            'activity_now'        => 'Nu',
            'activity_just_now'   => 'Zojuist',
            'system_status'       => 'Systeem Status',
            'check_php_version'   => 'PHP Versie',
            'check_database'      => 'Database',
            'check_database_val'  => 'MariaDB / MySQL',
            'check_cache'         => 'Cache',
            'check_cache_val'     => 'File driver actief',
            'check_queue'         => 'Queue',
            'check_queue_val'     => 'Database driver actief',
            'check_debug_mode'    => 'Debug Mode',
            'check_debug_on'      => 'AAN',
            'check_debug_off'     => 'UIT',
        ],
    ],
];

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: lang/nl.php | Role: I18n | Version: 1.0.0                    ║
// ║  Created: 2026-09-29 | Status: New — S13 (Multi-language/i18n)      ║
// ╚══════════════════════════════════════════════════════════════════════╝
