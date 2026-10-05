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
  .form-wrap.is-full { position:fixed; inset:0; z-index:9990; overflow:auto; max-width:none; margin:0; padding:1rem 1.5rem; background:var(--bg); }
  .blk-preview { margin-top:1.5rem; }
  .blk-preview iframe { width:100%; min-height:220px; border:1px solid var(--border); border-radius:8px; background:#0f172a; resize:vertical; }
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
      <div class="form-wrap" id="blk-wrap">
        <div style="display:flex;justify-content:flex-end;margin-bottom:.5rem;">
          <button type="button" class="cf-btn-sm" id="blk-full" aria-pressed="false">⛶ Volledig scherm</button>
        </div>
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
            <button type="button" class="cf-btn-ghost" id="blk-preview-btn">👁 Voorbeeld</button>
            <a href="/admin/blocks" class="cf-btn-ghost">Annuleren</a>
          </div>
        </form>

        <section class="blk-preview" id="blk-preview" hidden>
          <h3 style="margin:0 0 .5rem;">Voorbeeld <small style="color:var(--muted);font-weight:400;">(niet opgeslagen; scripts worden niet uitgevoerd)</small></h3>
          <p id="blk-preview-err" style="color:var(--error-text);" hidden></p>
          <iframe id="blk-preview-frame" sandbox="" title="Voorbeeld van het blok"></iframe>
        </section>

        <div class="danger-zone">
          <form method="post" action="/admin/blocks/<?= (int) $block['id'] ?>/delete"
                onsubmit="return confirm('Dit blok verwijderen?');">
            <?= CsrfProtection::field() ?>
            <button type="submit" class="cf-btn-sm cf-btn-danger">🗑️ Blok verwijderen</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<?= \CommunityFusion\Core\Template\EditorAssets::tags() ?>
<script>
(function () {
  var form = document.querySelector('form[action$="/update"]');
  var wrap = document.getElementById('blk-wrap');
  var full = document.getElementById('blk-full');
  full.addEventListener('click', function () {
    var on = !wrap.classList.contains('is-full');
    wrap.classList.toggle('is-full', on);
    full.setAttribute('aria-pressed', on ? 'true' : 'false');
    full.textContent = on ? '✕ Sluiten' : '⛶ Volledig scherm';
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && wrap.classList.contains('is-full') && !document.querySelector('.cf-ed-full, .tox-fullscreen')) full.click();
  });
  var btn = document.getElementById('blk-preview-btn');
  btn.addEventListener('click', function () {
    if (window.tinymce) window.tinymce.triggerSave();
    var box = document.getElementById('blk-preview'), err = document.getElementById('blk-preview-err'), fr = document.getElementById('blk-preview-frame');
    box.hidden = false; err.hidden = true; btn.disabled = true;
    fetch(form.getAttribute('action').replace(/\/update$/, '/preview'), {
      method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin'
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok || res.j.error) { err.textContent = res.j.error || 'Voorbeeld mislukt.'; err.hidden = false; fr.srcdoc = ''; return; }
        fr.srcdoc = '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/assets/css/blueprint.css"><body style="background:#0f172a;color:#e2e8f0;font-family:system-ui,sans-serif;padding:1rem">' + res.j.html;
      })
      .catch(function () { err.textContent = 'Voorbeeld mislukt (netwerk).'; err.hidden = false; })
      .then(function () { btn.disabled = false; });
  });
})();
</script>
</body>
</html>
