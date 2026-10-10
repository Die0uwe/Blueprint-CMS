-- ============================================================
-- Community Fusion CMS — Database Schema v1.0
-- Engine: InnoDB | Charset: utf8mb4_unicode_ci
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- USERS
CREATE TABLE `cf_users` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`          VARCHAR(50) NOT NULL,
    `email`             VARCHAR(255) NOT NULL,
    `password_hash`     VARCHAR(255) NOT NULL,
    `display_name`      VARCHAR(100) NULL,
    `avatar_url`        VARCHAR(500) NULL,
    `bio`               TEXT NULL,
    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `is_verified`       TINYINT(1) NOT NULL DEFAULT 0,
    `email_verified_at` DATETIME NULL,
    `last_login_at`     DATETIME NULL,
    `last_login_ip`     VARCHAR(45) NULL,
    `locale`            VARCHAR(10) NOT NULL DEFAULT 'nl',
    `timezone`          VARCHAR(50) NOT NULL DEFAULT 'Europe/Amsterdam',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`        DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_username` (`username`),
    UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ROLES
CREATE TABLE `cf_roles` (
    `id`           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`         VARCHAR(50) NOT NULL,
    `display_name` VARCHAR(100) NOT NULL,
    `description`  TEXT NULL,
    `color`        VARCHAR(7) NULL,
    `is_default`   TINYINT(1) NOT NULL DEFAULT 0,
    `priority`     SMALLINT NOT NULL DEFAULT 0,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PERMISSIONS
