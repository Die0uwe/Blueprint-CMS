<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $album, $items, $flash, $error beschikbaar vanuit GalleryAdminController::manage()
//
// Combineert album-metadata bewerken + item-upload + item-beheer in één
// scherm (i.p.v. drie aparte schermen) — een album zonder items is
// zelden interessant om te bezoeken, dus dit is het enige scherm dat een
// beheerder na het aanmaken van een album nog nodig heeft.

use CommunityFusion\Core\Security\CsrfProtection;

$activeNav   = 'gallery';
$flashLabels = ['bijgewerkt' => 'Album bijgewerkt.', 'geupload' => "Bestand geüpload.", 'verwijderd' => 'Item verwijderd.'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Album beheren: <?= htmlspecialchars($album['name']) ?> — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>
  /* Overige .cf-gallery-admin-* regels staan in blueprint.css (S11-sectie) */
  .form-wrap { max-width: 640px; }
</style>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>📷 Album beheren: <?= htmlspecialchars($album['name']) ?></h1>
      <a href="/admin/gallery" class="cf-btn-sm">← Terug naar overzicht</a>
    </header>

    <div class="admin-content">
      <?php if ($flash && isset($flashLabels[$flash])): ?>
        <div class="cf-alert cf-alert-success"><?= htmlspecialchars($flashLabels[$flash]) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="cf-card">
        <h2 style="margin-top:0;">Albumgegevens</h2>
        <div class="form-wrap">
          <form method="post" action="/admin/gallery/<?= (int) $album['id'] ?>/bewerk">
            <?= CsrfProtection::field() ?>

            <div class="cf-form-group">
              <label class="cf-label">Naam</label>
              <input type="text" name="name" class="cf-input" required maxlength="200"
                     value="<?= htmlspecialchars($album['name']) ?>">
            </div>

            <div class="cf-form-group">
              <label class="cf-label">Slug (URL)</label>
              <input type="text" name="slug" class="cf-input" maxlength="150"
                     value="<?= htmlspecialchars($album['slug']) ?>">
              <p style="color:var(--text-dim);font-size:.78rem;margin:.35rem 0 0;">
                Let op: de slug wijzigen breekt bestaande links naar dit album (<code>/galerij/<?= htmlspecialchars($album['slug']) ?></code>).
              </p>
            </div>

            <div class="cf-form-group">
              <label class="cf-label">Omschrijving</label>
              <textarea name="description" class="cf-input" rows="3"><?= htmlspecialchars($album['description'] ?? '') ?></textarea>
            </div>

            <div class="cf-form-group">
              <label class="cf-label">Positie <span style="color:var(--text-dim);font-weight:400;">— lager = hoger in de lijst</span></label>
              <input type="number" name="position" class="cf-input" style="max-width:120px;"
                     value="<?= (int) $album['position'] ?>">
            </div>

            <button type="submit" class="cf-btn">Wijzigingen opslaan</button>
          </form>
        </div>
      </div>

      <div class="cf-card">
        <h2 style="margin-top:0;">Foto/video uploaden</h2>
        <div class="form-wrap">
          <form method="post" action="/admin/gallery/<?= (int) $album['id'] ?>/upload" enctype="multipart/form-data">
            <?= CsrfProtection::field() ?>

            <div class="cf-form-group">
              <label class="cf-label">Bestand <span style="color:var(--text-dim);font-weight:400;">— jpg, png, gif, webp, mp4 of webm (max. 25MB)</span></label>
              <input type="file" name="file" class="cf-input" required accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm">
            </div>

            <div class="cf-form-group">
              <label class="cf-label">Titel <span style="color:var(--text-dim);font-weight:400;">— optioneel</span></label>
              <input type="text" name="title" class="cf-input" maxlength="255">
            </div>

            <div class="cf-form-group">
              <label class="cf-label">Omschrijving <span style="color:var(--text-dim);font-weight:400;">— optioneel</span></label>
              <textarea name="description" class="cf-input" rows="2"></textarea>
            </div>

            <button type="submit" class="cf-btn">Uploaden</button>
          </form>
        </div>
      </div>

      <div class="cf-card">
        <h2 style="margin-top:0;">Inhoud (<?= count($items) ?>)</h2>
        <?php if (empty($items)): ?>
          <div class="cf-table-empty">Nog geen foto's of video's in dit album.</div>
        <?php else: ?>
          <div class="cf-gallery-admin-grid">
            <?php foreach ($items as $item): ?>
              <div class="cf-gallery-admin-item">
                <?php if ($item['media_type'] === 'image' && !empty($item['thumbnail_path'])): ?>
                  <img src="/media/<?= htmlspecialchars($item['thumbnail_path']) ?>" alt="<?= htmlspecialchars($item['title'] ?? $item['original_filename']) ?>" loading="lazy">
                <?php else: ?>
                  <div class="cf-gallery-video-placeholder">▶️</div>
                <?php endif; ?>
                <div class="cf-gallery-item-meta" title="<?= htmlspecialchars($item['title'] ?? $item['original_filename']) ?>">
                  <?= htmlspecialchars($item['title'] ?: $item['original_filename']) ?>
                </div>
                <form method="post" action="/admin/gallery/items/<?= (int) $item['id'] ?>/verwijder"
                      onsubmit="return confirm('Dit item definitief verwijderen?');">
                  <?= CsrfProtection::field() ?>
                  <button type="submit" class="cf-btn-sm" style="color:#f87171;border-color:rgba(248,113,113,.4);">🗑️ Verwijder</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
