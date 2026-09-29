<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Roles;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Security\CsrfProtection;

/**
 * /admin/roles — Rollen & Permissies (Wave 5).
 * De RBAC-tabellen (cf_roles/cf_permissions/cf_role_permissions) draaiden
 * al sinds Wave 1, maar hadden nooit een scherm — permissies aan een rol
 * koppelen kon alleen via directe SQL. Permissie: roles.manage.
 */
final class RoleAdminController
{
    public function __construct(
        private readonly RoleRepository $repo,
        private readonly AuthManager    $auth,
        private readonly AuditLogger    $audit,
    ) {}

    public function index(Request $request): Response
    {
        $roles = $this->repo->getAllWithCounts();
        $flash = $request->query('ok');
        $error = $request->query('error');

        ob_start();
        include __DIR__ . '/views/admin_index.php';
        return Response::html(ob_get_clean());
    }

    public function createForm(Request $request): Response
    {
        $role  = null;
        $error = $request->query('error');
        ob_start();
        include __DIR__ . '/views/admin_form.php';
        return Response::html(ob_get_clean());
    }

    public function store(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $displayName = trim((string) $request->input('display_name', ''));
        $description = trim((string) $request->input('description', ''));
        $color       = trim((string) $request->input('color', ''));
        $priority    = (int) $request->input('priority', 0);

        if ($displayName === '') {
            return Response::redirect('/admin/roles/nieuw?error=' . urlencode('Naam is verplicht.'));
        }

        $name = $this->slugify($displayName);
        if ($name === '' || in_array($name, RoleRepository::PROTECTED_NAMES, true) || $this->repo->nameTaken($name)) {
            $name .= '-' . bin2hex(random_bytes(2));
        }

        $id = $this->repo->createRole($name, $displayName, $description ?: null, $color ?: null, $priority);
        $this->logAction('roles.create', ['role_id' => $id, 'name' => $name, 'display_name' => $displayName]);
        return Response::redirect("/admin/roles/{$id}/bewerk?ok=aangemaakt");
    }

    public function editForm(Request $request): Response
    {
        $id   = (int) $request->param('id');
        $role = $this->repo->findById($id);

        if ($role === null) {
            return Response::html('<h1>404 — Rol niet gevonden</h1>', 404);
        }

        $permissionGroups = $this->repo->getAllPermissionsGrouped();
        $rolePermIds      = $this->repo->getRolePermissionIds($id);
        // "Beschermd" (naam onveranderlijk, niet verwijderbaar) geldt voor
        // alle 5 kernrollen. Maar hun PERMISSIES mogen wél vrij bewerkt
        // worden (dat is het hele punt van dit scherm) — alleen super_admin
        // is daarnaast ook hard-coded aan de '*'-wildcard gebonden
        // (RBACManager::userCan()), dus alleen díe checkboxen zijn
        // read-only. Deze twee eerst door elkaar gebruiken maakte de
        // permissie-checkboxen voor admin/moderator/member/guest allemaal
        // per ongeluk disabled — gevonden tijdens de live-test van dit
        // scherm (zie CHANGELOG v1.15.0).
        $isProtected      = in_array($role['name'], RoleRepository::PROTECTED_NAMES, true);
        $isSuperAdmin     = $role['name'] === 'super_admin';
        $error            = $request->query('error');
        $flash            = $request->query('ok');

        ob_start();
        include __DIR__ . '/views/admin_form.php';
        return Response::html(ob_get_clean());
    }

    public function update(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $id   = (int) $request->param('id');
        $role = $this->repo->findById($id);
        if ($role === null) {
            return Response::html('<h1>404 — Rol niet gevonden</h1>', 404);
        }

        $displayName = trim((string) $request->input('display_name', ''));
        $description = trim((string) $request->input('description', ''));
        $color       = trim((string) $request->input('color', ''));
        $priority    = (int) $request->input('priority', 0);
        $permIds     = array_map('intval', (array) $request->input('permissions', []));

        if ($displayName === '') {
            return Response::redirect("/admin/roles/{$id}/bewerk?error=" . urlencode('Naam is verplicht.'));
        }

        // super_admin behoudt altijd de '*'-wildcard — RBACManager::userCan()
        // leunt hier hard op (elke andere check zou super_admin per ongeluk
        // kunnen buitensluiten van zijn eigen beheerpaneel). Checkboxes voor
        // deze rol worden dan ook read-only getoond in de view; dit is de
        // server-side garantie daarachter.
        $wildcardId = $this->findWildcardPermissionId();
        if ($role['name'] === 'super_admin') {
            $permIds = $wildcardId !== null ? [$wildcardId] : $permIds;
        } elseif ($wildcardId !== null) {
            // Privilege-escalatie voorkomen: zonder deze uitsluiting kon elke
            // houder van roles.manage (standaard ook de 'admin'-rol, niet
            // alleen super_admin) de '*'-wildcard aan een ANDERE rol hangen
            // via de gewone permissions[]-checkboxlijst — de view toont die
            // checkbox voor niet-super_admin rollen namelijk gewoon actief.
            // Gevonden tijdens de S13-inventarisatiepas.
            $permIds = array_values(array_diff($permIds, [$wildcardId]));
        }

        $this->repo->updateRole($id, $displayName, $description ?: null, $color ?: null, $priority);
        $this->repo->syncPermissions($id, $permIds);
        $this->logAction('roles.update', ['role_id' => $id, 'name' => $role['name'], 'permission_ids' => $permIds]);

        return Response::redirect("/admin/roles/{$id}/bewerk?ok=bijgewerkt");
    }

    public function setDefault(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $id   = (int) $request->param('id');
        $role = $this->repo->findById($id);
        if ($role === null) {
            return Response::html('<h1>404 — Rol niet gevonden</h1>', 404);
        }
        $this->repo->setDefault($id);
        $this->logAction('roles.set_default', ['role_id' => $id, 'name' => $role['name']]);
        return Response::redirect('/admin/roles?ok=standaard-ingesteld');
    }

    public function delete(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $id   = (int) $request->param('id');
        $role = $this->repo->findById($id);
        if ($role === null) {
            return Response::html('<h1>404 — Rol niet gevonden</h1>', 404);
        }

        if (in_array($role['name'], RoleRepository::PROTECTED_NAMES, true)) {
            return Response::redirect('/admin/roles?error=' . urlencode(
                "\"{$role['display_name']}\" is een kernrol en kan niet verwijderd worden."
            ));
        }

        if ($this->repo->userCount($id) > 0) {
            return Response::redirect('/admin/roles?error=' . urlencode(
                "\"{$role['display_name']}\" is nog aan gebruikers toegewezen — ontkoppel die eerst via /admin/users."
            ));
        }

        $this->repo->deleteRole($id);
        $this->logAction('roles.delete', ['role_id' => $id, 'name' => $role['name'], 'display_name' => $role['display_name']]);
        return Response::redirect('/admin/roles?ok=verwijderd');
    }

    private function logAction(string $action, array $context): void
    {
        $this->audit->log($action, $this->auth->id(), $this->auth->user()['username'] ?? null, $context);
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        return trim($slug, '_');
    }

    private function findWildcardPermissionId(): ?int
    {
        $groups = $this->repo->getAllPermissionsGrouped();
        foreach ($groups['system'] ?? [] as $p) {
            if ($p['name'] === '*') return (int) $p['id'];
        }
        return null;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: RoleAdminController.php | Role: Core | Version: 1.0.0         ║
// ║  Created: 2026-09-29 — Wave 5 (admin/roles)                          ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ         ║
// ╚══════════════════════════════════════════════════════════════════════╝
