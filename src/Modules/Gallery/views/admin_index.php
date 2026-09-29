<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $albums, $flash, $error beschikbaar vanuit GalleryAdminController::index()

use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'gallery';
$flashLabels = ['aangemaakt' => 'aangemaakt', 'bijgewerkt' => 'bijgewerkt', 'verwijderd' => 'verwijderd'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Galerij Beheer — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>📷 Galerij Beheer</h1>
      <a href="/admin/gallery/nieuw" class="cf-btn-sm">+ Nieuw album</a>
    </header>

    <div class="admin-content">
      <?php if ($flash && isset($flashLabels[$flash])): ?>
        <div class="cf-alert cf-alert-success">Album succesvol <?= htmlspecialchars($flashLabels[$flash]) ?>.</div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="cf-card">
        <?php if (empty($albums)): ?>
          <div class="cf-table-empty">Nog geen albums — maak er een aan om te starten.</div>
        <?php else: ?>
          <table class="cf-table">
            <thead>
              <tr>
                <th>Positie</th>
                <th>Naam</th>
                <th>Slug</th>
                <th>Foto's/video's</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($albums as $a): ?>
                <tr>
                  <td><?= (int) $a['position'] ?></td>
                  <td>
                    <strong><?= htmlspecialchars($a['name']) ?></strong>
                    <?php if (!empty($a['description'])): ?>
                      <br><span style="color:var(--text-dim);font-size:.8rem;"><?= htmlspecialchars($a['description']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td><code>/galerij/<?= htmlspecialchars($a['slug']) ?></code></td>
                  <td><?= (int) $a['item_count'] ?></td>
                  <td>
                    <div class="cf-table-actions">
                      <a href="/galerij/<?= htmlspecialchars($a['slug']) ?>" class="cf-btn-sm" target="_blank">👁️ Bekijk</a>
                      <a href="/admin/gallery/<?= (int) $a['id'] ?>/beheer" class="cf-btn-sm">✏️ Beheer</a>
                      <?php if ((int) $a['item_count'] === 0): ?>
                        <form method="post" action="/admin/gallery/<?= (int) $a['id'] ?>/verwijder" style="display:inline;"
                              onsubmit="return this.dataset.confirmed==='1' || (this.dataset.confirmed='1', document.getElementById('confirm-<?= (int) $a['id'] ?>').style.display='inline', false);">
                          <?= CsrfProtection::field() ?>
                          <button type="submit" class="cf-btn-sm" style="color:#f87171;border-color:rgba(248,113,113,.4);">🗑️ Verwijder</button>
                          <span id="confirm-<?= (int) $a['id'] ?>" style="display:none;color:var(--text-dim);font-size:.75rem;">Klik nogmaals om te bevestigen</span>
                        </form>
                      <?php else: ?>
                        <span class="cf-btn-sm" style="opacity:.5;cursor:not-allowed;" title="Bevat nog foto's/video's">🗑️ Verwijder</span>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
