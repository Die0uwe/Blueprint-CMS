<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $items, $page, $pages, $total, $flash zijn beschikbaar vanuit PageController::adminIndex()
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'pages';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\CommunityFusion\Core\I18n\Trans::locale(), ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pagina's Beheer — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>📄 Pagina's Beheer</h1>
      <a href="/admin/pages/create" class="cf-btn-sm">+ Nieuwe pagina</a>
    </header>

    <div class="admin-content">
      <?php if ($flash): ?>
        <div class="cf-alert cf-alert-success">Pagina succesvol <?= htmlspecialchars($flash) ?>.</div>
      <?php endif; ?>

      <div class="cf-card">
        <?php if (empty($items)): ?>
          <div class="cf-table-empty">
            Nog geen pagina's. <a href="/admin/pages/create">Maak de eerste pagina aan →</a>
          </div>
        <?php else: ?>
          <table class="cf-table">
            <thead>
              <tr>
                <th>Titel</th>
                <th>Auteur</th>
                <th>Status</th>
                <th>Menu</th>
                <th>Aangemaakt</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $row): ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars($row['title']) ?></strong>
                    <div style="color:var(--muted);font-size:.75rem;">/<?= htmlspecialchars($row['slug']) ?></div>
                  </td>
                  <td><?= htmlspecialchars($row['display_name'] ?? $row['username']) ?></td>
                  <td>
                    <span class="cf-badge <?= $row['status'] === 'published' ? 'cf-badge-green' : 'cf-badge-gold' ?>">
                      <?= htmlspecialchars($row['status']) ?>
                    </span>
                  </td>
                  <td><?= $row['menu_position'] !== null ? '#' . (int) $row['menu_position'] : '—' ?></td>
                  <td><?= htmlspecialchars(date('d-m-Y', strtotime((string) $row['created_at']))) ?></td>
                  <td>
                    <div class="cf-table-actions">
                      <a href="/admin/pages/<?= (int) $row['id'] ?>/bewerk" class="cf-btn-sm">✏️ Bewerk</a>
                      <form method="post" action="/admin/pages/<?= (int) $row['id'] ?>/verwijder"
                            onsubmit="return confirm('Deze pagina verwijderen?');" style="display:inline;">
                        <?= CsrfProtection::field() ?>
                        <button type="submit" class="cf-btn-sm cf-btn-danger">🗑️</button>
                      </form>
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
            <a href="/admin/pages?page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
