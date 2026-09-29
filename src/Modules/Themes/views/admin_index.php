<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $themes, $activeTheme, $error, $flash beschikbaar vanuit ThemeAdminController::index()
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'themes';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\CommunityFusion\Core\I18n\Trans::locale(), ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Thema's — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>🎨 Thema's</h1>
    </header>

    <div class="admin-content">
      <?php if ($flash === 'geactiveerd'): ?>
        <div class="cf-alert cf-alert-success">Thema geactiveerd.</div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1.25rem;">
        <?php foreach ($themes as $slug => $t): ?>
          <?php $isActive = $slug === $activeTheme; ?>
          <div class="cf-card" style="padding:1.1rem;<?= $isActive ? 'border:2px solid var(--accent);' : '' ?>">
            <div style="display:flex;gap:.35rem;margin-bottom:.75rem;border-radius:8px;overflow:hidden;height:44px;">
              <?php foreach (($t['colors'] ?? []) as $colorName => $hex): ?>
                <?php if (in_array($colorName, ['bg','accent','accent2','gold','success'], true)): ?>
                  <div style="flex:1;background:<?= htmlspecialchars($hex) ?>;" title="<?= htmlspecialchars($colorName) ?>"></div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
            <strong><?= htmlspecialchars($t['name'] ?? $slug) ?></strong>
            <?php if ($isActive): ?><span class="cf-badge cf-badge-green" style="margin-left:.3rem;">Actief</span><?php endif; ?>
            <p style="color:var(--text-dim);font-size:.82rem;margin:.4rem 0 .8rem;">
              <?= htmlspecialchars($t['description'] ?? '') ?>
            </p>
            <p style="color:var(--text-dim);font-size:.75rem;margin:0 0 .8rem;">
              v<?= htmlspecialchars($t['version'] ?? '?') ?> — <?= htmlspecialchars($t['author'] ?? '?') ?>
              · <code><?= htmlspecialchars($slug) ?></code>
            </p>
            <?php if (!$isActive): ?>
              <form method="post" action="/admin/themes/<?= htmlspecialchars($slug) ?>/activeren">
                <?= CsrfProtection::field() ?>
                <button type="submit" class="cf-btn-sm">Activeren</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <p style="color:var(--text-dim);font-size:.78rem;margin-top:1.5rem;max-width:60ch;">
        Wisselen van thema wisselt de pagina-<em>templates</em> — de kleurenzwatches hierboven komen uit elk
        thema's eigen <code>theme.json</code>, maar worden momenteel nog niet door de templates zelf toegepast
        (die lezen allemaal dezelfde <code>public/assets/css/blueprint.css</code>). Een kleurenschema dat echt
        per thema verandert is een logische vervolgstap.
      </p>
    </div>
  </div>
</div>
</body>
</html>
