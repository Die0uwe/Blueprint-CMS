<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $item (array|null) en $error (string|null) zijn beschikbaar vanuit
// PageController::createForm() / editForm()
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'pages';
$isEdit    = $item !== null;
$action    = $isEdit ? '/admin/pages/' . (int) $item['id'] . '/bewerk' : '/admin/pages';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\CommunityFusion\Core\I18n\Trans::locale(), ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isEdit ? 'Pagina bewerken' : 'Nieuwe pagina' ?> — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>
.form-wrap { max-width: 720px; }
.pv-bar { display:flex; gap:.5rem; flex-wrap:wrap; margin:.6rem 0; }
.pv-box { margin-top:.5rem; }
.pv-box iframe { display:block; width:100%; height:520px; border:1px solid var(--border); border-radius:8px; background:#fff; resize:vertical; }
.pv-box.is-full { position:fixed; inset:0; z-index:9999; margin:0; background:var(--bg,#0f172a); }
.pv-box.is-full iframe { height:100%; border:0; border-radius:0; resize:none; }
.pv-box .pv-close { display:none; }
.pv-box.is-full .pv-close { display:block; position:fixed; top:.6rem; right:.6rem; z-index:10000; }
</style>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1><?= $isEdit ? '✏️ Pagina bewerken' : '📄 Nieuwe pagina' ?></h1>
      <a href="/admin/pages" class="cf-btn-sm">← Terug naar overzicht</a>
    </header>

    <div class="admin-content">
      <div class="form-wrap">
        <?php if ($error): ?>
          <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($isEdit): ?>
          <div class="cf-alert cf-alert-warning" style="font-size:.8rem;">
            De URL (<code>/<?= htmlspecialchars($item['slug']) ?></code>) blijft ongewijzigd bij het bewerken.
          </div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES) ?>">
          <?= CsrfProtection::field() ?>

          <div class="cf-form-group">
            <label class="cf-label" for="title">Titel</label>
            <input type="text" id="title" name="title" class="cf-input" required maxlength="300"
                   value="<?= htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES) ?>">
          </div>

          <div class="cf-form-group">
            <label class="cf-label" for="template">Template <span style="font-weight:400;text-transform:none;color:var(--text-dim);">(kies dit eerst: bij “HTML-pagina” gaat de tekstverwerker uit)</span></label>
            <select id="template" name="template" class="cf-select">
              <?php $currentTemplate = $item['template'] ?? (in_array($_GET['template'] ?? '', ['html', 'html-theme'], true) ? $_GET['template'] : 'default'); ?>
              <option value="default" <?= $currentTemplate === 'default' ? 'selected' : '' ?>>Standaard</option>
              <option value="full"    <?= $currentTemplate === 'full' ? 'selected' : '' ?>>Volledige breedte</option>
              <option value="html"    <?= $currentTemplate === 'html' ? 'selected' : '' ?>>HTML-pagina (eigen code, afgeschermd)</option>
              <option value="html-theme" <?= $currentTemplate === 'html-theme' ? 'selected' : '' ?>>HTML-pagina in thema-stijl (eigen CSS overschreven)</option>
            </select>
          </div>

          <div class="cf-form-group">
            <label class="cf-label" for="content">Inhoud (HTML toegestaan)</label>
            <?php $isHtmlPage = in_array($item['template'] ?? ($_GET['template'] ?? 'default'), ['html', 'html-theme'], true); ?>
            <p id="html-hint" class="cf-hint" style="color:var(--text-dim);font-size:.85rem;margin:.2rem 0 .5rem;<?= $isHtmlPage ? '' : 'display:none;' ?>">
              HTML-pagina: plak hier een complete pagina (met &lt;html&gt;, &lt;style&gt;, &lt;script&gt;). Die wordt niet opgeschoond en komt
              afgeschermd in een kader op de pagina te staan, zodat hij de rest van de site niet verstoort.
            </p>
            <textarea id="content" name="content" class="cf-textarea" data-editor="<?= $isHtmlPage ? 'code' : 'richtext' ?>" required style="min-height:280px;"><?= htmlspecialchars((string) ($item['content'] ?? '')) ?></textarea>
            <div class="pv-bar">
              <button type="button" class="cf-btn-ghost" id="pv-btn">👁 Voorbeeld</button>
              <button type="button" class="cf-btn-ghost" id="pv-full">⛶ Voorbeeld volledig scherm</button>
            </div>
            <div class="pv-box" id="pv-box" hidden>
              <button type="button" class="cf-btn-ghost pv-close" id="pv-close">✕ Sluiten (Esc)</button>
              <iframe id="pv-frame" sandbox="allow-scripts allow-forms allow-popups" title="Voorbeeld van de pagina"></iframe>
            </div>
          </div>


          <div class="cf-form-group">
            <label class="cf-label" for="menu_position">Positie in menu (leeg = niet in menu tonen)</label>
            <input type="number" id="menu_position" name="menu_position" class="cf-input" min="0" step="1"
                   value="<?= htmlspecialchars((string) ($item['menu_position'] ?? ''), ENT_QUOTES) ?>">
          </div>

          <div class="cf-form-group">
            <label class="cf-label" for="meta_title">SEO — Meta titel (optioneel)</label>
            <input type="text" id="meta_title" name="meta_title" class="cf-input" maxlength="200"
                   value="<?= htmlspecialchars((string) ($item['meta_title'] ?? ''), ENT_QUOTES) ?>">
          </div>

          <div class="cf-form-group">
            <label class="cf-label" for="meta_desc">SEO — Meta omschrijving (optioneel)</label>
            <textarea id="meta_desc" name="meta_desc" class="cf-textarea" style="min-height:70px;" maxlength="400"><?= htmlspecialchars((string) ($item['meta_desc'] ?? '')) ?></textarea>
          </div>

          <div class="cf-form-group">
            <label class="cf-label" for="status">Status</label>
            <select id="status" name="status" class="cf-select">
              <?php $currentStatus = $item['status'] ?? 'draft'; ?>
              <option value="draft"     <?= $currentStatus === 'draft' ? 'selected' : '' ?>>Concept</option>
              <option value="published" <?= $currentStatus === 'published' ? 'selected' : '' ?>>Gepubliceerd</option>
            </select>
          </div>

          <button type="submit" class="cf-btn"><?= $isEdit ? 'Wijzigingen opslaan' : 'Pagina aanmaken' ?></button>
          <a href="/admin/pages" class="cf-btn-ghost">Annuleren</a>
        </form>
      </div>
    </div>
  </div>
</div>
<?= \CommunityFusion\Core\Template\EditorAssets::tags() ?>
<script>
// Template "HTML-pagina": de tekstverwerker haalt <style>/<script> weg (en herschrijft de rest), dus bij die
// keuze gaat hij uit. De oorspronkelijke tekst wordt bewaard vóórdat de editor hem kan aanpassen.
(function () {
  var sel = document.getElementById('template'), hint = document.getElementById('html-hint'),
      ta = document.getElementById('content');
  if (!sel || !ta) return;
  var orig = ta.value;                       // draait vóór cf-editor.js (DOMContentLoaded)
  sel.addEventListener('change', function () {
    var on = sel.value === 'html' || sel.value === 'html-theme';
    hint.style.display = on ? '' : 'none';
    var ed = window.tinymce && window.tinymce.get('content');
    if (on && ed) {
      var changed = ed.isDirty();
      ed.remove();                           // verwijdert de editor; textarea blijft over
      ta.style.display = '';
      ta.value = changed ? ed.getContent() : orig;
      if (changed) {
        hint.textContent = 'De tekstverwerker had je tekst al opgeschoond: plak je originele HTML hier opnieuw.';
        ta.value = '';
      }
    }
    if (!on && !ed && window.tinymce) {
      hint.textContent = 'Sla de pagina op en open hem opnieuw om de tekstverwerker weer te gebruiken.';
      hint.style.display = '';
    }
  });
})();
</script>
<script>
// Voorbeeld: toont de code zoals de bezoeker hem ziet (HTML-pagina: ruw en afgeschermd, anders met site-stijl).
(function () {
  var ta = document.getElementById('content'), sel = document.getElementById('template'),
      box = document.getElementById('pv-box'), fr = document.getElementById('pv-frame');
  if (!ta || !box) return;
  function render() {
    if (window.tinymce) window.tinymce.triggerSave();
    var html = ta.value;
    fr.srcdoc = (sel.value === 'html' || sel.value === 'html-theme')
      ? html + '\n<base target="_blank">'
      : '<!doctype html><meta charset="utf-8"><base target="_blank"><link rel="stylesheet" href="/assets/css/blueprint.css"><body style="padding:1rem">' + html;
    box.hidden = false;
  }
  function full(on) { box.classList.toggle('is-full', on); document.documentElement.style.overflow = on ? 'hidden' : ''; }
  document.getElementById('pv-btn').addEventListener('click', render);
  document.getElementById('pv-full').addEventListener('click', function () { render(); full(true); });
  document.getElementById('pv-close').addEventListener('click', function () { full(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && box.classList.contains('is-full')) full(false); });
})();
</script>
</body>
</html>
