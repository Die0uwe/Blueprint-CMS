<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $slug (string, uit de querystring), $type (BlockInterface) — vanuit
// BlockController::create().
//
// Golf 10a ontdekte dit gat tijdens het live-testen van de nieuwe YouTube-
// blokken: BlockController::create() deed al sinds het bestaan van deze
// controller `include __DIR__ . '/views/create.php'`, maar dat bestand
// bestond nergens — een lege HTTP 200 op elke aanroep van
// GET /admin/blocks/create?type_slug=... In de praktijk onschadelijk, want
// de ECHTE "blok toevoegen"-flow in views/index.php gebruikt een JS-modal
// die rechtstreeks naar POST /admin/blocks/store post en deze GET-route
// nooit aanroept — vandaar dat dit nooit eerder opviel. Dit is de
// ontbrekende, simpele non-JS fallback: hetzelfde formulier als de modal,
// nu ook bruikbaar zonder JavaScript of als directe link.
use CommunityFusion\Core\Security\CsrfProtection;

$zones = [
    'header'        => 'Header',
    'topmenu'       => 'Top Menu',
    'sidebar_left'  => 'Linker Sidebar',
    'content'       => 'Content',
    'sidebar_right' => 'Rechter Sidebar',
    'footer'        => 'Footer',
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\CommunityFusion\Core\I18n\Trans::locale(), ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Blok toevoegen: <?= htmlspecialchars($type->getName()) ?> — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>.form-wrap { max-width: 720px; margin: 2rem auto; }</style>
</head>
<body>
<div class="admin-wrap">
  <?php $activeNav = 'blocks'; include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>🧩 Blok toevoegen: <?= htmlspecialchars($type->getName()) ?></h1>
      <a href="/admin/blocks" class="cf-btn-sm">← Terug naar blokken</a>
    </header>

    <div class="admin-content">
      <div class="form-wrap">
        <form method="post" action="/admin/blocks/store">
          <?= CsrfProtection::field() ?>
          <input type="hidden" name="type_slug" value="<?= htmlspecialchars($slug, ENT_QUOTES) ?>">

          <div class="cf-form-group">
            <label class="cf-label">Titel (optioneel)</label>
            <input type="text" name="title" class="cf-input" maxlength="200">
          </div>

          <div class="cf-form-group">
            <label class="cf-label">Zone</label>
            <select name="zone" class="cf-input">
              <?php foreach ($zones as $zoneSlug => $zoneLabel): ?>
                <option value="<?= htmlspecialchars($zoneSlug, ENT_QUOTES) ?>"><?= htmlspecialchars($zoneLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php $schema = $type->getConfigSchema(); $values = []; include __DIR__ . '/_config_fields.php'; ?>

          <button type="submit" class="cf-btn">Blok toevoegen</button>
          <a href="/admin/blocks" class="cf-btn-ghost">Annuleren</a>
        </form>
      </div>
    </div>
  </div>
</div>
</body>
</html>
<?php
// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: create.php | Role: View | Version: 1.0.0                     ║
// ║  Created: 2026-09-29 | Status: New — Golf 10a (ontbrak volledig)    ║
// ╚══════════════════════════════════════════════════════════════════════╝
