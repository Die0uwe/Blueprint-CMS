<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $items, $page, $pages, $total, $flash komen uit DownloadsAdminController::index()
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'downloads';
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$flashText = ['gepubliceerd' => 'gepubliceerd', 'verborgen' => 'verborgen', 'verwijderd' => 'verwijderd'][$flash ?? ''] ?? null;
?>
<!DOCTYPE html>
<html lang="<?= $e(\CommunityFusion\Core\I18n\Trans::locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Downloads Beheer — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>📥 Downloads Beheer</h1>
      <div style="display:flex;gap:.5rem;">
        <a href="/admin/downloads/statistieken" class="cf-btn-ghost">📊 Statistieken</a>
        <a href="/downloads/nieuw" class="cf-btn-sm">+ Nieuwe download</a>
      </div>
    </header>

    <div class="admin-content">
      <?php if ($flashText): ?>
        <div class="cf-alert cf-alert-success">Download <?= $e($flashText) ?>.</div>
      <?php endif; ?>

      <div class="cf-card">
        <?php if (empty($items)): ?>
          <div class="cf-table-empty">Nog geen downloads.</div>
        <?php else: ?>
          <table class="cf-table">
            <thead><tr><th>Titel</th><th>Bestand</th><th>Status</th><th>Downloads</th><th>Grootte</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($items as $row): ?>
                <tr>
                  <td><strong><?= $e($row['title']) ?></strong></td>
                  <td><?= $e($row['original_filename']) ?></td>
                  <td><span class="cf-badge <?= (int) $row['is_published'] === 1 ? 'cf-badge-green' : 'cf-badge-gold' ?>"><?= (int) $row['is_published'] === 1 ? 'published' : 'hidden' ?></span></td>
                  <td><?= (int) $row['download_count'] ?></td>
                  <td><?= $e(number_format(((int) $row['file_size']) / 1048576, 2, ',', '.')) ?> MB</td>
                  <td>
                    <div class="cf-table-actions">
                      <a href="<?= $e('/downloads/' . $row['slug'] . '/bewerk') ?>" class="cf-btn-sm">✏️ Bewerk</a>
                      <form method="post" action="/admin/downloads/<?= (int) $row['id'] ?>/status" style="display:inline;">
                        <?= CsrfProtection::field() ?>
                        <button type="submit" class="cf-btn-sm"><?= (int) $row['is_published'] === 1 ? '🙈 Verberg' : '👁️ Publiceer' ?></button>
                      </form>
                      <form method="post" action="/admin/downloads/<?= (int) $row['id'] ?>/verwijder"
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
            <a href="/admin/downloads?page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
