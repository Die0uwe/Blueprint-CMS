<?php
// Downloadstatistieken: logtabel + versiekolom op cf_downloads.
// Idempotent: DDL is niet transactioneel in MySQL/MariaDB.

declare(strict_types=1);

return function (\PDO $pdo, string $prefix): void {
    $downloads = $prefix . 'downloads';
    $log       = $prefix . 'download_log';

    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$log}` (
        `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `download_id` INT UNSIGNED NULL,
        `title`       VARCHAR(255) NOT NULL,
        `version`     VARCHAR(30) NULL,
        `user_id`     INT UNSIGNED NULL,
        `ip_address`  VARCHAR(45) NOT NULL DEFAULT '',
        `bytes_sent`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_dl_download` (`download_id`, `created_at`),
        KEY `idx_dl_created` (`created_at`),
        CONSTRAINT `fk_dl_download` FOREIGN KEY (`download_id`) REFERENCES `{$downloads}`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $pdo->exec("ALTER TABLE `{$downloads}` ADD COLUMN `version` VARCHAR(30) NULL AFTER `description`");
    } catch (\PDOException $e) {
        // 1060 = kolom bestaat al; SQLite: "duplicate column name".
        if (($e->errorInfo[1] ?? null) !== 1060 && !str_contains($e->getMessage(), 'duplicate column')) {
            throw $e;
        }
    }
};
