-- Blueprint CMS 1.36.0 — Back-ups (voor hosting zonder SSH: plak in phpMyAdmin → SQL)
-- Pas `cf_` aan als je een andere tabelprefix gebruikt. Veilig om opnieuw te draaien.

INSERT IGNORE INTO `cf_permissions` (`name`, `group`, `description`) VALUES
('backup.manage',  'system', 'Back-ups maken, downloaden, verwijderen en plannen'),
('backup.restore', 'system', 'Een back-up terugzetten (overschrijft de database)');

INSERT IGNORE INTO `cf_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `cf_roles` r, `cf_permissions` p
WHERE r.name = 'admin' AND p.name IN ('backup.manage');

-- super_admin heeft het '*'-wildcard en mag dus ook terugzetten. Niets anders nodig.
