<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);

// English. Any key missing here silently falls back to lang/nl.php
// (see Translator::$fallbackLocale) — this file does not need to be 100%
// complete at all times, but every key added to lang/nl.php should be
// mirrored here when practical.

return [
    'common' => [
        'save'     => 'Save',
        'cancel'   => 'Cancel',
        'delete'   => 'Delete',
        'edit'     => 'Edit',
        'back'     => 'Back',
        'language' => 'Language',
    ],

    'nav' => [
        'news'      => 'News',
        'blog'      => 'Blog',
        'forum'     => 'Forum',
        'downloads' => 'Downloads',
        'gallery'   => 'Gallery',
        'contact'   => 'Contact',
        'admin'     => 'Admin',
        'login'     => 'Log in',
        'logout'    => 'Log out',
    ],

    'auth' => [
        'login' => [
            'title'            => 'Log in',
            'identifier_label' => 'Username or Email',
            'password_label'   => 'Password',
            'submit'           => 'Log in →',
            'or'               => 'or',
            'with_discord'     => 'Log in with Discord',
            'with_twitch'      => 'Log in with Twitch',
            'with_google'      => 'Log in with Google',
            'with_battlenet'   => 'Log in with Battle.net',
            'with_github'     => 'Log in with GitHub',
            'no_account'       => "Don't have an account yet?",
            'register_link'    => 'Register',
            'register_hint'    => '— or use one of the buttons above, which creates one automatically.',
        ],
        'register' => [
            'title'                  => 'New Account',
            'username_label'         => 'Username',
            'email_label'            => 'Email address',
            'password_label'         => 'Password',
            'password_confirm_label' => 'Confirm Password',
            'submit'                 => 'Create Account →',
            'have_account'           => 'Already have an account?',
            'login_link'             => 'Log in',
        ],
    ],

    'profile' => [
        'title'              => 'My profile',
        'avatar_label'       => 'New avatar (jpg/png/gif/webp, max 5MB)',
        'avatar_submit'      => 'Update avatar',
        'linked_accounts'    => 'Linked accounts',
        'no_linked_accounts' => 'No external accounts linked yet.',
        'disconnect'         => 'Disconnect',
        'connect_suffix'     => 'connect',
        'status_connected' => 'Connected',
        'status_not_connected' => 'Not connected',
        'no_providers_available' => 'There are no accounts to connect yet. An administrator can enable and configure login providers in the modules.',
        'disconnect_blocked' => 'Cannot disconnect: you would no longer be able to log in. Connect another account first.',
        'flash_connected' => 'account connected.',
        'flash_disconnected' => 'account disconnected.',
        'flash_already_linked' => 'This account is already linked to another user.',
        'flash_last_login' => 'could not be disconnected: it is your only way to log in.',
        'connect_unavailable' => 'Currently unavailable for connecting',
        'language_label'     => 'Language for this site',
        'language_hint'      => "Determines which language you see the site and admin panel in, regardless of the site's default.",
        'language_submit'    => 'Save language',
        'language_updated'   => 'Language updated.',
        'bio_label'          => 'About me',
        'bio_hint'           => 'Short introduction, shown on your public member profile. Max. 500 characters.',
        'bio_submit'         => 'Save bio',
        'bio_updated'        => 'Bio updated.',
    ],

    'members' => [
        'since'         => 'Member since',
        'recent_topics' => 'Recent topics',
        'recent_posts'  => 'Recent replies',
        'no_topics'     => 'No topics started yet.',
        'no_posts'      => 'No replies posted yet.',
        'replies'       => 'replies',
        'not_found'     => 'User not found.',
    ],

    'admin' => [
        'sidebar' => [
            'section_content'    => 'Content',
            'dashboard'          => 'Dashboard',
            'news'               => 'News',
            'pages'              => 'Pages',
            'media'              => 'Media',
            'gallery'            => 'Gallery',
            'section_community'  => 'Community',
            'users'              => 'Users',
            'roles'              => 'Roles',
            'forum'              => 'Forum boards',
            'contact'            => 'Contact',
            'section_appearance' => 'Appearance',
            'blocks'             => 'Blocks',
            'themes'             => 'Themes',
            'menus'              => 'Menus',
            'section_system'     => 'System',
            'modules'            => 'Modules',
            'settings'           => 'Settings',
            'logs'               => 'Logs',
            'marketplace'        => 'Marketplace',
            'plugins'            => 'Plugins',
            'view_site'          => 'View Site',
            'logout'             => 'Log out',
        ],
        'settings' => [
            'language_label' => 'Default site language',
            'language_hint'  => "Language for visitors who haven't picked one themselves (guests, or via their profile).",
        ],
        'dashboard' => [
            'title'          => 'Dashboard',
            'settings_btn'   => 'Settings',
            'stat_users'     => 'Users',
            'stat_users_sub' => 'Registered',
            'stat_news'      => 'News Articles',
            'stat_news_sub'  => 'Published',
            'stat_pages'     => 'Pages',
            'stat_pages_sub' => 'Active',
            'stat_modules'     => 'Modules',
            'stat_modules_sub' => 'Active',
            'quick_actions'    => 'Quick Actions',
            'action_new_article'  => 'New Article',
            'action_new_page'     => 'New Page',
            'action_users'        => 'Users',
            'action_blocks'       => 'Blocks',
            'action_modules'      => 'Modules',
            'action_themes'       => 'Themes',
            'action_marketplace'  => 'Marketplace',
            'action_logs'         => 'Logs',
            'recent_activity'     => 'Recent Activity',
            'activity_installed'  => 'Blueprint CMS v1.0.0 installed',
            'activity_admin_created'  => 'Admin account created',
            'activity_schema_imported' => 'Database schema imported',
            'activity_now'        => 'Now',
            'activity_just_now'   => 'Just now',
            'system_status'       => 'System Status',
            'check_php_version'   => 'PHP Version',
            'check_database'      => 'Database',
            'check_database_val'  => 'MariaDB / MySQL',
            'check_cache'         => 'Cache',
            'check_cache_val'     => 'File driver active',
            'check_queue'         => 'Queue',
            'check_queue_val'     => 'Database driver active',
            'check_debug_mode'    => 'Debug Mode',
            'check_debug_on'      => 'ON',
            'check_debug_off'     => 'OFF',
        ],
    ],
];

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: lang/en.php | Role: I18n | Version: 1.0.0                    ║
// ║  Created: 2026-09-29 | Status: New — S13 (Multi-language/i18n)      ║
// ╚══════════════════════════════════════════════════════════════════════╝
