<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $menuItems, $otherPages, $error beschikbaar vanuit MenuAdminController::index()
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'menus';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\CommunityFusion\Core\I18n\Trans::locale(), ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sitenavigatie — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>🔗 Sitenavigatie</h1>
    </header>

    <div class="admin-content">
      <?php if ($error): ?>
        <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <p style="color:var(--text-dim);font-size:.85rem;margin-bottom:1.25rem;">
        Dit menu toont alleen <strong>gepubliceerde pagina's</strong> (<code>/admin/pages</code>).
        Nieuws, forum en andere modules krijgen hun eigen navigatie-item los hiervan.
      </p>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
        <div>
          <h2 style="font-size:.9rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-dim);margin-bottom:.6rem;">
            In het menu (<?= count($menuItems) ?>)
          </h2>
          <div class="cf-card">
            <?php if (empty($menuItems)): ?>
              <div class="cf-table-empty">Nog geen pagina's in het menu.</div>
            <?php else: ?>
              <?php foreach ($menuItems as $i => $p): ?>
                <div style="display:flex;align-items:center;gap:.6rem;padding:.7rem 1rem;<?= $i < count($menuItems) - 1 ? 'border-bottom:1px solid var(--border);' : '' ?>">
                  <span style="color:var(--text-dim);font-size:.78rem;min-width:1.5rem;">#<?= (int) $p['menu_position'] ?></span>
                  <span style="flex:1;"><?= htmlspecialchars($p['title']) ?>
                    <span style="color:var(--text-dim);font-size:.78rem;"> — /page/<?= htmlspecialchars($p['slug']) ?></span>
                  </span>
                  <div style="display:flex;gap:.3rem;">
                    <?php if ($i > 0): ?>
                      <form method="post" action="/admin/menus/<?= (int) $p['id'] ?>/omhoog">
                        <?= CsrfProtection::field() ?>
                        <button type="submit" class="cf-btn-sm" title="Omhoog">↑</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($i < count($menuItems) - 1): ?>
                      <form method="post" action="/admin/menus/<?= (int) $p['id'] ?>/omlaag">
                        <?= CsrfProtection::field() ?>
                        <button type="submit" class="cf-btn-sm" title="Omlaag">↓</button>
                      </form>
                    <?php endif; ?>
                    <form method="post" action="/admin/menus/<?= (int) $p['id'] ?>/verwijderen">
                      <?= CsrfProtection::field() ?>
                      <button type="submit" class="cf-btn-sm" title="Uit menu halen">✕</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div>
          <h2 style="font-size:.9rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-dim);margin-bottom:.6rem;">
            Niet in het menu (<?= count($otherPages) ?>)
          </h2>
          <div class="cf-card">
            <?php if (empty($otherPages)): ?>
              <div class="cf-table-empty">Alle gepubliceerde pagina's staan al in het menu.</div>
            <?php else: ?>
              <?php foreach ($otherPages as $i => $p): ?>
                <div style="display:flex;align-items:center;gap:.6rem;padding:.7rem 1rem;<?= $i < count($otherPages) - 1 ? 'border-bottom:1px solid var(--border);' : '' ?>">
                  <span style="flex:1;"><?= htmlspecialchars($p['title']) ?>
                    <span style="color:var(--text-dim);font-size:.78rem;"> — /page/<?= htmlspecialchars($p['slug']) ?></span>
                  </span>
                  <form method="post" action="/admin/menus/<?= (int) $p['id'] ?>/toevoegen">
                    <?= CsrfProtection::field() ?>
                    <button type="submit" class="cf-btn-sm">+ Toevoegen</button>
                  </form>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
