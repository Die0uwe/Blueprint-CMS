<?php
// Galerij-taxonomie: stijl + tags op items, de 5 hoofdcategorieën als albums zaaien en dubbele
// albums (zelfde naam, bv. "3d-art" en "3d-art-a1b2") samenvoegen.
// Idempotent: DDL is niet transactioneel in MySQL/MariaDB.

declare(strict_types=1);

use CommunityFusion\Modules\Gallery\GalleryDeduplicator;

return function (\PDO $pdo, string $prefix): void {
    $items = $prefix . 'gallery_items';

    $add = static function (string $sql) use ($pdo): void {
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            // 1060 = kolom bestaat al; 1061 = index bestaat al; SQLite: "duplicate column name".
            $code = $e->errorInfo[1] ?? null;
            if ($code !== 1060 && $code !== 1061 && !str_contains($e->getMessage(), 'duplicate column')) {
                throw $e;
            }
        }
    };

    $add("ALTER TABLE `{$items}` ADD COLUMN `style` VARCHAR(60) NULL AFTER `description`");
    $add("ALTER TABLE `{$items}` ADD COLUMN `tags` VARCHAR(600) NULL AFTER `style`");
    $add("ALTER TABLE `{$items}` ADD INDEX `idx_gi_style` (`style`)");

    (new GalleryDeduplicator($pdo, $prefix))->run();
};
