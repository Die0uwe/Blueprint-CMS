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
      <a href="/admin/themes/instellingen" class="cf-btn-sm">⚙️ Thema-instellingen</a>
    </header>

    <div class="admin-content">
      <?php if ($flash === 'geactiveerd'): ?>
        <div class="cf-alert cf-alert-success">Thema geactiveerd.</div>
      <?php elseif ($flash === 'keuze_aan' || $flash === 'keuze_uit'): ?>
        <div class="cf-alert cf-alert-success">Bezoekerskeuze <?= $flash === 'keuze_aan' ? 'ingeschakeld' : 'uitgeschakeld' ?>.</div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1.25rem;">
        <?php foreach ($themes as $slug => $t): ?>
          <?php $isActive = $slug === $activeTheme; ?>
          <div class="cf-card" style="padding:1.1rem;<?= $isActive ? 'border:2px solid var(--accent);' : '' ?>">
            <?php foreach (['colors' => ($t['mode'] ?? 'dark') === 'light' ? '☀️ Licht' : '🌙 Donker',
                           'colors_alt' => ($t['mode'] ?? 'dark') === 'light' ? '🌙 Donker' : '☀️ Licht'] as $set => $label): ?>
              <?php if (!empty($t[$set])): ?>
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem;">
                  <div style="display:flex;flex:1;border-radius:8px;overflow:hidden;height:30px;border:1px solid var(--border);">
                    <?php foreach (['bg','surface','accent','accent2','gold','text'] as $colorName): ?>
                      <?php if (!empty($t[$set][$colorName]) && preg_match('/^#[0-9a-fA-F]{3,6}$/', $t[$set][$colorName])): ?>
                        <div style="flex:1;background:<?= htmlspecialchars($t[$set][$colorName]) ?>;" title="<?= htmlspecialchars($colorName) ?>"></div>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                  <span style="font-size:.72rem;color:var(--text-dim);white-space:nowrap;"><?= $label ?></span>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
            <div style="height:.4rem;"></div>
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

      <div class="cf-card" style="padding:1.1rem;margin-top:1.5rem;max-width:60ch;">
        <form method="post" action="/admin/themes/bezoekerskeuze" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
          <?= CsrfProtection::field() ?>
          <input type="hidden" name="visitor_theme_choice" value="<?= $visitorChoice ? '0' : '1' ?>">
          <div style="flex:1;min-width:220px;">
            <strong>Bezoekers mogen zelf kiezen</strong>
            <p style="color:var(--text-dim);font-size:.8rem;margin:.25rem 0 0;">
              Toont een 🎨-knop in de header waarmee bezoekers een thema en licht/donker kiezen
              (onthouden in hun eigen browser). Nu: <strong><?= $visitorChoice ? 'aan' : 'uit' ?></strong>.
            </p>
          </div>
          <button type="submit" class="cf-btn-sm"><?= $visitorChoice ? 'Uitschakelen' : 'Inschakelen' ?></button>
        </form>
      </div>
      <p style="color:var(--text-dim);font-size:.78rem;margin-top:1rem;max-width:60ch;">
        Het actieve thema is de standaard voor nieuwe bezoekers. Elk thema heeft een lichte en een donkere
        variant (zie <code>colors</code> en <code>colors_alt</code> in <code>theme.json</code>).
      </p>
    </div>
  </div>
</div>
</body>
</html>
