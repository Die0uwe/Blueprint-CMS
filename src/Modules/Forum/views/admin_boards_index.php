<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $boards, $flash, $error beschikbaar vanuit BoardAdminController::index()

$activeNav = 'forum';
$flashLabels = ['aangemaakt' => 'aangemaakt', 'bijgewerkt' => 'bijgewerkt', 'verwijderd' => 'verwijderd'];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\CommunityFusion\Core\I18n\Trans::locale(), ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forumborden Beheer — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>💬 Forumborden Beheer</h1>
      <a href="/admin/forum/boards/nieuw" class="cf-btn-sm">+ Nieuw bord</a>
    </header>

    <div class="admin-content">
      <?php if ($flash && isset($flashLabels[$flash])): ?>
        <div class="cf-alert cf-alert-success">Bord succesvol <?= htmlspecialchars($flashLabels[$flash]) ?>.</div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="cf-card">
        <?php if (empty($boards)): ?>
          <div class="cf-table-empty">Nog geen borden — maak er een aan om te starten.</div>
        <?php else: ?>
          <table class="cf-table">
            <thead>
              <tr>
                <th>Positie</th>
                <th>Naam</th>
                <th>Slug</th>
                <th>Topics</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($boards as $b): ?>
                <tr>
                  <td><?= (int) $b['position'] ?></td>
                  <td>
                    <strong><?= htmlspecialchars($b['name']) ?></strong>
                    <?php if (!empty($b['description'])): ?>
                      <br><span style="color:var(--text-dim);font-size:.8rem;"><?= htmlspecialchars($b['description']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td><code>/forum/<?= htmlspecialchars($b['slug']) ?></code></td>
                  <td><?= (int) $b['topic_count'] ?></td>
                  <td>
                    <div class="cf-table-actions">
                      <a href="/forum/<?= htmlspecialchars($b['slug']) ?>" class="cf-btn-sm" target="_blank">👁️ Bekijk</a>
                      <a href="/admin/forum/boards/<?= (int) $b['id'] ?>/bewerk" class="cf-btn-sm">✏️ Bewerk</a>
                      <?php if ((int) $b['topic_count'] === 0): ?>
                        <form method="post" action="/admin/forum/boards/<?= (int) $b['id'] ?>/verwijder" style="display:inline;"
                              onsubmit="return this.dataset.confirmed==='1' || (this.dataset.confirmed='1', document.getElementById('confirm-<?= (int) $b['id'] ?>').style.display='inline', false);">
                          <?= \CommunityFusion\Core\Security\CsrfProtection::field() ?>
                          <button type="submit" class="cf-btn-sm" style="color:#f87171;border-color:rgba(248,113,113,.4);">🗑️ Verwijder</button>
                          <span id="confirm-<?= (int) $b['id'] ?>" style="display:none;color:var(--text-dim);font-size:.75rem;">Klik nogmaals om te bevestigen</span>
                        </form>
                      <?php else: ?>
                        <span class="cf-btn-sm" style="opacity:.5;cursor:not-allowed;" title="Bevat nog topics">🗑️ Verwijder</span>
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
