<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $album (altijd null hier — enkel voor nieuw album; bewerken gebeurt in
// admin_album_manage.php), $error beschikbaar vanuit GalleryAdminController::createForm()

use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'gallery';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nieuw album — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>.form-wrap { max-width: 640px; }</style>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>📷 Nieuw album</h1>
      <a href="/admin/gallery" class="cf-btn-sm">← Terug naar overzicht</a>
    </header>

    <div class="admin-content">
      <div class="form-wrap">
        <?php if ($error): ?>
          <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/admin/gallery">
          <?= CsrfProtection::field() ?>

          <div class="cf-form-group">
            <label class="cf-label">Naam</label>
            <input type="text" name="name" class="cf-input" required maxlength="200"
                   placeholder="Bijv. Guild-events, Screenshots seizoen 3">
          </div>

          <div class="cf-form-group">
            <label class="cf-label">Slug (URL) <span style="color:var(--text-dim);font-weight:400;">— leeg laten om automatisch te genereren</span></label>
            <input type="text" name="slug" class="cf-input" maxlength="150" placeholder="guild-events">
          </div>

          <div class="cf-form-group">
            <label class="cf-label">Omschrijving</label>
            <textarea name="description" class="cf-input" rows="3"
                      placeholder="Korte uitleg die op de galerij-index verschijnt"></textarea>
          </div>

          <div class="cf-form-group">
            <label class="cf-label">Positie <span style="color:var(--text-dim);font-weight:400;">— lager = hoger in de lijst</span></label>
            <input type="number" name="position" class="cf-input" style="max-width:120px;" value="0">
          </div>

          <button type="submit" class="cf-btn">Album aanmaken</button>
          <a href="/admin/gallery" class="cf-btn-ghost">Annuleren</a>
        </form>
      </div>
    </div>
  </div>
</div>
</body>
</html>
