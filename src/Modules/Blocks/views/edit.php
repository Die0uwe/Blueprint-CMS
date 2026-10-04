<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// Blok bewerken. Vanuit BlockController::edit():
//   $block (array, DB-rij incl. type_slug), $type (BlockInterface),
//   $schema (array), $values (array, huidige config), $zones (array), $saved (bool)
use CommunityFusion\Core\Security\CsrfProtection;

$h = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $h(\CommunityFusion\Core\I18n\Trans::locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Blok bewerken: <?= $h($type->getName()) ?> — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>
  .form-wrap { max-width: 820px; margin: 1.5rem auto; }
  .form-actions { display:flex; gap:.8rem; align-items:center; margin-top:1.5rem; }
  .saved-note { background: rgba(16,185,129,.12); border:1px solid var(--success); color: var(--success);
                padding:.6rem .9rem; border-radius:8px; margin-bottom:1rem; }
  .danger-zone { margin-top:2.5rem; padding-top:1rem; border-top:1px solid var(--border); }
</style>
</head>
<body>
<div class="admin-wrap">
  <?php $activeNav = 'blocks'; include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>✏️ Blok bewerken: <?= $h($type->getName()) ?></h1>
      <a href="/admin/blocks" class="cf-btn-sm">← Terug naar blokken</a>
    </header>

    <div class="admin-content">
      <div class="form-wrap">
        <?php if (!empty($saved)): ?>
          <div class="saved-note">✅ Blok opgeslagen.</div>
        <?php endif; ?>

        <form method="post" action="/admin/blocks/<?= (int) $block['id'] ?>/update">
          <?= CsrfProtection::field() ?>

          <div class="cf-form-group">
            <label class="cf-label" for="blk_title">Titel (optioneel)</label>
            <input type="text" id="blk_title" name="title" class="cf-input" maxlength="200"
                   value="<?= $h($block['title'] ?? '') ?>">
          </div>

          <div class="cf-form-group">
            <label class="cf-label" for="blk_zone">Zone</label>
            <select id="blk_zone" name="zone" class="cf-select">
              <?php foreach ($zones as $zoneSlug => $zoneLabel): ?>
                <option value="<?= $h($zoneSlug) ?>" <?= $zoneSlug === $block['zone'] ? 'selected' : '' ?>><?= $h($zoneLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="cf-form-group">
            <input type="hidden" name="is_visible" value="0">
            <label class="cf-label" style="display:flex;align-items:center;gap:.5rem;font-weight:400;">
              <input type="checkbox" name="is_visible" value="1" style="width:auto;"
                     <?= !empty($block['is_visible']) ? 'checked' : '' ?>>
              Zichtbaar op de site
            </label>
          </div>

          <?php if ($schema === []): ?>
            <p style="color:var(--muted);">Dit bloktype heeft geen extra instellingen.</p>
          <?php else: ?>
            <?php include __DIR__ . '/_config_fields.php'; ?>
          <?php endif; ?>

          <div class="form-actions">
            <button type="submit" class="cf-btn">Opslaan</button>
            <a href="/admin/blocks" class="cf-btn-ghost">Annuleren</a>
          </div>
        </form>

        <div class="danger-zone">
          <form method="post" action="/admin/blocks/<?= (int) $block['id'] ?>/delete"
                onsubmit="return confirm('Dit blok verwijderen?');">
            <?= CsrfProtection::field() ?>
            <button type="submit" class="cf-btn-sm" style="color:var(--error);">🗑️ Blok verwijderen</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
