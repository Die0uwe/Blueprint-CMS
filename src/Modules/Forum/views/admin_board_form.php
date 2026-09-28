<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $board (array|null), $error beschikbaar vanuit BoardAdminController::createForm()/editForm()
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'forum';
$isEdit    = $board !== null;
$actionUrl = $isEdit ? '/admin/forum/boards/' . (int) $board['id'] . '/bewerk' : '/admin/forum/boards';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isEdit ? 'Bord bewerken' : 'Nieuw bord' ?> — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>.form-wrap { max-width: 640px; }</style>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>💬 <?= $isEdit ? 'Bord bewerken: ' . htmlspecialchars($board['name']) : 'Nieuw forumbord' ?></h1>
      <a href="/admin/forum/boards" class="cf-btn-sm">← Terug naar overzicht</a>
    </header>

    <div class="admin-content">
      <div class="form-wrap">
        <?php if ($error): ?>
          <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars($actionUrl) ?>">
          <?= CsrfProtection::field() ?>

          <div class="cf-form-group">
            <label class="cf-label">Naam</label>
            <input type="text" name="name" class="cf-input" required maxlength="200"
                   value="<?= htmlspecialchars($board['name'] ?? '') ?>" placeholder="Bijv. Algemeen, Aankondigingen, Guild-recrutering">
          </div>

          <div class="cf-form-group">
            <label class="cf-label">Slug (URL) <span style="color:var(--text-dim);font-weight:400;">— leeg laten om automatisch te genereren</span></label>
            <input type="text" name="slug" class="cf-input" maxlength="150"
                   value="<?= htmlspecialchars($board['slug'] ?? '') ?>" placeholder="algemeen">
            <?php if ($isEdit): ?>
              <p style="color:var(--text-dim);font-size:.78rem;margin:.35rem 0 0;">
                Let op: de slug wijzigen breekt bestaande links naar dit bord (<code>/forum/<?= htmlspecialchars($board['slug']) ?></code>).
              </p>
            <?php endif; ?>
          </div>

          <div class="cf-form-group">
            <label class="cf-label">Omschrijving</label>
            <textarea name="description" class="cf-input" rows="3"
                      placeholder="Korte uitleg die op de forumindex verschijnt"><?= htmlspecialchars($board['description'] ?? '') ?></textarea>
          </div>

          <div class="cf-form-group">
            <label class="cf-label">Positie <span style="color:var(--text-dim);font-weight:400;">— lager = hoger in de lijst</span></label>
            <input type="number" name="position" class="cf-input" style="max-width:120px;"
                   value="<?= (int) ($board['position'] ?? 0) ?>">
          </div>

          <button type="submit" class="cf-btn"><?= $isEdit ? 'Wijzigingen opslaan' : 'Bord aanmaken' ?></button>
          <a href="/admin/forum/boards" class="cf-btn-ghost">Annuleren</a>
        </form>
      </div>
    </div>
  </div>
</div>
</body>
</html>
