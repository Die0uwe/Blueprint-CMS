<?php
// Back-ups (v1.36.0): permissies backup.manage (admin + super_admin) en backup.restore (alleen super_admin
// via het '*'-wildcard). Idempotent (INSERT IGNORE).

declare(strict_types=1);

return function (\PDO $pdo, string $prefix): void {
    $perm = $prefix . 'permissions';
    $rp   = $prefix . 'role_permissions';
    $roles = $prefix . 'roles';

    $pdo->exec("INSERT IGNORE INTO `{$perm}` (`name`, `group`, `description`) VALUES
        ('backup.manage',  'system', 'Back-ups maken, downloaden, verwijderen en plannen'),
        ('backup.restore', 'system', 'Een back-up terugzetten (overschrijft de database)')");

    $pdo->exec("INSERT IGNORE INTO `{$rp}` (`role_id`, `permission_id`)
        SELECT r.id, p.id FROM `{$roles}` r, `{$perm}` p
        WHERE r.name = 'admin' AND p.name IN ('backup.manage')");
};
