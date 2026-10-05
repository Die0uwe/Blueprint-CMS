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
<style>.form-wrap { max-width: 720px; }</style>
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
            <label class="cf-label" for="content">Inhoud (HTML toegestaan)</label>
            <?php $isHtmlPage = ($item['template'] ?? 'default') === 'html'; ?>
            <p id="html-hint" class="cf-hint" style="color:var(--text-dim);font-size:.85rem;margin:.2rem 0 .5rem;<?= $isHtmlPage ? '' : 'display:none;' ?>">
              HTML-pagina: plak hier een complete pagina (met &lt;html&gt;, &lt;style&gt;, &lt;script&gt;). Die wordt niet opgeschoond en komt
              afgeschermd in een kader op de pagina te staan, zodat hij de rest van de site niet verstoort.
            </p>
            <textarea id="content" name="content" class="cf-textarea" data-editor="<?= $isHtmlPage ? 'code' : 'richtext' ?>" required style="min-height:280px;"><?= htmlspecialchars((string) ($item['content'] ?? '')) ?></textarea>
          </div>

          <div class="cf-form-group">
            <label class="cf-label" for="template">Template</label>
            <select id="template" name="template" class="cf-select">
              <?php $currentTemplate = $item['template'] ?? 'default'; ?>
              <option value="default" <?= $currentTemplate === 'default' ? 'selected' : '' ?>>Standaard</option>
              <option value="full"    <?= $currentTemplate === 'full' ? 'selected' : '' ?>>Volledige breedte</option>
              <option value="html"    <?= $currentTemplate === 'html' ? 'selected' : '' ?>>HTML-pagina (eigen code, afgeschermd)</option>
            </select>
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
// Template "HTML-pagina": de tekstverwerker haalt <style>/<script> weg, dus bij die keuze gaat hij uit.
(function () {
  var sel = document.getElementById('template'), hint = document.getElementById('html-hint');
  if (!sel) return;
  sel.addEventListener('change', function () {
    var on = sel.value === 'html';
    hint.style.display = on ? '' : 'none';
    var ed = window.tinymce && window.tinymce.get('content');
    if (on && ed) { ed.save(); ed.remove(); document.getElementById('content').style.display = ''; }
    if (!on && !ed && window.tinymce) {
      hint.textContent = 'Sla de pagina op en open hem opnieuw om de tekstverwerker weer te gebruiken.';
      hint.style.display = '';
    }
  });
})();
</script>
</body>
</html>
