<?php
// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later
// Variabelen (AiStudioController::index): $conversations, $providers, $defaultProvider,
// $csrf, $canAdmin, $assetVersion.
use CommunityFusion\Core\I18n\Trans;
use CommunityFusion\Core\Security\ContentSanitizer as S;

$activeNav = 'ai-studio';
$base = '/admin/ai-studio/assets/';
$v = '?v=' . rawurlencode($assetVersion);
?>
<!DOCTYPE html>
<html lang="<?= S::escape(Trans::locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Studio — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include CF_ROOT . '/src/Modules/Shared/views/admin_styles.php'; ?>
<link rel="stylesheet" href="<?= S::escape($base . 'studio.css' . $v) ?>">
</head>
<body>
<div class="admin-wrap">
  <?php include CF_ROOT . '/src/Modules/Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>🤖 AI Studio</h1>
      <?php if ($canAdmin): ?><a href="/admin/ai-studio/settings" class="cf-btn-sm">⚙️ API-keys</a><?php endif; ?>
    </header>

    <?php if ($providers === []): ?>
      <div class="admin-content">
        <div class="cf-alert">Er is nog geen AI-provider ingesteld.
          <?php if ($canAdmin): ?><a href="/admin/ai-studio/settings">Voeg een API-key toe</a>.<?php else: ?>Vraag een beheerder om een API-key toe te voegen.<?php endif; ?>
        </div>
      </div>
    <?php else: ?>
    <main id="aistudio" class="studio"
          data-csrf="<?= S::escape($csrf) ?>"
          data-url-base="/admin/ai-studio"
          data-default-provider="<?= S::escape($defaultProvider) ?>">

      <aside class="studio-col studio-convs" aria-label="Gesprekken">
        <button type="button" class="cf-btn studio-new" id="studio-new">+ Nieuw gesprek</button>
        <ul class="studio-conv-list" id="studio-conv-list">
          <?php foreach ($conversations as $c): ?>
            <li data-id="<?= (int) $c['id'] ?>">
              <button type="button" class="studio-conv-open"><?= S::escape($c['title']) ?></button>
              <button type="button" class="studio-conv-del" aria-label="Verwijder gesprek" title="Verwijderen">✕</button>
            </li>
          <?php endforeach; ?>
        </ul>
      </aside>

      <?php include __DIR__ . '/partials/chat-pane.php'; ?>
      <?php include __DIR__ . '/partials/editor-pane.php'; ?>
    </main>

    <?php include __DIR__ . '/partials/diff-modal.php'; ?>
    <script type="module" src="<?= S::escape($base . 'studio.js' . $v) ?>"></script>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
