<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $items, $page, $pages, $total, $flash zijn beschikbaar vanuit NewsController::adminIndex()
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'news';
$badgeFor  = static function (string $status): string {
    return match ($status) {
        'published' => 'cf-badge-green',
        'archived'  => 'cf-badge-gray',
        default     => 'cf-badge-gold',
    };
};
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nieuws Beheer — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>📰 Nieuws Beheer</h1>
      <div style="display:flex;gap:.5rem;">
        <a href="/admin/news/categories" class="cf-btn-ghost">🗂️ Categorieën beheren</a>
        <a href="/admin/news/create" class="cf-btn-sm">+ Nieuw artikel</a>
      </div>
    </header>

    <div class="admin-content">
      <?php if ($flash): ?>
        <div class="cf-alert cf-alert-success">Artikel succesvol <?= htmlspecialchars($flash) ?>.</div>
      <?php endif; ?>

      <div class="cf-card">
        <?php if (empty($items)): ?>
          <div class="cf-table-empty">
            Nog geen nieuwsartikelen. <a href="/admin/news/create">Maak het eerste artikel aan →</a>
          </div>
        <?php else: ?>
          <table class="cf-table">
            <thead>
              <tr>
                <th>Titel</th>
                <th>Auteur</th>
                <th>Status</th>
                <th>Weergaven</th>
                <th>Aangemaakt</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $row): ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars($row['title']) ?></strong>
                    <?php if ((int) $row['is_sticky'] === 1): ?><span class="cf-badge cf-badge-purple">Sticky</span><?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($row['display_name'] ?? $row['username']) ?></td>
                  <td><span class="cf-badge <?= $badgeFor($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                  <td><?= (int) $row['views'] ?></td>
                  <td><?= htmlspecialchars(date('d-m-Y', strtotime((string) $row['created_at']))) ?></td>
                  <td>
                    <div class="cf-table-actions">
                      <a href="/admin/news/<?= (int) $row['id'] ?>/bewerk" class="cf-btn-sm">✏️ Bewerk</a>
                      <form method="post" action="/admin/news/<?= (int) $row['id'] ?>/verwijder"
                            onsubmit="return confirm('Dit artikel verwijderen?');" style="display:inline;">
                        <?= CsrfProtection::field() ?>
                        <button type="submit" class="cf-btn-sm" style="background:rgba(239,68,68,.15);color:#fca5a5;">🗑️</button>
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
            <a href="/admin/news?page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
