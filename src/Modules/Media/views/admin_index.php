<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $uploadsFiles, $downloadsFiles, $totalBytes, $flash, $error beschikbaar
// vanuit MediaAdminController::index()
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'media';

$fmtSize = static function (int $bytes): string {
    if ($bytes < 1024) return "{$bytes} B";
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1) . ' KB';
    return number_format($bytes / 1024 / 1024, 1) . ' MB';
};

$renderTable = function (array $files, string $area) use ($fmtSize) {
    if (empty($files)) {
        echo '<div class="cf-table-empty">Geen bestanden.</div>';
        return;
    }
    ?>
    <table class="cf-table">
      <thead><tr><th>Bestand</th><th>Grootte</th><th>Gewijzigd</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($files as $f): ?>
          <tr>
            <td><code style="font-size:.8rem;"><?= htmlspecialchars($f['path']) ?></code></td>
            <td><?= htmlspecialchars($fmtSize($f['size'])) ?></td>
            <td><?= htmlspecialchars(date('d-m-Y H:i', $f['mtime'])) ?></td>
            <td>
              <?php if ($f['in_use']): ?>
                <span class="cf-badge cf-badge-green">In gebruik</span>
              <?php else: ?>
                <span class="cf-badge cf-badge-gray">Ongebruikt</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($area === 'uploads' && in_array(strtolower(pathinfo($f['path'], PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp'], true)): ?>
                <a href="/media/<?= htmlspecialchars($f['path']) ?>" target="_blank" class="cf-btn-sm">👁️ Bekijk</a>
              <?php endif; ?>
              <?php if (!$f['in_use']): ?>
                <form method="post" action="/admin/media/verwijderen" style="display:inline;">
                  <?= CsrfProtection::field() ?>
                  <input type="hidden" name="area" value="<?= htmlspecialchars($area) ?>">
                  <input type="hidden" name="path" value="<?= htmlspecialchars($f['path']) ?>">
                  <button type="submit" class="cf-btn-sm" style="color:#f87171;border-color:rgba(248,113,113,.4);">🗑️ Verwijder</button>
                </form>
              <?php else: ?>
                <span class="cf-btn-sm" style="opacity:.5;cursor:not-allowed;" title="Nog in gebruik">🗑️ Verwijder</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
};
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Media Beheer — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>🖼️ Media Beheer</h1>
    </header>

    <div class="admin-content">
      <?php if ($flash === 'verwijderd'): ?>
        <div class="cf-alert cf-alert-success">Bestand verwijderd.</div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="cf-toolbar">
        <span style="color:var(--text-dim);font-size:.85rem;">
          <?= count($uploadsFiles) + count($downloadsFiles) ?> bestand(en) — <?= htmlspecialchars($fmtSize($totalBytes)) ?> totaal
        </span>
      </div>

      <h2 style="font-size:.9rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-dim);margin:1.25rem 0 .6rem;">
        storage/uploads/ — avatars (<?= count($uploadsFiles) ?>)
      </h2>
      <div class="cf-card"><?php $renderTable($uploadsFiles, 'uploads'); ?></div>

      <h2 style="font-size:.9rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-dim);margin:1.5rem 0 .6rem;">
        storage/downloads/ — Downloads-module (<?= count($downloadsFiles) ?>)
      </h2>
      <div class="cf-card"><?php $renderTable($downloadsFiles, 'downloads'); ?></div>
    </div>
  </div>
</div>
</body>
</html>
