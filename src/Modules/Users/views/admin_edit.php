<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $user, $allRoles, $userRoleIds, $isSelf, $error beschikbaar vanuit
// UserAdminController::editForm()
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'users';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\CommunityFusion\Core\I18n\Trans::locale(), ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gebruiker beheren — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>.form-wrap { max-width: 640px; }</style>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>👤 <?= htmlspecialchars($user['username']) ?></h1>
      <a href="/admin/users" class="cf-btn-sm">← Terug naar overzicht</a>
    </header>

    <div class="admin-content">
      <div class="form-wrap">
        <?php if ($error): ?>
          <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($isSelf): ?>
          <div class="cf-alert" style="background:rgba(99,102,241,.12);color:#a5b4fc;">
            Dit is je eigen account — je kan jezelf hier niet deactiveren of je laatste rol afpakken.
          </div>
        <?php endif; ?>

        <div class="cf-card" style="padding:1rem 1.25rem;margin-bottom:1.5rem;">
          <p style="margin:0 0 .35rem;"><strong>E-mail:</strong> <?= htmlspecialchars($user['email']) ?></p>
          <p style="margin:0 0 .35rem;"><strong>Geregistreerd:</strong> <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $user['created_at']))) ?></p>
          <p style="margin:0;"><strong>Laatst ingelogd:</strong>
            <?= $user['last_login_at'] ? htmlspecialchars(date('d-m-Y H:i', strtotime((string) $user['last_login_at']))) . ' — ' . htmlspecialchars((string) ($user['last_login_ip'] ?? '')) : 'nooit' ?>
          </p>
        </div>

        <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/bewerk">
          <?= CsrfProtection::field() ?>

          <div class="cf-form-group">
            <label style="display:flex;align-items:center;gap:.5rem;font-size:.875rem;color:var(--text-dim);">
              <input type="checkbox" name="is_active" value="1"
                     <?= (int) $user['is_active'] === 1 ? 'checked' : '' ?>
                     <?= $isSelf ? 'disabled' : '' ?>>
              Account actief (uitvinken = direct uitgelogd, kan niet meer inloggen)
            </label>
            <?php if ($isSelf): ?>
              <input type="hidden" name="is_active" value="1">
            <?php endif; ?>
          </div>

          <div class="cf-form-group">
            <label class="cf-label">Rollen</label>
            <?php foreach ($allRoles as $role): ?>
              <label style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;padding:.35rem 0;">
                <input type="checkbox" name="roles[]" value="<?= (int) $role['id'] ?>"
                       <?= in_array((int) $role['id'], $userRoleIds, true) ? 'checked' : '' ?>>
                <?= htmlspecialchars($role['display_name']) ?>
                <span style="color:var(--text-dim);font-size:.8rem;">(<?= htmlspecialchars($role['name']) ?>)</span>
              </label>
            <?php endforeach; ?>
          </div>

          <button type="submit" class="cf-btn">Wijzigingen opslaan</button>
          <a href="/admin/users" class="cf-btn-ghost">Annuleren</a>
        </form>
      </div>
    </div>
  </div>
</div>
</body>
</html>