CREATE TABLE `cf_permissions` (
    `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100) NOT NULL,
    `group`       VARCHAR(50) NOT NULL,
    `description` TEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ROLE <-> PERMISSIONS
CREATE TABLE `cf_role_permissions` (
    `role_id`       SMALLINT UNSIGNED NOT NULL,
    `permission_id` SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    CONSTRAINT `fk_rp_role`       FOREIGN KEY (`role_id`)       REFERENCES `cf_roles`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `cf_permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USER <-> ROLES
CREATE TABLE `cf_user_roles` (
    `user_id`    INT UNSIGNED NOT NULL,
    `role_id`    SMALLINT UNSIGNED NOT NULL,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `assigned_by` INT UNSIGNED NULL,
    PRIMARY KEY (`user_id`, `role_id`),
    CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `cf_users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `cf_roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OAUTH
CREATE TABLE `cf_user_oauth` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          INT UNSIGNED NOT NULL,
    `provider`         VARCHAR(50) NOT NULL,
    `provider_user_id` VARCHAR(255) NOT NULL,
    `access_token`     TEXT NOT NULL,
    `refresh_token`    TEXT NULL,
    `token_expires_at` DATETIME NULL,
    `scope`            TEXT NULL,
    `provider_data`    JSON NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_provider_user` (`provider`, `provider_user_id`),
    CONSTRAINT `fk_oauth_user` FOREIGN KEY (`user_id`) REFERENCES `cf_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- EXTRA E-MAILADRESSEN (max. 3 per account, één hoofdadres)
CREATE TABLE `cf_user_emails` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          INT UNSIGNED NOT NULL,
    `email`            VARCHAR(255) NOT NULL,
    `is_primary`       TINYINT(1) NOT NULL DEFAULT 0,
    `verified_at`      DATETIME NULL,
    `token_hash`       CHAR(64) NULL,
    `token_expires_at` DATETIME NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_email` (`email`),
    KEY `idx_ue_user` (`user_id`),
    KEY `idx_ue_token` (`token_hash`),
    CONSTRAINT `fk_ue_user` FOREIGN KEY (`user_id`) REFERENCES `cf_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MODULES
CREATE TABLE `cf_modules` (
    `id`           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug`         VARCHAR(100) NOT NULL,
    `name`         VARCHAR(150) NOT NULL,
    `version`      VARCHAR(20) NOT NULL,
    `author`       VARCHAR(100) NULL,
    `description`  TEXT NULL,
    `is_core`      TINYINT(1) NOT NULL DEFAULT 0,
    `is_enabled`   TINYINT(1) NOT NULL DEFAULT 1,
    `config`       JSON NULL,
    `installed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- BLOCK TYPES
CREATE TABLE `cf_block_types` (
    `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `module_id`   SMALLINT UNSIGNED NOT NULL,
    `slug`        VARCHAR(100) NOT NULL,
    `name`        VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `icon`        VARCHAR(100) NULL,
    `schema`      JSON NOT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`slug`),
    CONSTRAINT `fk_bt_module` FOREIGN KEY (`module_id`) REFERENCES `cf_modules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- BLOCK INSTANCES
CREATE TABLE `cf_blocks` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `block_type_id`    SMALLINT UNSIGNED NOT NULL,
    `zone`             VARCHAR(50) NOT NULL,
    `position`         SMALLINT NOT NULL DEFAULT 0,
    `title`            VARCHAR(200) NULL,
    `config`           JSON NULL,
    `is_visible`       TINYINT(1) NOT NULL DEFAULT 1,
    `visibility_roles` JSON NULL,
    `cache_ttl`        SMALLINT UNSIGNED NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_zone_position` (`zone`, `position`),
    CONSTRAINT `fk_block_type` FOREIGN KEY (`block_type_id`) REFERENCES `cf_block_types`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SETTINGS
CREATE TABLE `cf_settings` (
    `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `group`       VARCHAR(50) NOT NULL,
    `key`         VARCHAR(100) NOT NULL,
    `value`       TEXT NULL,
    `type`        ENUM('string','int','bool','json','encrypted') NOT NULL DEFAULT 'string',
    `label`       VARCHAR(200) NULL,
    `description` TEXT NULL,
    `is_public`   TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_group_key` (`group`, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CATEGORIES
CREATE TABLE `cf_categories` (
    `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id`   SMALLINT UNSIGNED NULL,
    `type`        VARCHAR(50) NOT NULL,
    `slug`        VARCHAR(150) NOT NULL,
    `name`        VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `position`    SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_type_slug` (`type`, `slug`),
    CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `cf_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NEWS
CREATE TABLE `cf_news` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `author_id`      INT UNSIGNED NOT NULL,
    `category_id`    SMALLINT UNSIGNED NULL,
    `slug`           VARCHAR(200) NOT NULL,
    `title`          VARCHAR(300) NOT NULL,
    `summary`        TEXT NULL,
    `content`        LONGTEXT NOT NULL,
    `featured_image` VARCHAR(500) NULL,
    `status`         ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    `is_sticky`      TINYINT(1) NOT NULL DEFAULT 0,
    `views`          INT UNSIGNED NOT NULL DEFAULT 0,
    `comment_count`  INT UNSIGNED NOT NULL DEFAULT 0,
    `published_at`   DATETIME NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`     DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`slug`),
    KEY `idx_status_published` (`status`, `published_at`),
    CONSTRAINT `fk_news_author`   FOREIGN KEY (`author_id`)   REFERENCES `cf_users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_news_category` FOREIGN KEY (`category_id`) REFERENCES `cf_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PAGES
CREATE TABLE `cf_pages` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `author_id`     INT UNSIGNED NOT NULL,
    `parent_id`     INT UNSIGNED NULL,
    `slug`          VARCHAR(200) NOT NULL,
    `title`         VARCHAR(300) NOT NULL,
    `content`       LONGTEXT NOT NULL,
    `template`      VARCHAR(100) NOT NULL DEFAULT 'default',
    `meta_title`    VARCHAR(200) NULL,
    `meta_desc`     VARCHAR(400) NULL,
    `status`        ENUM('draft','published') NOT NULL DEFAULT 'draft',
    `menu_position` SMALLINT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`    DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`slug`),
    CONSTRAINT `fk_page_author` FOREIGN KEY (`author_id`) REFERENCES `cf_users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_page_parent` FOREIGN KEY (`parent_id`) REFERENCES `cf_pages`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DEFAULT SEED DATA
INSERT INTO `cf_roles` (`name`, `display_name`, `priority`, `is_default`) VALUES
('super_admin', 'Super Admin', 100, 0),
('admin',       'Admin',       80,  0),
('moderator',   'Moderator',   50,  0),
('member',      'Member',      10,  1),
('guest',       'Gast',        0,   0);

-- 'blocks' is de eigenaar-module voor de 6 ingebouwde block types
-- (Text/Html/News/Login/Stats/Ad) — cf_block_types.module_id is NOT NULL
-- met een FK naar cf_modules, dus zelfs core blocks hebben een module nodig
-- om aan te hangen. Zie Application.php voor de sync-aanroep (v1.16.0-fix).
INSERT INTO `cf_modules` (`slug`, `name`, `version`, `is_core`, `is_enabled`) VALUES
('users',    'Gebruikersbeheer', '1.0.0', 1, 1),
('news',     'Nieuws',           '1.0.0', 1, 1),
('pages',    'Pagina\'s',        '1.0.0', 1, 1),
('settings', 'Instellingen',     '1.0.0', 1, 1),
('blocks',   'Blokkensysteem',   '1.0.0', 1, 1);

-- De 'core'-instellingengroep wordt niet hier geseed, maar pas tijdens de
-- installer (Step5.php) met de door de beheerder ingevulde site-gegevens.
-- 'contact.notify_email' hoort bij geen enkele installer-stap (die is nu
-- alleen via /admin/settings in te stellen — zie Settings\AdminController),
-- dus die rij zetten we hier wél alvast klaar: leeg toegestaan, valt in dat
-- geval terug op Mailer::getFromAddress() (zie ContactController::notifyAdmin()).
INSERT INTO `cf_settings` (`group`, `key`, `value`, `type`, `label`, `is_public`) VALUES
('contact', 'notify_email', '', 'string', 'Meldingen-e-mailadres', 0);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- MARKETPLACE SCHEMA (Sprint 7)
-- ============================================================

CREATE TABLE IF NOT EXISTS `cf_marketplace_packages` (
    `id`            INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `slug`          VARCHAR(100)        NOT NULL,
    `type`          ENUM('module','theme','block') NOT NULL DEFAULT 'module',
    `name`          VARCHAR(150)        NOT NULL,
    `description`   TEXT                NULL,
    `author`        VARCHAR(100)        NULL,
    `author_url`    VARCHAR(300)        NULL,
    `version`       VARCHAR(20)         NOT NULL,
    `min_cms`       VARCHAR(20)         NOT NULL DEFAULT '1.0.0',
    `license`       VARCHAR(50)         NOT NULL DEFAULT 'GPL-3.0',
    `download_url`  VARCHAR(500)        NULL COMMENT 'ZIP download URL',
    `homepage_url`  VARCHAR(500)        NULL,
    `icon_url`      VARCHAR(500)        NULL,
    `tags`          JSON                NULL,
    `downloads`     INT UNSIGNED        NOT NULL DEFAULT 0,
    `rating`        DECIMAL(3,2)        NULL,
    `is_featured`   TINYINT(1)          NOT NULL DEFAULT 0,
    `is_verified`   TINYINT(1)          NOT NULL DEFAULT 0,
    `is_premium`    TINYINT(1)          NOT NULL DEFAULT 0,
    `price`         DECIMAL(8,2)        NULL COMMENT 'NULL = gratis',
    `created_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`slug`),
    KEY `idx_type` (`type`),
    KEY `idx_featured` (`is_featured`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cf_marketplace_installed` (
    `id`            INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `package_slug`  VARCHAR(100)        NOT NULL,
    `type`          ENUM('module','theme','block') NOT NULL DEFAULT 'module',
    `version`       VARCHAR(20)         NOT NULL,
    `installed_at`  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `installed_by`  INT UNSIGNED        NULL,
    `update_available` VARCHAR(20)      NULL COMMENT 'Versie van beschikbare update',
    `is_enabled`    TINYINT(1)          NOT NULL DEFAULT 1,
    `install_path`  VARCHAR(300)        NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`package_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gereserveerd voor beoordelingen in de Marketplace (nog niet in de UI gebouwd).
CREATE TABLE IF NOT EXISTS `cf_marketplace_reviews` (
    `id`            INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `package_slug`  VARCHAR(100)        NOT NULL,
    `user_id`       INT UNSIGNED        NOT NULL,
    `rating`        TINYINT UNSIGNED    NOT NULL COMMENT '1-5 sterren',
    `review`        TEXT                NULL,
    `created_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_pkg` (`package_slug`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: ingebouwde modules als marketplace entries
INSERT IGNORE INTO `cf_marketplace_packages` (slug, type, name, description, author, version, is_verified, downloads, is_featured) VALUES
('discord',          'module', 'Discord Integration',       'OAuth login, rollen sync, widgets, live stats',                    'DieOuwe',   '1.0.0', 1, 1247, 1),
('twitch',           'module', 'Twitch Integration',        'Live status, stream embeds, kanaal statistieken',                  'DieOuwe',   '1.0.0', 1, 893,  1),
('warcraft',         'module', 'World of Warcraft',         'Guild roster, raid progress, character via Blizzard + Raider.IO',  'DieOuwe',   '1.0.0', 1, 734,  1),
('guild-management', 'module', 'Guild Management',          'Leden, teams, rangen, aanmeldingen, events',                       'DieOuwe',   '1.0.0', 1, 612,  1),
('minecraft',        'module', 'Minecraft Server Status',   'Server status, online spelers, MOTD via mcsrvstat.us',             'DieOuwe',   '1.0.0', 1, 521,  0),
('fivem',            'module', 'FiveM Server Status',       'FXServer status, spelers, ping via /info.json',                    'DieOuwe',   '1.0.0', 1, 388,  0),
('ollama',           'module', 'Ollama AI Integratie',      'Gratis lokale AI chat, content assistent, Open WebUI koppeling',   'DieOuwe',   '1.0.0', 1, 298,  1),
('default',          'theme',  'Blueprint Default',         'Gaming dark thema — het standaard Blueprint CMS thema',            'DieOuwe',   '1.0.0', 1, 2341, 1);

-- ============================================================
-- FORUM (Wave 1 gap-fix — cf_categories (type='forum') dient als
-- bordenlijst, conform de gedeelde categorieën-tabel uit SD §3.5)
-- ============================================================

CREATE TABLE IF NOT EXISTS `cf_forum_topics` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `board_id`          SMALLINT UNSIGNED NOT NULL COMMENT 'FK naar cf_categories.id (type=forum)',
    `author_id`         INT UNSIGNED NOT NULL,
    `slug`              VARCHAR(220) NOT NULL,
    `title`             VARCHAR(255) NOT NULL,
    `is_pinned`         TINYINT(1) NOT NULL DEFAULT 0,
    `is_locked`         TINYINT(1) NOT NULL DEFAULT 0,
    `views`             INT UNSIGNED NOT NULL DEFAULT 0,
    `reply_count`       INT UNSIGNED NOT NULL DEFAULT 0,
    `last_post_id`      INT UNSIGNED NULL,
    `last_post_at`      DATETIME NULL,
    `last_post_user_id` INT UNSIGNED NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`        DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_board_slug` (`board_id`, `slug`),
    KEY `idx_board_pinned_last` (`board_id`, `is_pinned`, `last_post_at`),
    KEY `idx_author` (`author_id`),
    CONSTRAINT `fk_ft_board`  FOREIGN KEY (`board_id`)  REFERENCES `cf_categories`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ft_author` FOREIGN KEY (`author_id`) REFERENCES `cf_users`(`id`)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cf_forum_posts` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `topic_id`      INT UNSIGNED NOT NULL,
    `author_id`     INT UNSIGNED NOT NULL,
    `content`       TEXT NOT NULL,
    `is_first_post` TINYINT(1) NOT NULL DEFAULT 0,
    `edited_at`     DATETIME NULL,
    `edited_by`     INT UNSIGNED NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at`    DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_topic_created` (`topic_id`, `created_at`),
    KEY `idx_author` (`author_id`),
    CONSTRAINT `fk_fp_topic`  FOREIGN KEY (`topic_id`)  REFERENCES `cf_forum_topics`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fp_author` FOREIGN KEY (`author_id`) REFERENCES `cf_users`(`id`)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eén standaardbord zodat het forum niet leeg oogt na installatie
INSERT IGNORE INTO `cf_categories` (`type`, `slug`, `name`, `description`, `position`) VALUES
('forum', 'algemeen', 'Algemeen', 'Algemene discussies over de community.', 0);

-- ============================================================
-- RBAC — permissies + rol-toewijzingen (Wave 1 gap-fix)
--
-- cf_permissions en cf_role_permissions werden nergens geseed:
-- module.json-bestanden (discord, twitch, ...) declareerden al een
-- "permissions"-lijst sinds Sprint 5, maar niets voerde die ooit in.
-- RBACManager::userCan() gaf daardoor voor ELKE gebruiker altijd false
-- terug — inclusief super_admin, want ook de '*'-wildcard werd nooit
-- toegekend. Elke auth()->can(...)-check in de codebase was dus dood.
-- ============================================================
INSERT IGNORE INTO `cf_permissions` (`name`, `group`, `description`) VALUES
('*',                  'system',      'Alle rechten (super admin wildcard)'),
('admin.access',       'system',      'Basistoegang tot het /admin-paneel'),
('users.manage',       'users',       'Gebruikers aanmaken, bewerken, bannen'),
('users.view',         'users',       'Gebruikerslijst inzien via de API'),
('news.create',        'news',        'Nieuwsartikelen aanmaken en bewerken'),
('pages.manage',       'pages',       'Pagina\'s aanmaken en bewerken'),
('modules.manage',     'system',      'Modules in-/uitschakelen'),
('blocks.manage',      'system',      'Blokken-layout beheren'),
('settings.edit',      'system',      'Site-instellingen bewerken'),
('marketplace.view',   'marketplace', 'Marketplace-catalogus en installed-lijst inzien'),
('marketplace.install','marketplace', 'Modules/thema\'s installeren, bijwerken, verwijderen'),
('discord.admin',      'discord',     'Discord-module configureren'),
('discord.sync',       'discord',     'Discord rollen-synchronisatie uitvoeren'),
('forum.post',         'forum',       'Nieuwe forumtopics en reacties plaatsen'),
('forum.moderate',     'forum',       'Topics/posts pinnen, sluiten of verwijderen');

-- Wave 2: marketplace.view/marketplace.install en users.view werden al
-- sinds respectievelijk Sprint 7 en Sprint 6 aangeroepen door
-- MarketplaceController::authorize() en UsersController::index() — maar
-- stonden, net als de rest hierboven, nooit in cf_permissions. Voor
-- MarketplaceController betekende dit dat zelfs de 'admin'-rol nooit bij
-- /admin/marketplace kon (alleen super_admin, via de '*'-wildcard).
INSERT IGNORE INTO `cf_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `cf_roles` r, `cf_permissions` p
WHERE (r.name = 'super_admin' AND p.name = '*')
   OR (r.name = 'admin' AND p.name IN (
        'admin.access','users.manage','users.view','news.create','pages.manage',
        'modules.manage','blocks.manage','settings.edit',
        'marketplace.view','marketplace.install',
        'discord.admin','discord.sync','forum.post','forum.moderate'
   ))
   OR (r.name = 'moderator' AND p.name IN ('forum.moderate'))
   OR (r.name = 'member' AND p.name IN ('forum.post'));

-- ============================================================
-- BLOG, DOWNLOADS, CONTACT (Wave 1 gap-fix)
-- ============================================================

-- Blog: elk lid schrijft in zijn eigen "blog" — vandaar uniek per
-- (author_id, slug) i.p.v. globaal uniek, en géén "blog.create"-permissie:
-- ieder ingelogd lid mag zijn eigen posts aanmaken/bewerken/verwijderen;
-- `blog.moderate` is alleen nodig om ANDERMANS post te bewerken/verwijderen.
CREATE TABLE IF NOT EXISTS `cf_blog_posts` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `author_id`      INT UNSIGNED NOT NULL,
    `category_id`    SMALLINT UNSIGNED NULL COMMENT 'FK naar cf_categories.id (type=blog)',
    `slug`           VARCHAR(200) NOT NULL,
    `title`          VARCHAR(300) NOT NULL,
    `summary`        TEXT NULL,
    `content`        LONGTEXT NOT NULL,
    `featured_image` VARCHAR(500) NULL,
    `status`         ENUM('draft','published') NOT NULL DEFAULT 'draft',
    `views`          INT UNSIGNED NOT NULL DEFAULT 0,
    `published_at`   DATETIME NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`     DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_author_slug` (`author_id`, `slug`),
    KEY `idx_status_published` (`status`, `published_at`),
    KEY `idx_category` (`category_id`),
    CONSTRAINT `fk_bp_author`   FOREIGN KEY (`author_id`)   REFERENCES `cf_users`(`id`)      ON DELETE CASCADE,
    CONSTRAINT `fk_bp_category` FOREIGN KEY (`category_id`) REFERENCES `cf_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Downloads: bestandsbeheer, curated door beheer (`downloads.manage`).
-- `file_path` is een door UploadManager gegenereerd willekeurig pad —
-- `original_filename` bewaart de nette naam los daarvan (nooit als
-- opslagpad gebruikt, alleen als Content-Disposition-bestandsnaam).
CREATE TABLE IF NOT EXISTS `cf_downloads` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `author_id`          INT UNSIGNED NOT NULL,
    `category_id`        SMALLINT UNSIGNED NULL COMMENT 'FK naar cf_categories.id (type=downloads)',
    `slug`               VARCHAR(200) NOT NULL,
    `title`              VARCHAR(255) NOT NULL,
    `description`        TEXT NULL,
    `version`            VARCHAR(30) NULL COMMENT 'Release-versie van het bestand (bv. 1.4.2)',
    `file_path`          VARCHAR(500) NOT NULL COMMENT 'Relatief pad binnen storage/downloads/',
    `original_filename`  VARCHAR(255) NOT NULL,
    `file_size`          INT UNSIGNED NOT NULL DEFAULT 0,
    `download_count`     INT UNSIGNED NOT NULL DEFAULT 0,
    `is_published`       TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`         DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`slug`),
    KEY `idx_published` (`is_published`),
    KEY `idx_category` (`category_id`),
    CONSTRAINT `fk_dl_author`   FOREIGN KEY (`author_id`)   REFERENCES `cf_users`(`id`)      ON DELETE CASCADE,
    CONSTRAINT `fk_dl_category` FOREIGN KEY (`category_id`) REFERENCES `cf_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Downloadlog: één rij per daadwerkelijk geserveerd bestand (statistieken-dashboard).
-- download_id wordt NULL als de download later verwijderd wordt; titel/versie zijn
-- een snapshot zodat de geschiedenis leesbaar blijft.
CREATE TABLE IF NOT EXISTS `cf_download_log` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `download_id`    INT UNSIGNED NULL,
    `title`          VARCHAR(255) NOT NULL,
    `version`        VARCHAR(30) NULL,
    `user_id`        INT UNSIGNED NULL,
    `ip_address`     VARCHAR(45) NOT NULL DEFAULT '',
    `bytes_sent`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dl_download` (`download_id`, `created_at`),
    KEY `idx_dl_created` (`created_at`),
    CONSTRAINT `fk_dl_download` FOREIGN KEY (`download_id`) REFERENCES `cf_downloads`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contact: publiek formulier, geen login vereist. `user_id` wordt alleen
-- gevuld als de afzender toevallig ingelogd was — geen verplichting.
CREATE TABLE IF NOT EXISTS `cf_contact_messages` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NULL,
    `name`       VARCHAR(150) NOT NULL,
    `email`      VARCHAR(255) NOT NULL,
    `subject`    VARCHAR(255) NULL,
    `message`    TEXT NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_is_read` (`is_read`),
    CONSTRAINT `fk_cm_user` FOREIGN KEY (`user_id`) REFERENCES `cf_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `cf_permissions` (`name`, `group`, `description`) VALUES
('blog.moderate',      'blog',      'Andermans blogposts bewerken of verwijderen'),
('downloads.manage',   'downloads', 'Downloads toevoegen, bewerken of verwijderen'),
('contact.manage',     'contact',   'Contactformulier-inbox inzien en afhandelen');

INSERT IGNORE INTO `cf_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `cf_roles` r, `cf_permissions` p
WHERE r.name = 'admin' AND p.name IN ('blog.moderate', 'downloads.manage', 'contact.manage');

-- ============================================================
-- Wave 5: /admin/roles, /admin/forum/boards, /admin/menus, /admin/themes,
-- /admin/logs, /admin/media — de laatste van de 6 placeholder-schermen uit
-- v1.10.0 kregen elk hun eigen permissie, net als de rest hierboven nooit
-- eerder geseed.
-- ============================================================
INSERT IGNORE INTO `cf_permissions` (`name`, `group`, `description`) VALUES
('roles.manage',   'system', 'Rollen aanmaken, bewerken en permissies toewijzen'),
('menus.manage',   'system', 'Sitenavigatie beheren'),
('themes.manage',  'system', 'Actief thema wisselen'),
('logs.view',      'system', 'Systeem-auditlog inzien'),
('media.manage',   'system', 'Geüploade bestanden inzien en verwijderen');

INSERT IGNORE INTO `cf_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `cf_roles` r, `cf_permissions` p
WHERE r.name = 'admin' AND p.name IN ('roles.manage', 'menus.manage', 'themes.manage', 'logs.view', 'media.manage');

-- Audit-log — Wave 5. Registreert alleen de events die daadwerkelijk ergens
-- in de code aangeroepen worden (zie AuditLogger::log() call-sites); geen
-- lege tabel die nooit gevuld wordt, zoals storage/logs/ tot deze wave was.
CREATE TABLE IF NOT EXISTS `cf_audit_log` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NULL COMMENT 'NULL bij mislukte login (onbekende/anonieme gebruiker)',
    `username`   VARCHAR(50)  NULL COMMENT 'Snapshot t.b.v. leesbaarheid, ook als de user later verwijderd wordt',
    `action`     VARCHAR(100) NOT NULL COMMENT 'bv. auth.login, auth.login_failed, forum.board.delete',
    `context`    JSON NULL COMMENT 'Vrije extra details per event, bv. {"board":"algemeen"}',
    `ip_address` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created_at`),
    KEY `idx_action` (`action`),
    KEY `idx_user` (`user_id`),
    KEY `idx_throttle` (`action`, `ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- S11: Media-galerij (los van de generieke upload-handler)
-- ============================================================
-- Albums = cf_categories met type='gallery' — zelfde hergebruik-patroon als
-- Forumborden (type='forum') en News-categorieën (type='news'), zie
-- ForumRepository/CategoryAdminController. Items krijgen een eigen tabel
-- omdat ze, anders dan een categorie, eigen bestands-/type-/auteurmetadata
-- nodig hebben — zelfde reden waarom cf_downloads een eigen tabel is i.p.v.
-- (her)gebruik van cf_categories.
--
-- `fk_gi_album` staat op ON DELETE CASCADE (net als cf_forum_topics.board_id)
-- — GalleryAdminController blokkeert het verwijderen van een album met nog
-- items erin actief in de applicatielaag (zelfde bescherming als
-- BoardAdminController::delete()), dus deze cascade is een laatste vangnet,
-- geen bedoeld gedrag.
--
-- `thumbnail_path` is alleen gevuld voor `media_type='image'` — video-items
-- krijgen bewust geen thumbnail (geen ffmpeg/frame-extractie in dit project,
-- zie GalleryThumbnailer.php); de publieke/admin-weergave valt voor video
-- terug op een play-icoon-placeholder i.p.v. een miniatuur.
CREATE TABLE IF NOT EXISTS `cf_gallery_items` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `album_id`           SMALLINT UNSIGNED NOT NULL COMMENT 'FK naar cf_categories.id (type=gallery)',
    `author_id`          INT UNSIGNED NOT NULL,
    `media_type`         ENUM('image','video') NOT NULL,
    `title`              VARCHAR(255) NULL,
    `description`        TEXT NULL,
    `style`              VARCHAR(60) NULL COMMENT 'Stijl-tag (slug), bv. pixar — zie GalleryTaxonomy',
    `tags`               VARCHAR(600) NULL COMMENT 'Vrije tags als ,tag1,tag2, (LIKE ''%,tag,%'')',
    `file_path`          VARCHAR(500) NOT NULL COMMENT 'Relatief pad binnen storage/uploads/gallery/',
    `thumbnail_path`     VARCHAR(500) NULL COMMENT 'Relatief pad, alleen gevuld voor media_type=image',
    `original_filename`  VARCHAR(255) NOT NULL,
    `file_size`          INT UNSIGNED NOT NULL DEFAULT 0,
    `width`              SMALLINT UNSIGNED NULL COMMENT 'Alleen voor afbeeldingen',
    `height`             SMALLINT UNSIGNED NULL COMMENT 'Alleen voor afbeeldingen',
    `is_published`       TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`         DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_album`     (`album_id`),
    KEY `idx_published` (`is_published`),
    KEY `idx_gi_style`  (`style`),
    CONSTRAINT `fk_gi_album`  FOREIGN KEY (`album_id`)  REFERENCES `cf_categories`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_gi_author` FOREIGN KEY (`author_id`) REFERENCES `cf_users`(`id`)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- De 5 hoofdcategorieën uit de galerij-taxonomie (GalleryTaxonomy::MAIN). Subalbums
-- krijgen een `parent_id` naar een van deze; stijlen zijn tags op items, geen albums.
INSERT IGNORE INTO `cf_categories` (`type`, `slug`, `name`, `description`, `position`) VALUES
('gallery', '3d-art',            '3D-Art',            '3D-renders, avatars en isometrische scènes.',        10),
('gallery', 'digital-paintings', 'Digital-Paintings', 'Concept art, olieverf, aquarel en fantasy art.',     20),
('gallery', 'illustrations',     'Illustrations',     'Cartoons, vector, line art en comics.',               30),
('gallery', 'photorealistic',    'Photorealistic',    'Foto-stijl: landschappen, portretten en stadsbeeld.', 40),
('gallery', 'ui-graphics',       'UI-Graphics',       'Logo''s, banners, iconen en website-elementen.',      50);

INSERT IGNORE INTO `cf_permissions` (`name`, `group`, `description`) VALUES
('gallery.manage', 'gallery', 'Albums aanmaken en foto''s/video''s uploaden of verwijderen');

INSERT IGNORE INTO `cf_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `cf_roles` r, `cf_permissions` p
WHERE r.name = 'admin' AND p.name IN ('gallery.manage');

-- Back-ups (v1.36.0): backup.manage = maken/downloaden/verwijderen/planning (admin + super_admin);
-- backup.restore = terugzetten (overschrijft de database): alleen super_admin via het '*'-wildcard.
INSERT IGNORE INTO `cf_permissions` (`name`, `group`, `description`) VALUES
('backup.manage',  'system', 'Back-ups maken, downloaden, verwijderen en plannen'),
('backup.restore', 'system', 'Een back-up terugzetten (overschrijft de database)');

INSERT IGNORE INTO `cf_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `cf_roles` r, `cf_permissions` p
WHERE r.name = 'admin' AND p.name IN ('backup.manage');

-- ============================================================
-- KRITIEK — v1.25.9, gevonden tijdens de Security-herscan van de totale-
-- codebase-audit: guild-management/module.json en ollama/module.json
-- declareren "guild.manage"/"guild.admin" resp. "ollama.admin" al sinds
-- hun allereerste versie, maar — exact dezelfde bug als hierboven bij
-- Wave 1/2/5/S11 telkens opnieuw gevonden en gefixt voor andere modules —
-- niets voerde die ooit in cf_permissions in. GuildModule.php/
-- OllamaModule.php gaven hun admin-routes daarom alleen AuthMiddleware
-- mee (elke ingelogde gebruiker) i.p.v. PermissionMiddleware: zonder een
-- bestaande permissie om tegen te checken was er nooit een andere optie.
-- Resultaat: elk geregistreerd lid kon /admin/guild/applications/{id}/
-- approve|reject aanroepen (guild-aanmeldingen goed-/afkeuren) en
-- /admin/ollama/save (Ollama-host/systeemprompt overschrijven — een
-- opstap naar SSRF via de publieke chatendpoint). Zie CHANGELOG v1.25.9.
-- ============================================================
INSERT IGNORE INTO `cf_permissions` (`name`, `group`, `description`) VALUES
('guild.manage', 'guild',  'Guild-aanmeldingen goed- of afkeuren, guild-instellingen beheren'),
('ollama.admin', 'ollama', 'Ollama AI-module configureren (host, systeemprompt, model)');

INSERT IGNORE INTO `cf_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `cf_roles` r, `cf_permissions` p
WHERE r.name = 'admin' AND p.name IN ('guild.manage', 'ollama.admin');

-- ============================================================
-- Wachtwoord vergeten / herstellen (v1.29.0)
-- Alleen de SHA-256-hash van het token staat in de database (nooit het token
-- zelf), het token is eenmalig (used_at) en verloopt na 60 minuten. Een
-- verbruikt token heeft een tweede functie: elke sessie van die gebruiker die
-- ouder is dan used_at wordt ongeldig (zie AuthManager / PasswordResetService).
-- ============================================================
CREATE TABLE IF NOT EXISTS `cf_password_resets` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED NOT NULL,
    `token_hash`   CHAR(64) NOT NULL COMMENT 'sha256 van het token uit de e-mail',
    `expires_at`   DATETIME NOT NULL,
    `used_at`      DATETIME NULL COMMENT 'NULL = nog niet gebruikt',
    `requested_ip` VARCHAR(45) NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token_hash` (`token_hash`),
    KEY `idx_user_created` (`user_id`, `created_at`),
    KEY `idx_user_used` (`user_id`, `used_at`),
    CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `cf_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
