-- Voorbeeld: een eigen tabel. Alleen namen cf_plg_example_hello_world_* zijn toegestaan.
CREATE TABLE IF NOT EXISTS `cf_plg_example_hello_world_greetings` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `text`       VARCHAR(200) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
