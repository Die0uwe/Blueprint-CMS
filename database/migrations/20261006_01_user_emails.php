<?php
// Extra e-mailadressen per account (max. 3, één hoofdadres) + backfill van het huidige cf_users.email.
// Idempotent: CREATE IF NOT EXISTS + backfill alleen voor gebruikers zonder rij.

declare(strict_types=1);

return function (\PDO $pdo, string $prefix): void {
    $t = $prefix . 'user_emails';
    $u = $prefix . 'users';

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$t}` (
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
        CONSTRAINT `fk_ue_user` FOREIGN KEY (`user_id`) REFERENCES `{$u}`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("INSERT INTO `{$t}` (`user_id`, `email`, `is_primary`, `verified_at`, `created_at`)
        SELECT u.`id`, u.`email`, 1, u.`email_verified_at`, u.`created_at`
        FROM `{$u}` u
        WHERE u.`deleted_at` IS NULL
          AND u.`email` NOT LIKE '%.invalid'
          AND NOT EXISTS (SELECT 1 FROM `{$t}` e WHERE e.`user_id` = u.`id`)");
};
