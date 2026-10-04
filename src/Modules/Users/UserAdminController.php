<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Users;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Auth\RBAC\RBACManager;
use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Security\CsrfProtection;

/**
 * /admin/users — Gebruikersbeheer (Wave 3).
 * Los van AuthController (login/register) en ProfileController (eigen profiel) —
 * dit is de admin-kant: andermans account bekijken, activeren/deactiveren,
 * rollen toewijzen. Permissie: users.manage (zie schema.sql + Router.php).
 */
final class UserAdminController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly UserRepository $repo,
        private readonly AuthManager    $auth,
        private readonly RBACManager    $rbac,
        private readonly AuditLogger    $audit,
    ) {}

    public function index(Request $request): Response
    {
        $page    = $request->page();
        $search  = trim((string) $request->query('q', ''));
        $offset  = ($page - 1) * self::PER_PAGE;
        $items   = $this->repo->getAll(self::PER_PAGE, $offset, $search);
        $total   = $this->repo->countAll($search);
        $pages   = max(1, (int) ceil($total / self::PER_PAGE));
        $flash   = $request->query('ok');

        ob_start();
        include __DIR__ . '/views/admin_index.php';
        return Response::html(ob_get_clean());
    }

    public function editForm(Request $request): Response
    {
        $id   = (int) $request->param('id');
        $user = $this->repo->findById($id);

        if ($user === null) {
            return Response::html('<h1>404 — Gebruiker niet gevonden</h1>', 404);
        }

        $allRoles     = $this->repo->getAllRoles();
        $userRoleIds  = $this->repo->getUserRoleIds($id);
        $isSelf       = $this->auth->id() === $id;
        $error        = $request->query('error');

        ob_start();
        include __DIR__ . '/views/admin_edit.php';
        return Response::html(ob_get_clean());
    }

    public function update(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $id   = (int) $request->param('id');
        $user = $this->repo->findById($id);
        if ($user === null) {
            return Response::html('<h1>404 — Gebruiker niet gevonden</h1>', 404);
        }

        $isSelf     = $this->auth->id() === $id;
        $roleIds    = array_map('intval', (array) $request->input('roles', []));
        $wantActive = $request->input('is_active') !== null;

        // Zelf-lockout voorkomen: je kan jezelf via dit scherm niet
        // deactiveren of al je eigen rollen ontnemen — anders kan de laatste
        // ingelogde super_admin zichzelf per ongeluk buitensluiten zonder dat
        // er nog iemand is die het kan terugdraaien.
        if ($isSelf) {
            $wantActive = true;
            if ($roleIds === []) {
                return Response::redirect("/admin/users/{$id}/bewerk?error=" . urlencode(
                    'Je kan je eigen laatste rol niet verwijderen — vraag een andere beheerder.'
                ));
            }
        }

        // Privilege-escalatie voorkomen: users.manage staat standaard ook op
        // de 'admin'-rol (priority 80), niet alleen op super_admin (priority
        // 100). Zonder deze check kon elke admin zichzelf of een ander via
        // dit formulier gewoon de super_admin-rol geven — de checkbox-lijst
        // in admin_edit.php toont immers alle rollen zonder onderscheid.
        // Regel: je kan nooit een rol toewijzen met een hogere priority dan
        // je eigen hoogste rol. Gevonden tijdens de S13-inventarisatiepas.
        $actingMaxPriority = $this->highestPriority($this->auth->id());
        $allRoles          = $this->repo->getAllRoles();
        $priorityById      = array_column($allRoles, 'priority', 'id');
        foreach ($roleIds as $rid) {
            if (($priorityById[$rid] ?? 0) > $actingMaxPriority) {
                return Response::redirect("/admin/users/{$id}/bewerk?error=" . urlencode(
                    'Je kan geen rol toewijzen met een hogere prioriteit dan je eigen rol.'
                ));
            }
        }

        $this->repo->setActive($id, $wantActive);
        $this->repo->syncRoles($id, $roleIds, $this->auth->id());

        // Zonder dit bleef RBACManager's 300s-cache (rbac.user.{id}.permissions/
        // .roles) de OUDE rollen teruggeven aan een al ingelogde gebruiker — een
        // net ontnomen recht (of een net gegeven recht) had dus tot 5 minuten
        // geen effect. Gevonden tijdens het bouwen van het Rollen-scherm
        // (Wave 5), dat dezelfde cache-invalidatie nodig had en dit gat toen
        // blootlegde. Zie CHANGELOG v1.15.0.
        $this->rbac->clearUserCache($id);

        $this->audit->log('users.update', $this->auth->id(), $this->auth->user()['username'] ?? null, [
            'target_user_id' => $id,
            'target_username' => $user['username'],
            'active' => $wantActive,
            'role_ids' => $roleIds,
        ]);

        return Response::redirect('/admin/users?ok=bijgewerkt');
    }

    /**
     * Hoogste role-priority van de ingelogde gebruiker (0 als hij geen
     * rollen heeft). RBACManager::getUserRoles() sorteert al op
     * priority DESC, dus de eerste rij volstaat.
     */
    private function highestPriority(int $userId): int
    {
        $roles = $this->rbac->getUserRoles($userId);
        return $roles === [] ? 0 : (int) $roles[0]['priority'];
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: UserAdminController.php | Role: Core | Version: 1.0.0        ║
// ║  Created: 2026-09-28 — Wave 3 (admin/users)                          ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ        ║
// ╚══════════════════════════════════════════════════════════════════════╝
