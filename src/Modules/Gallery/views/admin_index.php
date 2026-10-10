<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $albums (boomvolgorde, met depth), $duplicates, $taxonomy, $mainSlugs, $flash, $error beschikbaar vanuit GalleryAdminController::index()

use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'gallery';
$flashLabels = ['aangemaakt' => 'aangemaakt', 'bijgewerkt' => 'bijgewerkt', 'verwijderd' => 'verwijderd'];
$dedupeMsg   = ($flash ?? null) === 'ontdubbeld'
    ? ((int) ($_GET['n'] ?? 0)) . ' groep(en) dubbele albums samengevoegd, ' . ((int) ($_GET['s'] ?? 0)) . ' hoofdcategorie(ën) aangemaakt.'
    : null;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\CommunityFusion\Core\I18n\Trans::locale(), ENT_QUOTES) ?>">
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
      <div class="cf-table-actions">
        <a href="/admin/gallery/export.json" class="cf-btn-sm" target="_blank">⬇️ JSON-export</a>
        <a href="/admin/gallery/nieuw" class="cf-btn-sm">+ Nieuw album</a>
      </div>
    </header>

    <div class="admin-content">
      <?php if ($dedupeMsg): ?>
        <div class="cf-alert cf-alert-success"><?= htmlspecialchars($dedupeMsg) ?></div>
      <?php endif; ?>
      <?php if (!$taxonomy): ?>
        <div class="cf-alert cf-alert-error">
          Stijl-tags en tags zijn nog niet actief: draai <code>php cli/console.php migrate</code> of plak
          <code>database/sql/20261010_gallery_taxonomy.sql</code> in phpMyAdmin. De galerij werkt intussen gewoon door.
        </div>
      <?php endif; ?>
      <?php if (!empty($duplicates)): ?>
        <div class="cf-alert cf-alert-error">
          <strong>Dubbele albums gevonden:</strong>
          <ul style="margin:.4rem 0 .6rem 1.2rem;">
            <?php foreach ($duplicates as $group): ?>
              <li><?= htmlspecialchars(implode('  =  ', array_map(static fn($a) => $a['name'] . ' (' . $a['item_count'] . ')', $group))) ?></li>
            <?php endforeach; ?>
          </ul>
          <form method="post" action="/admin/gallery/ontdubbel" style="display:inline;"
                onsubmit="return confirm('Dubbele albums samenvoegen? Items verhuizen naar het oudste album; de rest verdwijnt.');">
            <?= CsrfProtection::field() ?>
            <button type="submit" class="cf-btn-sm">🧹 Automatisch samenvoegen</button>
          </form>
        </div>
      <?php else: ?>
        <form method="post" action="/admin/gallery/ontdubbel" style="margin-bottom:1rem;">
          <?= CsrfProtection::field() ?>
          <button type="submit" class="cf-btn-ghost">🗂️ Standaard hoofdcategorieën aanvullen</button>
          <span style="color:var(--text-dim);font-size:.78rem;">3D-Art, Digital-Paintings, Illustrations, Photorealistic, UI-Graphics — ontbrekende worden toegevoegd.</span>
        </form>
      <?php endif; ?>
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
                    <?php if ((int) ($a['depth'] ?? 0) === 1): ?><span style="color:var(--text-dim);">↳ </span><?php endif; ?>
                    <strong><?= htmlspecialchars($a['name']) ?></strong>
                    <?php if (in_array($a['slug'], $mainSlugs, true) && (int) ($a['depth'] ?? 0) === 0): ?>
                      <span class="cf-badge" title="Standaard hoofdcategorie">hoofd</span>
                    <?php endif; ?>
                    <?php if ((int) ($a['subalbum_count'] ?? 0) > 0): ?>
                      <span style="color:var(--text-dim);font-size:.75rem;"> · <?= (int) $a['subalbum_count'] ?> subalbum(s)</span>
                    <?php endif; ?>
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
                      <?php if ((int) $a['item_count'] === 0 && (int) ($a['subalbum_count'] ?? 0) === 0): ?>
                        <form method="post" action="/admin/gallery/<?= (int) $a['id'] ?>/verwijder" style="display:inline;"
                              onsubmit="return this.dataset.confirmed==='1' || (this.dataset.confirmed='1', document.getElementById('confirm-<?= (int) $a['id'] ?>').style.display='inline', false);">
                          <?= CsrfProtection::field() ?>
                          <button type="submit" class="cf-btn-sm cf-btn-danger">🗑️ Verwijder</button>
                          <span id="confirm-<?= (int) $a['id'] ?>" style="display:none;color:var(--text-dim);font-size:.75rem;">Klik nogmaals om te bevestigen</span>
                        </form>
                      <?php else: ?>
                        <span class="cf-btn-sm" style="opacity:.5;cursor:not-allowed;" title="Bevat nog foto's/video's of subalbums">🗑️ Verwijder</span>
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
