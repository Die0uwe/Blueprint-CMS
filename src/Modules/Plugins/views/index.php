<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================
// Beschikbaar vanuit PluginAdminController::index(): $items, $flash, $canUpload, $uploadEnabled

use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'plugins';
$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrf = CsrfProtection::getToken();
$badge = ['active' => ['green', 'Actief'], 'inactive' => ['gray', 'Uit'], 'discovered' => ['purple', 'Gevonden'], 'invalid' => ['red', 'Ongeldig'], 'error' => ['red', 'Fout']];
?>
<!DOCTYPE html>
<html lang="<?= $e(\CommunityFusion\Core\I18n\Trans::locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Plugins — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>
  <div class="admin-main">
    <header class="admin-topbar"><h1>🧰 Plugins</h1></header>
    <div class="admin-content">
      <?php if ($flash): ?>
        <div class="cf-alert <?= $flash['type'] === 'ok' ? 'cf-alert-success' : 'cf-alert-error' ?>" role="status"><?= $e($flash['msg']) ?></div>
      <?php endif; ?>

      <p style="color:var(--text-dim);font-size:.85rem;margin-bottom:1rem;">
        Plugins staan in <code>plugins/</code>. Een plugin is PHP-code die met de rechten van de site draait:
        installeer alleen plugins die je vertrouwt. Gegevens van een plugin leven in tabellen <code>cf_plg_{naam}_…</code>.
      </p>

      <div class="cf-card" style="overflow-x:auto;">
        <table class="cf-table" style="width:100%;">
          <thead><tr><th>Plugin</th><th>Versie</th><th>Status</th><th style="text-align:right;">Acties</th></tr></thead>
          <tbody>
          <?php if (!$items): ?>
            <tr><td colspan="4" class="cf-table-empty">Geen plugins gevonden in <code>plugins/</code>.</td></tr>
          <?php endif; ?>
          <?php foreach ($items as $p): [$bc, $bl] = $badge[$p['status']] ?? ['gray', $p['status']]; $slug = $p['slug']; ?>
            <tr>
              <td>
                <strong><?= $e($p['name']) ?></strong> <small style="color:var(--muted);"><?= $e($slug) ?></small>
                <?php if (!empty($p['manifest']['description'])): ?><div style="color:var(--text-dim);font-size:.8rem;"><?= $e($p['manifest']['description']) ?></div><?php endif; ?>
                <?php foreach ($p['errors'] as $err): ?><div style="color:#fca5a5;font-size:.8rem;">⚠ <?= $e($err) ?></div><?php endforeach; ?>
                <?php if ($p['needs_migration']): ?><div style="color:var(--gold);font-size:.8rem;">Openstaande database-migraties</div><?php endif; ?>
              </td>
              <td><?= $e($p['version']) ?></td>
              <td><span class="cf-badge cf-badge-<?= $bc ?>"><?= $e($bl) ?></span></td>
              <td style="text-align:right;white-space:nowrap;">
                <?php if ($p['status'] !== 'invalid'): ?>
                  <?php if ($p['active']): ?>
                    <form method="post" action="/admin/plugins/<?= $e(rawurlencode($slug)) ?>/deactiveren" style="display:inline;"><input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>"><button class="cf-btn-sm">Deactiveren</button></form>
                    <?php if (!empty($p['manifest']['settings'])): ?><a class="cf-btn-sm" href="/admin/plugins/<?= $e(rawurlencode($slug)) ?>/instellingen">Instellingen</a><?php endif; ?>
                  <?php else: ?>
                    <form method="post" action="/admin/plugins/<?= $e(rawurlencode($slug)) ?>/activeren" style="display:inline;"><input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>"><button class="cf-btn-sm">Activeren</button></form>
                  <?php endif; ?>
                  <?php if ($p['needs_migration'] && $p['active']): ?>
                    <form method="post" action="/admin/plugins/<?= $e(rawurlencode($slug)) ?>/migreren" style="display:inline;"><input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>"><button class="cf-btn-sm">Migreren</button></form>
                  <?php endif; ?>
                <?php endif; ?>
                <details style="display:inline-block;text-align:left;">
                  <summary class="cf-btn-sm" style="cursor:pointer;display:inline-block;">Verwijderen…</summary>
                  <form method="post" action="/admin/plugins/<?= $e(rawurlencode($slug)) ?>/verwijderen" style="margin-top:.4rem;">
                    <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
                    <label style="font-size:.8rem;display:block;">Typ <code><?= $e($slug) ?></code> ter bevestiging
                      <input class="cf-input" name="confirm" autocomplete="off" required></label>
                    <label style="font-size:.8rem;display:block;margin:.3rem 0;"><input type="checkbox" name="drop_data" value="1"> Ook de gegevens (<code>cf_plg_…</code>-tabellen) verwijderen</label>
                    <button class="cf-btn-sm">Definitief verwijderen</button>
                  </form>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <h2 style="font-size:.9rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-dim);margin:1.5rem 0 .6rem;">ZIP uploaden</h2>
      <?php if ($canUpload): ?>
        <form method="post" action="/admin/plugins/uploaden" enctype="multipart/form-data" class="cf-card" style="padding:1rem;">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
          <input type="file" name="zip" accept=".zip" required>
          <button class="cf-btn" style="margin-left:.6rem;">Uploaden</button>
          <p style="color:var(--muted);font-size:.78rem;margin:.6rem 0 0;">De ZIP wordt gecontroleerd (paden, grootte, bestandstypen) en daarna pas uitgepakt. De plugin blijft uit tot je hem activeert.</p>
        </form>
      <?php else: ?>
        <p style="color:var(--muted);font-size:.85rem;">
          <?= $uploadEnabled ? 'Uploaden is alleen voor de rol super_admin.' : 'Uploaden staat uit. Zet <code>ALLOW_PLUGIN_UPLOAD=true</code> in <code>.env</code> om het voor super_admin aan te zetten, of kopieer de map naar <code>plugins/</code>.' ?>
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
