<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Api\V1;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Auth\AuthManager;

final class UsersController
{
    public function __construct(
        private readonly Connection  $db,
        private readonly AuthManager $auth,
    ) {}

    /** GET /api/v1/users — lijst van gebruikers (admin only) */
    public function index(Request $request): Response
    {
        if (!$this->auth->can('users.view')) {
            return Response::json(['error' => 'Geen toegang.'], 403);
        }

        $page  = $request->page();
        $limit = min(50, max(1, (int)$request->query('limit', 20)));
        $offset = ($page - 1) * $limit;

        $users = $this->db->fetchAll(
            "SELECT id, username, email, display_name, avatar_url, is_active, created_at
             FROM cf_users WHERE deleted_at IS NULL
             ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        $total = (int)($this->db->fetchOne("SELECT COUNT(*) as c FROM cf_users WHERE deleted_at IS NULL")['c'] ?? 0);

        return Response::json([
            'data'  => $users,
            'meta'  => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int)ceil($total/$limit)],
        ]);
    }

    /** GET /api/v1/users/{id} */
    public function show(Request $request): Response
    {
        $id   = (int)$request->param('id');
        $user = $this->db->fetchOne(
            "SELECT id, username, display_name, avatar_url, bio, created_at FROM cf_users WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
        if (!$user) return Response::json(['error' => 'Gebruiker niet gevonden.'], 404);
        return Response::json($user);
    }
}
