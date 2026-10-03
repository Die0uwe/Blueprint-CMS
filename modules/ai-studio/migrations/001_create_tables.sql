-- ============================================================================
-- Blueprint AI Studio — migratie 001
-- Copyright (C) 2026 DieOuwe — GPL-3.0-or-later
--
-- IDEMPOTENT: veilig om meerdere keren te draaien, ook op een bestaande
-- installatie. Tabellen: CREATE TABLE IF NOT EXISTS. Kolommen/indexen die later
-- zijn toegevoegd: ALTER uitsluitend na een INFORMATION_SCHEMA-controle.
-- Uitvoeren: SchemaMigrator (per statement via PDO::exec), dus elke statement
-- eindigt op ";" aan het regeleinde en bevat nergens een ";" in een string.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cf_ai_conversations` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `title`      VARCHAR(200) NOT NULL DEFAULT 'Nieuw gesprek',
    `provider`   VARCHAR(32) NOT NULL DEFAULT '',
    `model`      VARCHAR(100) NOT NULL DEFAULT '',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_conv_user_updated` (`user_id`, `updated_at`),
    CONSTRAINT `fk_ai_conv_user` FOREIGN KEY (`user_id`) REFERENCES `cf_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cf_ai_messages` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `conversation_id` BIGINT UNSIGNED NOT NULL,
    `role`            VARCHAR(16) NOT NULL,
    `content`         MEDIUMTEXT NOT NULL,
    `provider`        VARCHAR(32) NOT NULL DEFAULT '',
    `model`           VARCHAR(100) NOT NULL DEFAULT '',
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_msg_conv` (`conversation_id`, `id`),
    CONSTRAINT `fk_ai_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `cf_ai_conversations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kolommen voor het wijzigingsvoorstel (diff). Op een verse installatie bestaan
-- ze nog niet, op een installatie met een eerdere (gedeeltelijke) versie van
-- deze tabel worden ze hier zonder foutmelding aangevuld.

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cf_ai_messages' AND COLUMN_NAME = 'proposal_diff');
SET @ddl := IF(@col = 0, 'ALTER TABLE `cf_ai_messages` ADD COLUMN `proposal_diff` MEDIUMTEXT NULL AFTER `model`', 'DO 0');
PREPARE aistudio_stmt FROM @ddl;
EXECUTE aistudio_stmt;
DEALLOCATE PREPARE aistudio_stmt;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cf_ai_messages' AND COLUMN_NAME = 'proposal_base_sha256');
SET @ddl := IF(@col = 0, 'ALTER TABLE `cf_ai_messages` ADD COLUMN `proposal_base_sha256` CHAR(64) NULL AFTER `proposal_diff`', 'DO 0');
PREPARE aistudio_stmt FROM @ddl;
EXECUTE aistudio_stmt;
DEALLOCATE PREPARE aistudio_stmt;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cf_ai_messages' AND COLUMN_NAME = 'proposal_applied_at');
SET @ddl := IF(@col = 0, 'ALTER TABLE `cf_ai_messages` ADD COLUMN `proposal_applied_at` DATETIME NULL AFTER `proposal_base_sha256`', 'DO 0');
PREPARE aistudio_stmt FROM @ddl;
EXECUTE aistudio_stmt;
DEALLOCATE PREPARE aistudio_stmt;

-- Index op (conversation_id, id) voor het ophalen van geschiedenis, alleen als hij ontbreekt.
SET @idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cf_ai_messages' AND INDEX_NAME = 'idx_ai_msg_conv');
SET @ddl := IF(@idx = 0, 'ALTER TABLE `cf_ai_messages` ADD INDEX `idx_ai_msg_conv` (`conversation_id`, `id`)', 'DO 0');
PREPARE aistudio_stmt FROM @ddl;
EXECUTE aistudio_stmt;
DEALLOCATE PREPARE aistudio_stmt;

-- Permissies (zelfde patroon als schema.sql). Standaard krijgt alleen de
-- admin-rol toegang; super_admin heeft de "*"-wildcard. De API-keys zijn
-- site-breed, dus "gebruiken" is bewust niet aan gewone leden gegeven.
INSERT IGNORE INTO `cf_permissions` (`name`, `group`, `description`) VALUES
('aistudio.use',   'ai-studio', 'AI Studio gebruiken (chat + editor, kost API-tegoed)'),
('aistudio.admin', 'ai-studio', 'AI Studio configureren (API-keys per provider)');

INSERT IGNORE INTO `cf_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `cf_roles` r, `cf_permissions` p
WHERE r.name = 'admin' AND p.name IN ('aistudio.use', 'aistudio.admin');
