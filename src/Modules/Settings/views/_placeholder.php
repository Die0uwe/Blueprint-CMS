<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
//
// Gedeelde "nog niet gebouwd"-pagina (Wave 2). De admin-sidebar linkte al
// sinds Sprint 2 naar /admin/media, /admin/users, /admin/roles, /admin/themes,
// /admin/menus en /admin/logs — geen van die schermen bestond, dus elke klik
// gaf een kale 404. Dat is geen best-effort winst t.o.v. eerlijk laten zien
// dat het scherm er nog niet is: deze partial doet dat, ingelogd en
// permissie-gated (i.p.v. een publieke 404 die niets zegt over waarom).
//
// Verwacht: $placeholderIcon, $placeholderTitle, $placeholderNote (strings).
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav ??= '';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\CommunityFusion\Core\I18n\Trans::locale(), ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($placeholderTitle) ?> — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1><?= $placeholderIcon ?> <?= htmlspecialchars($placeholderTitle) ?></h1>
    </header>

    <div class="admin-content">
      <div class="cf-card" style="max-width:640px;">
        <div class="cf-card-body" style="text-align:center;padding:3rem 2rem;">
          <div style="font-size:2.5rem;margin-bottom:1rem;"><?= $placeholderIcon ?></div>
          <h2 style="font-size:1.1rem;margin-bottom:.6rem;">Dit scherm bestaat nog niet</h2>
          <p style="color:var(--text-dim);font-size:.9rem;margin-bottom:1.2rem;">
            <?= htmlspecialchars($placeholderNote) ?>
          </p>
          <a href="/admin" class="cf-btn-ghost">← Terug naar dashboard</a>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
