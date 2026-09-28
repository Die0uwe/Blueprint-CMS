<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $items, $page, $pages, $total, $search, $flash beschikbaar vanuit
// UserAdminController::index()

$activeNav = 'users';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gebruikers Beheer — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>👥 Gebruikers Beheer</h1>
    </header>

    <div class="admin-content">
      <?php if ($flash): ?>
        <div class="cf-alert cf-alert-success">Gebruiker succesvol <?= htmlspecialchars($flash) ?>.</div>
      <?php endif; ?>

      <div class="cf-toolbar">
        <form method="get" action="/admin/users" style="display:flex;gap:.5rem;">
          <input type="text" name="q" class="cf-input" placeholder="Zoek op gebruikersnaam, e-mail of naam…"
                 value="<?= htmlspecialchars($search) ?>" style="min-width:280px;">
          <button type="submit" class="cf-btn-sm">Zoeken</button>
          <?php if ($search !== ''): ?>
            <a href="/admin/users" class="cf-btn-ghost">Wissen</a>
          <?php endif; ?>
        </form>
        <span style="color:var(--text-dim);font-size:.85rem;"><?= $total ?> gebruiker(s)</span>
      </div>

      <div class="cf-card">
        <?php if (empty($items)): ?>
          <div class="cf-table-empty">Geen gebruikers gevonden.</div>
        <?php else: ?>
          <table class="cf-table">
            <thead>
              <tr>
                <th>Gebruiker</th>
                <th>E-mail</th>
                <th>Status</th>
                <th>Laatst ingelogd</th>
                <th>Geregistreerd</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $row): ?>
                <tr>
                  <td><strong><?= htmlspecialchars($row['username']) ?></strong>
                    <?php if (!empty($row['display_name']) && $row['display_name'] !== $row['username']): ?>
                      <br><span style="color:var(--text-dim);font-size:.8rem;"><?= htmlspecialchars($row['display_name']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($row['email']) ?></td>
                  <td>
                    <?php if ((int) $row['is_active'] === 1): ?>
                      <span class="cf-badge cf-badge-green">Actief</span>
                    <?php else: ?>
                      <span class="cf-badge cf-badge-red">Gedeactiveerd</span>
                    <?php endif; ?>
                    <?php if ((int) $row['is_verified'] !== 1): ?>
                      <span class="cf-badge cf-badge-gray">Niet geverifieerd</span>
                    <?php endif; ?>
                  </td>
                  <td><?= $row['last_login_at'] ? htmlspecialchars(date('d-m-Y H:i', strtotime((string) $row['last_login_at']))) : '—' ?></td>
                  <td><?= htmlspecialchars(date('d-m-Y', strtotime((string) $row['created_at']))) ?></td>
                  <td>
                    <div class="cf-table-actions">
                      <a href="/admin/users/<?= (int) $row['id'] ?>/bewerk" class="cf-btn-sm">✏️ Beheer</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <?php if ($pages > 1): ?>
        <div class="cf-pagination">
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a href="/admin/users?page=<?= $i ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
               class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
