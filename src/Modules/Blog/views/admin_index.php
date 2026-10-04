<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $items, $page, $pages, $total, $flash komen uit BlogAdminController::index()
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'blog';
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$flashText = ['gepubliceerd' => 'gepubliceerd', 'verborgen' => 'verborgen', 'verwijderd' => 'verwijderd'][$flash ?? ''] ?? null;
?>
<!DOCTYPE html>
<html lang="<?= $e(\CommunityFusion\Core\I18n\Trans::locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Blog Beheer — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>✍️ Blog Beheer</h1>
      <a href="<?= $e('/blog/' . $meUsername . '/nieuw') ?>" class="cf-btn-sm">+ Nieuw bericht</a>
    </header>

    <div class="admin-content">
      <?php if ($flashText): ?>
        <div class="cf-alert cf-alert-success">Bericht <?= $e($flashText) ?>.</div>
      <?php endif; ?>

      <div class="cf-card">
        <?php if (empty($items)): ?>
          <div class="cf-table-empty">Nog geen blogberichten.</div>
        <?php else: ?>
          <table class="cf-table">
            <thead><tr><th>Titel</th><th>Auteur</th><th>Status</th><th>Weergaven</th><th>Aangemaakt</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($items as $row): ?>
                <tr>
                  <td><strong><?= $e($row['title']) ?></strong></td>
                  <td><?= $e($row['display_name'] ?? $row['username']) ?></td>
                  <td><span class="cf-badge <?= $row['status'] === 'published' ? 'cf-badge-green' : 'cf-badge-gold' ?>"><?= $e($row['status']) ?></span></td>
                  <td><?= (int) $row['views'] ?></td>
                  <td><?= $e(date('d-m-Y', strtotime((string) $row['created_at']))) ?></td>
                  <td>
                    <div class="cf-table-actions">
                      <a href="<?= $e('/blog/' . $row['username'] . '/' . $row['slug'] . '/bewerk') ?>" class="cf-btn-sm">✏️ Bewerk</a>
                      <form method="post" action="/admin/blog/<?= (int) $row['id'] ?>/status" style="display:inline;">
                        <?= CsrfProtection::field() ?>
                        <button type="submit" class="cf-btn-sm"><?= $row['status'] === 'published' ? '🙈 Verberg' : '👁️ Publiceer' ?></button>
                      </form>
                      <form method="post" action="/admin/blog/<?= (int) $row['id'] ?>/verwijder"
                            onsubmit="return confirm('Verwijderen?');" style="display:inline;">
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
            <a href="/admin/blog?page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
