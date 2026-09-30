<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================
// Beschikbaar vanuit EditorController::edit(): $type, $id, $title, $initial, $config, $csrf, $backUrl, $isBlog, $canPhp

$activeNav = $type === 'news' ? 'news' : ($type === 'page' ? 'pages' : '');
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $e(\CommunityFusion\Core\I18n\Trans::locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Editor — <?= $e($title) ?> — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<link rel="stylesheet" href="/assets/css/admin-editor.css">
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>✍️ Editor — <?= $e($title) ?></h1>
      <a href="<?= $e($backUrl) ?>" class="cf-btn-sm">← Terug</a>
    </header>

    <div class="admin-content ed-content">
      <div id="ed-draft" class="cf-alert cf-alert-warning" hidden>
        Er is een niet-opgeslagen concept gevonden<span id="ed-draft-at"></span>.
        <button type="button" class="cf-btn-sm" id="ed-draft-restore">Herstel concept</button>
        <button type="button" class="cf-btn-sm" id="ed-draft-discard">Verwerp</button>
      </div>

      <div class="ed-toolbar" role="toolbar" aria-label="Opmaak">
        <?php if (!$isBlog): ?>
        <span class="ed-group" role="group" aria-label="Weergave van de bron">
          <button type="button" class="ed-btn is-active" id="ed-mode-markup" aria-pressed="true" title="Met kleurmarkering voor HTML, PHP en Twig">Markup</button>
          <button type="button" class="ed-btn" id="ed-mode-source" aria-pressed="false" title="Puur tekst, zonder kleuren">Bron</button>
        </span>
        <span class="ed-group" id="ed-buttons" role="group" aria-label="Invoegen"></span>
        <?php else: ?>
        <span class="ed-hint">Blog-berichten zijn platte tekst: HTML wordt niet uitgevoerd.</span>
        <?php endif; ?>
        <span class="ed-spacer"></span>
        <?php if (!$isBlog): ?>
        <label class="ed-check"><input type="checkbox" id="ed-preview-toggle" checked> Preview</label>
        <?php endif; ?>
        <span id="ed-status" class="ed-status" role="status" aria-live="polite"></span>
        <button type="button" class="cf-btn" id="ed-save" title="Opslaan (Ctrl+S)">Opslaan</button>
      </div>

      <div class="ed-panes<?= $isBlog ? ' no-preview' : '' ?>" id="ed-panes">
        <div class="ed-editor" id="ed-editor">
          <pre id="ed-overlay" class="ed-overlay" aria-hidden="true"></pre>
          <textarea id="ed-text" class="ed-text" spellcheck="false" wrap="off" autocomplete="off" aria-label="Inhoud"><?= "\n" . $e($initial) ?></textarea>
        </div>
        <?php if (!$isBlog): ?>
        <div class="ed-preview" id="ed-preview-pane">
          <iframe id="ed-preview" title="Preview" sandbox="" referrerpolicy="no-referrer"></iframe>
          <div id="ed-error" class="cf-alert cf-alert-error" hidden></div>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!$isBlog): ?>
      <p class="ed-note">
        De bron (HTML en Twig) wordt bewaard; de pagina toont het gerenderde resultaat.
        Twig draait in een sandbox met een vaste lijst tags en filters en ziet alleen <code>title</code> en <code>today</code>.
        <?php if ($canPhp): ?>
          <strong>PHP-tags worden bewaard als tekst en nooit uitgevoerd.</strong>
        <?php else: ?>
          PHP-tags zijn voor jouw rol niet toegestaan.
        <?php endif; ?>
      </p>
      <?php endif; ?>
    </div>
  </div>
</div>
<input type="hidden" id="ed-csrf" value="<?= $e($csrf) ?>">
<script type="application/json" id="ed-config"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script type="module" src="/assets/js/admin/editor.js"></script>
</body>
</html>
