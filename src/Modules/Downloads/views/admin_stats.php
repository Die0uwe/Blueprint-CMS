<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $totals, $perFile, $recent, $perDay komen uit DownloadsAdminController::stats()
use CommunityFusion\Modules\Downloads\DownloadStats;

$activeNav = 'downloads';
$e   = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$max = max(1, max($perDay ?: [0]));
?>
<!DOCTYPE html>
<html lang="<?= $e(\CommunityFusion\Core\I18n\Trans::locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Download-statistieken — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>📊 Download-statistieken</h1>
      <a href="/admin/downloads" class="cf-btn-ghost">← Beheer</a>
    </header>

    <div class="admin-content">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:1rem;margin-bottom:1rem;">
        <?php foreach ([
            ['Totaal downloads', number_format($totals['total'], 0, ',', '.')],
            ['Unieke downloads', number_format($totals['unique'], 0, ',', '.')],
            ['Laatste 30 dagen', number_format($totals['last30'], 0, ',', '.')],
            ['Bandbreedte totaal', DownloadStats::formatBytes($totals['bytes'])],
            ['Bandbreedte 30 dagen', DownloadStats::formatBytes($totals['last30_bytes'])],
            ['Bestanden', number_format($totals['files'], 0, ',', '.')],
        ] as [$label, $value]): ?>
          <div class="cf-card"><div class="cf-card-body">
            <div style="color:var(--text-dim);font-size:.8rem;"><?= $e($label) ?></div>
            <div style="font-size:1.5rem;font-weight:700;"><?= $e($value) ?></div>
          </div></div>
        <?php endforeach; ?>
      </div>

      <div class="cf-card" style="margin-bottom:1rem;">
        <div class="cf-card-body">
          <strong>Downloads per dag (30 dagen)</strong>
          <div style="display:flex;align-items:flex-end;gap:2px;height:110px;margin-top:.75rem;" role="img" aria-label="Downloads per dag">
            <?php foreach ($perDay as $day => $count): ?>
              <div title="<?= $e($day . ': ' . $count) ?>"
                   style="flex:1;min-width:2px;background:var(--primary,#a855f7);opacity:<?= $count > 0 ? '1' : '.2' ?>;height:<?= $count > 0 ? max(4, (int) round($count / $max * 100)) : 2 ?>%;"></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="cf-card" style="margin-bottom:1rem;">
        <?php if (empty($perFile)): ?>
          <div class="cf-table-empty">Nog geen downloads gelogd.</div>
        <?php else: ?>
          <table class="cf-table">
            <thead><tr><th>Bestand</th><th>Versie</th><th>Totaal</th><th>Uniek</th><th>Bandbreedte</th><th>Laatst</th></tr></thead>
            <tbody>
            <?php foreach ($perFile as $r): ?>
              <tr>
                <td><strong><?= $e($r['title']) ?></strong></td>
                <td><?= $e($r['version'] ?? '—') ?></td>
                <td><?= (int) $r['total'] ?></td>
                <td><?= (int) $r['uniq'] ?></td>
                <td><?= $e(DownloadStats::formatBytes((int) $r['bytes'])) ?></td>
                <td><?= $e($r['last_at']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <div class="cf-card">
        <div class="cf-card-body"><strong>Recente activiteit</strong></div>
        <?php if (empty($recent)): ?>
          <div class="cf-table-empty">Geen activiteit.</div>
        <?php else: ?>
          <table class="cf-table">
            <thead><tr><th>Tijd</th><th>Bestand</th><th>Versie</th><th>Gebruiker / IP</th><th>Grootte</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
              <tr>
                <td><?= $e($r['created_at']) ?></td>
                <td><?= $e($r['title']) ?></td>
                <td><?= $e($r['version'] ?? '—') ?></td>
                <td><?= $r['username'] !== null ? $e($r['username']) : $e($r['ip_address']) ?></td>
                <td><?= $e(DownloadStats::formatBytes((int) $r['bytes_sent'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
