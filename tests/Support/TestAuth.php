<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Support;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Auth\JWTManager;
use CommunityFusion\Core\Auth\RBAC\RBACManager;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;

/** Bouwt een echte AuthManager (met echte RBAC) voor een tijdelijke gebruiker met precies de opgegeven rechten. */
final class TestAuth
{
    /** @param list<string> $permissions */
    public static function make(Connection $db, array $permissions): AuthManager
    {
        $tag = bin2hex(random_bytes(4));
        $db->execute("INSERT INTO cf_users (username, email, password_hash) VALUES (?, ?, 'x')", ["t_{$tag}", "t_{$tag}@example.test"]);
        $uid = (int)$db->fetchOne('SELECT id FROM cf_users WHERE username = ?', ["t_{$tag}"])['id'];
        $db->execute("INSERT INTO cf_roles (name, display_name, priority) VALUES (?, 'Test', 1)", ["t_{$tag}"]);
        $rid = (int)$db->fetchOne('SELECT id FROM cf_roles WHERE name = ?', ["t_{$tag}"])['id'];
        $db->execute('INSERT INTO cf_user_roles (user_id, role_id) VALUES (?, ?)', [$uid, $rid]);
        foreach ($permissions as $p) {
            $db->execute('INSERT INTO cf_role_permissions (role_id, permission_id) SELECT ?, id FROM cf_permissions WHERE name = ?', [$rid, $p]);
        }
        $_SESSION['user_id'] = $uid;
        $cache = new CacheManager(['path' => sys_get_temp_dir() . '/bp-ta-' . $tag]);
        return @new AuthManager($db, new RBACManager($db, $cache), new JWTManager('test-secret-test-secret-test-secret'), new AuditLogger($db));
    }

    public static function cleanup(Connection $db): void
    {
        $db->execute("DELETE FROM cf_users WHERE username LIKE 't\\_%'");
        $db->execute("DELETE FROM cf_roles WHERE name LIKE 't\\_%'");
    }
}
