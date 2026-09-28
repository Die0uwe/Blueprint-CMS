<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $roles, $flash, $error beschikbaar vanuit RoleAdminController::index()
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'roles';
$flashLabels = ['aangemaakt' => 'aangemaakt', 'bijgewerkt' => 'bijgewerkt', 'verwijderd' => 'verwijderd', 'standaard-ingesteld' => 'ingesteld als standaardrol'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rollen &amp; Permissies — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>🔑 Rollen &amp; Permissies</h1>
      <a href="/admin/roles/nieuw" class="cf-btn-sm">+ Nieuwe rol</a>
    </header>

    <div class="admin-content">
      <?php if ($flash && isset($flashLabels[$flash])): ?>
        <div class="cf-alert cf-alert-success">Rol succesvol <?= htmlspecialchars($flashLabels[$flash]) ?>.</div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="cf-card">
        <table class="cf-table">
          <thead>
            <tr>
              <th>Rol</th>
              <th>Machine-naam</th>
              <th>Prioriteit</th>
              <th>Permissies</th>
              <th>Gebruikers</th>
              <th>Standaard</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($roles as $r): ?>
              <?php $isProtected = in_array($r['name'], \CommunityFusion\Modules\Roles\RoleRepository::PROTECTED_NAMES, true); ?>
              <tr>
                <td>
                  <span style="display:inline-flex;align-items:center;gap:.4rem;">
                    <?php if (!empty($r['color'])): ?>
                      <span style="width:.6rem;height:.6rem;border-radius:50%;background:<?= htmlspecialchars($r['color']) ?>;display:inline-block;"></span>
                    <?php endif; ?>
                    <strong><?= htmlspecialchars($r['display_name']) ?></strong>
                  </span>
                  <?php if (!empty($r['description'])): ?>
                    <br><span style="color:var(--text-dim);font-size:.8rem;"><?= htmlspecialchars($r['description']) ?></span>
                  <?php endif; ?>
                </td>
                <td><code><?= htmlspecialchars($r['name']) ?></code>
                  <?php if ($isProtected): ?><span class="cf-badge cf-badge-gray" style="margin-left:.3rem;">kern</span><?php endif; ?>
                </td>
                <td><?= (int) $r['priority'] ?></td>
                <td><?= (int) $r['permission_count'] ?></td>
                <td><?= (int) $r['user_count'] ?></td>
                <td>
                  <?php if ((int) $r['is_default'] === 1): ?>
                    <span class="cf-badge cf-badge-green">✓ Standaard</span>
                  <?php else: ?>
                    <form method="post" action="/admin/roles/<?= (int) $r['id'] ?>/standaard" style="display:inline;">
                      <?= CsrfProtection::field() ?>
                      <button type="submit" class="cf-btn-sm" style="font-size:.75rem;">Maak standaard</button>
                    </form>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="cf-table-actions">
                    <a href="/admin/roles/<?= (int) $r['id'] ?>/bewerk" class="cf-btn-sm">✏️ Bewerk</a>
                    <?php if (!$isProtected && (int) $r['user_count'] === 0): ?>
                      <form method="post" action="/admin/roles/<?= (int) $r['id'] ?>/verwijder" style="display:inline;">
                        <?= CsrfProtection::field() ?>
                        <button type="submit" class="cf-btn-sm" style="color:#f87171;border-color:rgba(248,113,113,.4);">🗑️ Verwijder</button>
                      </form>
                    <?php elseif (!$isProtected): ?>
                      <span class="cf-btn-sm" style="opacity:.5;cursor:not-allowed;" title="Nog gebruikers gekoppeld">🗑️ Verwijder</span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</body>
</html>
