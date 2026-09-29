<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $category (array|null), $categories, $error beschikbaar vanuit
// CategoryAdminController::createForm()/editForm()
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'news';
$isEdit    = $category !== null;
$actionUrl = $isEdit ? '/admin/news/categories/' . (int) $category['id'] . '/bewerk' : '/admin/news/categories';
$selfId    = $isEdit ? (int) $category['id'] : null;
$currentParentId = isset($category['parent_id']) && $category['parent_id'] !== null ? (int) $category['parent_id'] : null;
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isEdit ? 'Categorie bewerken' : 'Nieuwe categorie' ?> — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>.form-wrap { max-width: 640px; }</style>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>🗂️ <?= $isEdit ? 'Categorie bewerken: ' . htmlspecialchars($category['name']) : 'Nieuwe nieuwscategorie' ?></h1>
      <a href="/admin/news/categories" class="cf-btn-sm">← Terug naar overzicht</a>
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
                   value="<?= htmlspecialchars($category['name'] ?? '') ?>" placeholder="Bijv. Aankondigingen, Patch Notes, Events">
          </div>

          <div class="cf-form-group">
            <label class="cf-label">Slug (URL) <span style="color:var(--text-dim);font-weight:400;">— leeg laten om automatisch te genereren</span></label>
            <input type="text" name="slug" class="cf-input" maxlength="150"
                   value="<?= htmlspecialchars($category['slug'] ?? '') ?>" placeholder="aankondigingen">
            <?php if ($isEdit): ?>
              <p style="color:var(--text-dim);font-size:.78rem;margin:.35rem 0 0;">
                Let op: de slug wijzigen breekt bestaande links naar deze categorie.
              </p>
            <?php endif; ?>
          </div>

          <div class="cf-form-group">
            <label class="cf-label">Omschrijving</label>
            <textarea name="description" class="cf-input" rows="3"
                      placeholder="Korte uitleg over deze categorie"><?= htmlspecialchars($category['description'] ?? '') ?></textarea>
          </div>

          <div class="cf-form-group">
            <label class="cf-label">Bovenliggende categorie <span style="color:var(--text-dim);font-weight:400;">— optioneel</span></label>
            <select name="parent_id" class="cf-select">
              <option value="">— Geen (hoofdcategorie) —</option>
              <?php foreach ($categories as $opt): ?>
                <?php
                  $optId = (int) $opt['id'];
                  if ($selfId !== null && $optId === $selfId) continue; // geen self-parent
                ?>
                <option value="<?= $optId ?>" <?= $currentParentId === $optId ? 'selected' : '' ?>>
                  <?= htmlspecialchars($opt['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="cf-form-group">
            <label class="cf-label">Positie <span style="color:var(--text-dim);font-weight:400;">— lager = hoger in de lijst</span></label>
            <input type="number" name="position" class="cf-input" style="max-width:120px;"
                   value="<?= (int) ($category['position'] ?? 0) ?>">
          </div>

          <button type="submit" class="cf-btn"><?= $isEdit ? 'Wijzigingen opslaan' : 'Categorie aanmaken' ?></button>
          <a href="/admin/news/categories" class="cf-btn-ghost">Annuleren</a>
        </form>
      </div>
    </div>
  </div>
</div>
</body>
</html>
