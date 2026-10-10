<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $rows, $pending, $flash, $error, $count, $warn — vanuit DatabaseUpdateController::index()

use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'database';
$h = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="<?= $h(\CommunityFusion\Core\I18n\Trans::locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Database bijwerken — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>
  <div class="admin-main">
    <header class="admin-topbar"><h1>🗄️ Database bijwerken</h1></header>
    <div class="admin-content">
      <?php if ($flash === 'bijgewerkt'): ?>
        <div class="cf-alert cf-alert-success"><?= (int) $count ?> migratie(s) uitgevoerd. Vooraf is een back-up gemaakt (zie Back-ups).</div>
      <?php elseif ($flash === 'actueel'): ?>
        <div class="cf-alert cf-alert-success">De database is al up-to-date.</div>
      <?php endif; ?>
      <?php if ($warn !== ''): ?><div class="cf-alert cf-alert-error"><?= $h($warn) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="cf-alert cf-alert-error"><?= $h($error) ?></div><?php endif; ?>

      <div class="cf-card" style="padding:1rem;margin-bottom:1rem;">
        <?php if ($pending > 0): ?>
          <p><strong><?= (int) $pending ?></strong> openstaande wijziging(en) na een update. Eén klik voert ze uit — handig als je geen SSH hebt
             (vervangt <code>php cli/console.php migrate</code> en het plakken van SQL in phpMyAdmin). Vooraf wordt automatisch een back-up gemaakt.</p>
          <form method="post" action="/admin/database/bijwerken" onsubmit="return confirm('Database nu bijwerken?');">
            <?= CsrfProtection::field() ?>
            <button type="submit" class="cf-btn">⚙️ Nu bijwerken</button>
          </form>
        <?php else: ?>
          <p>✅ Alles is up-to-date — er zijn geen openstaande database-wijzigingen.</p>
        <?php endif; ?>
      </div>

      <div class="cf-card">
        <table class="cf-table">
          <thead><tr><th>Migratie</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($rows) as $r): ?>
            <tr>
              <td><code><?= $h($r['migration']) ?></code></td>
              <td><?= $r['status'] === 'applied' ? '✅ uitgevoerd' : '⏳ openstaand' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</body>
</html>
