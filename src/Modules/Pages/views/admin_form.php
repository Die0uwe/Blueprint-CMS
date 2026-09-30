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
      <?php if ($isEdit): ?><a href="/admin/editor/page/<?= (int) $item['id'] ?>" class="cf-btn-sm">✍️ Open in editor</a><?php endif; ?>
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
            <textarea id="content" name="content" class="cf-textarea" required style="min-height:280px;"><?= htmlspecialchars((string) ($item['content'] ?? '')) ?></textarea>
          </div>

          <div class="cf-form-group">
            <label class="cf-label" for="template">Template</label>
            <select id="template" name="template" class="cf-select">
              <?php $currentTemplate = $item['template'] ?? 'default'; ?>
              <option value="default" <?= $currentTemplate === 'default' ? 'selected' : '' ?>>Standaard</option>
              <option value="full"    <?= $currentTemplate === 'full' ? 'selected' : '' ?>>Volledige breedte</option>
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
</body>
</html>
