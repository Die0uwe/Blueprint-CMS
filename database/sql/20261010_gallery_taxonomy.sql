-- Blueprint CMS 1.35.0 — Galerij-taxonomie (voor hosting zonder SSH: plak in phpMyAdmin → SQL)
-- Pas `cf_` aan als je een andere tabelprefix gebruikt. Veilig om opnieuw te draaien,
-- behalve de twee ALTER's: die geven bij een tweede run "Duplicate column" — negeer dat.

ALTER TABLE `cf_gallery_items` ADD COLUMN `style` VARCHAR(60) NULL AFTER `description`;
ALTER TABLE `cf_gallery_items` ADD COLUMN `tags`  VARCHAR(600) NULL AFTER `style`;
ALTER TABLE `cf_gallery_items` ADD INDEX `idx_gi_style` (`style`);

-- 1) De 5 hoofdcategorieën (worden overgeslagen als de slug al bestaat)
INSERT IGNORE INTO `cf_categories` (`type`, `slug`, `name`, `description`, `position`) VALUES
('gallery', '3d-art',            '3D-Art',            '3D-renders, avatars en isometrische scènes.',        10),
('gallery', 'digital-paintings', 'Digital-Paintings', 'Concept art, olieverf, aquarel en fantasy art.',     20),
('gallery', 'illustrations',     'Illustrations',     'Cartoons, vector, line art en comics.',               30),
('gallery', 'photorealistic',    'Photorealistic',    'Foto-stijl: landschappen, portretten en stadsbeeld.', 40),
('gallery', 'ui-graphics',       'UI-Graphics',       'Logo''s, banners, iconen en website-elementen.',      50);

-- 2) Dubbele albums opruimen: eerst bekijken wat er dubbel is…
SELECT MIN(id) AS blijft, GROUP_CONCAT(id) AS alle_ids, LOWER(REPLACE(REPLACE(REPLACE(name,' ',''),'-',''),'_','')) AS genormaliseerd, COUNT(*) AS aantal
FROM `cf_categories` WHERE type = 'gallery'
GROUP BY COALESCE(parent_id, 0), genormaliseerd HAVING COUNT(*) > 1;

-- …en voeg samen (alleen voor top-level albums; subalbums beheer je via Admin → Galerij → Samenvoegen).
UPDATE `cf_gallery_items` gi
JOIN `cf_categories` d ON d.id = gi.album_id AND d.type = 'gallery' AND d.parent_id IS NULL
JOIN (SELECT MIN(id) AS keep_id, LOWER(REPLACE(REPLACE(REPLACE(name,' ',''),'-',''),'_','')) AS n
      FROM `cf_categories` WHERE type = 'gallery' AND parent_id IS NULL GROUP BY n HAVING COUNT(*) > 1) k
  ON k.n = LOWER(REPLACE(REPLACE(REPLACE(d.name,' ',''),'-',''),'_',''))
SET gi.album_id = k.keep_id WHERE gi.album_id <> k.keep_id;

DELETE d FROM `cf_categories` d
JOIN (SELECT MIN(id) AS keep_id, LOWER(REPLACE(REPLACE(REPLACE(name,' ',''),'-',''),'_','')) AS n
      FROM `cf_categories` WHERE type = 'gallery' AND parent_id IS NULL GROUP BY n HAVING COUNT(*) > 1) k
  ON k.n = LOWER(REPLACE(REPLACE(REPLACE(d.name,' ',''),'-',''),'_',''))
WHERE d.type = 'gallery' AND d.parent_id IS NULL AND d.id <> k.keep_id;
