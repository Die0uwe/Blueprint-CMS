<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $role (array|null), $error, $flash beschikbaar; bij bewerken ook
// $permissionGroups, $rolePermIds, $isProtected — vanuit
// RoleAdminController::createForm()/editForm()
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav    = 'roles';
$isEdit       = $role !== null;
$isProtected  = $isProtected ?? false;
$isSuperAdmin = $isSuperAdmin ?? false;
$actionUrl    = $isEdit ? '/admin/roles/' . (int) $role['id'] . '/bewerk' : '/admin/roles';
$rolePermIds  = $rolePermIds ?? [];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isEdit ? 'Rol bewerken' : 'Nieuwe rol' ?> — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>.form-wrap { max-width: 720px; }</style>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>🔑 <?= $isEdit ? 'Rol bewerken: ' . htmlspecialchars($role['display_name']) : 'Nieuwe rol' ?></h1>
      <a href="/admin/roles" class="cf-btn-sm">← Terug naar overzicht</a>
    </header>

    <div class="admin-content">
      <div class="form-wrap">
        <?php if ($error): ?>
          <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (($flash ?? null) === 'bijgewerkt'): ?>
          <div class="cf-alert cf-alert-success">Rol succesvol bijgewerkt.</div>
        <?php endif; ?>
        <?php if ($isEdit && $isProtected): ?>
          <div class="cf-alert" style="background:rgba(99,102,241,.12);color:#a5b4fc;">
            Dit is een kernrol van het systeem — de machine-naam (<code><?= htmlspecialchars($role['name']) ?></code>)
            kan niet gewijzigd worden en de rol kan niet verwijderd worden. De permissies hieronder mogen wél
            gewoon aangepast worden.
            <?php if ($isSuperAdmin): ?>
              Behalve hier: deze rol behoudt altijd <em>alle</em> rechten (de <code>*</code>-wildcard) — de
              permissie-lijst hieronder is daarom alleen-lezen.
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars($actionUrl) ?>">
          <?= CsrfProtection::field() ?>

          <div class="cf-form-group">
            <label class="cf-label">Naam</label>
            <input type="text" name="display_name" class="cf-input" required maxlength="100"
                   value="<?= htmlspecialchars($role['display_name'] ?? '') ?>" placeholder="Bijv. Content Editor">
            <?php if ($isEdit): ?>
              <p style="color:var(--text-dim);font-size:.78rem;margin:.35rem 0 0;">Machine-naam: <code><?= htmlspecialchars($role['name']) ?></code> (onveranderlijk)</p>
            <?php endif; ?>
          </div>

          <div class="cf-form-group">
            <label class="cf-label">Omschrijving</label>
            <textarea name="description" class="cf-input" rows="2"><?= htmlspecialchars($role['description'] ?? '') ?></textarea>
          </div>

          <div style="display:flex;gap:1rem;">
            <div class="cf-form-group" style="flex:1;">
              <label class="cf-label">Kleur <span style="color:var(--text-dim);font-weight:400;">— hex, optioneel</span></label>
              <input type="text" name="color" class="cf-input" maxlength="7" placeholder="#6c3df4"
                     value="<?= htmlspecialchars($role['color'] ?? '') ?>">
            </div>
            <div class="cf-form-group" style="flex:1;">
              <label class="cf-label">Prioriteit <span style="color:var(--text-dim);font-weight:400;">— hoger = meer</span></label>
              <input type="number" name="priority" class="cf-input" value="<?= (int) ($role['priority'] ?? 0) ?>">
            </div>
          </div>

          <?php if ($isEdit): ?>
            <div class="cf-form-group">
              <label class="cf-label">Permissies</label>
              <div class="cf-card" style="padding:1rem;max-height:420px;overflow-y:auto;">
                <?php foreach ($permissionGroups as $group => $perms): ?>
                  <div style="margin-bottom:1rem;">
                    <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-dim);font-weight:700;margin-bottom:.4rem;">
                      <?= htmlspecialchars($group) ?>
                    </div>
                    <?php foreach ($perms as $p): ?>
                      <label style="display:flex;align-items:flex-start;gap:.5rem;padding:.3rem 0;font-size:.87rem;">
                        <input type="checkbox" name="permissions[]" value="<?= (int) $p['id'] ?>"
                               style="margin-top:.2rem;"
                               <?= in_array((int) $p['id'], $rolePermIds, true) ? 'checked' : '' ?>
                               <?= $isSuperAdmin ? 'disabled' : '' ?>>
                        <span>
                          <code><?= htmlspecialchars($p['name']) ?></code>
                          <?php if (!empty($p['description'])): ?>
                            <br><span style="color:var(--text-dim);font-size:.78rem;"><?= htmlspecialchars($p['description']) ?></span>
                          <?php endif; ?>
                        </span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if ($isSuperAdmin): ?>
                <?php foreach ($rolePermIds as $pid): ?>
                  <input type="hidden" name="permissions[]" value="<?= (int) $pid ?>">
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <button type="submit" class="cf-btn"><?= $isEdit ? 'Wijzigingen opslaan' : 'Rol aanmaken' ?></button>
          <a href="/admin/roles" class="cf-btn-ghost">Annuleren</a>
        </form>
      </div>
    </div>
  </div>
</div>
</body>
</html>
