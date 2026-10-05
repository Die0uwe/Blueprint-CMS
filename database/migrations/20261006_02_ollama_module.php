<?php
// Zorgt dat de Ollama-module (AI-chatbox-blok + /admin/ollama) bestaat in cf_modules en dat de
// permissie ollama.admin bestaat en bij de rol "admin" hoort.
//  - Bestaat de module-rij al (aan óf uit), dan blijft die ongemoeid: een bewuste keuze wordt niet overschreven.
//  - Bestaat de rij niet en staat de map modules/ollama er wel, dan wordt de module ingeschakeld
//    (wat de installer bij een gekozen module ook zou doen).
// Idempotent en zonder MySQL-only syntax.

declare(strict_types=1);

return function (\PDO $pdo, string $prefix): void {
    $one = static function (string $sql, array $args = []) use ($pdo) {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    };

    $mods = $prefix . 'modules';
    if ($one("SELECT id FROM `{$mods}` WHERE slug = ?", ['ollama']) === null
        && is_file(dirname(__DIR__, 2) . '/modules/ollama/module.json')) {
        $pdo->prepare("INSERT INTO `{$mods}` (slug, name, version, is_core, is_enabled) VALUES (?, ?, ?, 0, 1)")
            ->execute(['ollama', 'Ollama AI Integratie', '1.1.0']);
    }

    $perms = $prefix . 'permissions';
    if ($one("SELECT id FROM `{$perms}` WHERE name = ?", ['ollama.admin']) === null) {
        $pdo->prepare("INSERT INTO `{$perms}` (name, `group`, description) VALUES (?, ?, ?)")
            ->execute(['ollama.admin', 'ollama', 'Ollama AI-module configureren (host, systeemprompt, model)']);
    }
    $perm = $one("SELECT id FROM `{$perms}` WHERE name = ?", ['ollama.admin']);
    $role = $one("SELECT id FROM `{$prefix}roles` WHERE name = ?", ['admin']);
    if ($perm !== null && $role !== null
        && $one("SELECT 1 AS x FROM `{$prefix}role_permissions` WHERE role_id = ? AND permission_id = ?", [$role['id'], $perm['id']]) === null) {
        $pdo->prepare("INSERT INTO `{$prefix}role_permissions` (role_id, permission_id) VALUES (?, ?)")
            ->execute([$role['id'], $perm['id']]);
    }
};
