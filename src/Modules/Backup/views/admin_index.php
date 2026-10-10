<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// Beschikbaar vanuit BackupAdminController::index():
// $backups, $days, $state, $token, $canRestore, $flash, $error, $detail,
// $weekdays, $uploadLimit, $hasZip, $siteUrl, $nextRun

use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'backup';

$h = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$fmtSize = static function (int $b): string {
    if ($b >= 1073741824) return number_format($b / 1073741824, 2, ',', '.') . ' GB';
    if ($b >= 1048576)    return number_format($b / 1048576, 1, ',', '.') . ' MB';
    return number_format(max(0, $b) / 1024, 0, ',', '.') . ' KB';
};
$typeLabel = ['auto' => 'automatisch', 'manual' => 'handmatig', 'pre-restore' => 'vóór herstel', 'upload' => 'upload'];
$okMsg = [
    'gemaakt'      => 'Back-up gemaakt.',
    'verwijderd'   => 'Back-up verwijderd.',
    'instellingen' => 'Instellingen opgeslagen.',
    'token'        => 'Nieuw cron-token aangemaakt — pas je webcron-URL aan.',
    'hersteld'     => 'Back-up teruggezet. Vlak voor het terugzetten is automatisch een veiligheidsback-up gemaakt' . ($detail !== '' ? ' (' . $detail . ')' : '') . '.',
];
$csrf = CsrfProtection::field();
?>
<!DOCTYPE html>
<html lang="<?= $h(\CommunityFusion\Core\I18n\Trans::locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Back-ups — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>
  .bk-days { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:.75rem; }
  .bk-day { border:1px solid var(--border); border-radius:10px; padding:.8rem; background:var(--surface); display:flex; flex-direction:column; gap:.35rem; }
  .bk-day.empty { opacity:.55; border-style:dashed; }
  .bk-day.today { border-color:var(--accent2); }
  .bk-day h3 { margin:0; font-size:.95rem; }
  .bk-meta { color:var(--text-dim); font-size:.78rem; }
  .bk-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:1rem; margin-top:1rem; }
  .bk-pad { padding:1rem; }
  .bk-row { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; margin:.4rem 0; }
  .bk-url { word-break:break-all; font-size:.8rem; }
  dialog.bk-dlg { border:1px solid var(--border); border-radius:12px; background:var(--surface); color:var(--text); max-width:460px; }
  dialog.bk-dlg::backdrop { background:rgba(0,0,0,.6); }
</style>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar"><h1>💾 Back-ups</h1></header>

    <div class="admin-content">
      <?php if ($flash !== '' && isset($okMsg[$flash])): ?>
        <div class="cf-alert cf-alert-success"><?= $h($okMsg[$flash]) ?></div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="cf-alert cf-alert-error"><?= $h($error) ?></div>
      <?php endif; ?>
      <?php if (!$hasZip): ?>
        <div class="cf-alert cf-alert-error">De PHP-extensie <code>zip</code> ontbreekt — zet die aan in je hostingpaneel om back-ups te kunnen maken.</div>
      <?php endif; ?>
      <?php if ($state['last_error'] !== ''): ?>
        <div class="cf-alert cf-alert-error">Laatste automatische back-up mislukt: <?= $h($state['last_error']) ?></div>
      <?php endif; ?>

      <!-- ───────── Afgelopen 7 dagen ───────── -->
      <div class="cf-card bk-pad">
        <h2 style="margin-top:0;">Afgelopen week</h2>
        <p class="bk-meta">
          <?php if ($state['auto_enabled']): ?>
            Automatische back-up elke dag om <strong><?= $h($state['time']) ?></strong>
            <?php if ($state['last_success'] > 0): ?> · laatst gelukt: <?= $h(date('d-m-Y H:i', (int) $state['last_success'])) ?><?php endif; ?>
          <?php else: ?>
            Automatische back-up staat <strong>uit</strong>.
          <?php endif; ?>
        </p>
        <div class="bk-days">
          <?php foreach ($days as $i => $d): $b = $d['backup']; ?>
            <div class="bk-day <?= $b ? '' : 'empty' ?> <?= $i === 0 ? 'today' : '' ?>">
              <h3><?= $h($weekdays[$d['weekday']]) ?><?= $i === 0 ? ' <span class="cf-badge">vandaag</span>' : '' ?></h3>
              <div class="bk-meta"><?= $h(date('d-m-Y', (int) strtotime($d['date']))) ?></div>
              <?php if ($b): ?>
                <div class="bk-meta"><?= $h($b['time']) ?> · <?= $h($typeLabel[$b['type']] ?? $b['type']) ?><br><?= $h($fmtSize((int) $b['size'])) ?><?= $b['uploads'] ? ' · + bestanden' : '' ?></div>
                <div class="bk-row">
                  <a class="cf-btn-sm" href="/admin/backup/download/<?= $h($b['name']) ?>">⬇️</a>
                  <?php if ($canRestore): ?>
                    <button type="button" class="cf-btn-sm cf-btn-danger" data-restore="<?= $h($b['name']) ?>"
                            data-label="<?= $h($weekdays[$d['weekday']] . ' ' . date('d-m-Y', (int) strtotime($d['date'])) . ' ' . $b['time']) ?>"
                            data-uploads="<?= $b['uploads'] ? '1' : '0' ?>">♻️ Terugzetten</button>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div class="bk-meta">geen back-up</div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="bk-grid">
        <!-- ───────── Nu back-up maken ───────── -->
        <div class="cf-card bk-pad">
          <h2 style="margin-top:0;">Nu een back-up maken</h2>
          <form method="post" action="/admin/backup/maken">
            <?= $csrf ?>
            <label class="bk-row"><input type="checkbox" name="include_uploads" value="1"> Ook geüploade bestanden (foto's, media) meenemen</label>
            <button type="submit" class="cf-btn" <?= $hasZip ? '' : 'disabled' ?>>💾 Back-up maken</button>
            <p class="bk-meta">Bevat altijd de volledige database. Bij veel uploads kan het even duren.</p>
          </form>
        </div>

        <!-- ───────── Planning ───────── -->
        <div class="cf-card bk-pad">
          <h2 style="margin-top:0;">Automatische back-up</h2>
          <form method="post" action="/admin/backup/instellingen">
            <?= $csrf ?>
            <label class="bk-row"><input type="checkbox" name="auto_enabled" value="1" <?= $state['auto_enabled'] ? 'checked' : '' ?>> Dagelijks automatisch back-uppen</label>
            <label class="bk-row">Tijd <input type="time" name="time" value="<?= $h($state['time']) ?>" class="cf-input" style="width:auto;"></label>
            <label class="bk-row"><input type="checkbox" name="include_uploads" value="1" <?= $state['include_uploads'] ? 'checked' : '' ?>> Ook geüploade bestanden meenemen</label>
            <button type="submit" class="cf-btn-sm">Opslaan</button>
            <p class="bk-meta">De laatste 7 dagen blijven bewaard; oudere automatische back-ups worden opgeruimd.</p>
          </form>
        </div>

        <!-- ───────── Terugzetten via upload ───────── -->
        <?php if ($canRestore): ?>
        <div class="cf-card bk-pad">
          <h2 style="margin-top:0;">Terugzetten via upload</h2>
          <form method="post" action="/admin/backup/herstel-upload" enctype="multipart/form-data"
                onsubmit="return confirm('Hiermee wordt de huidige database overschreven. Doorgaan?');">
            <?= $csrf ?>
            <div class="bk-row"><input type="file" name="backup_file" accept=".zip,.sql" required></div>
            <label class="bk-row"><input type="checkbox" name="restore_uploads" value="1"> Ook bestanden terugzetten (als de zip die bevat)</label>
            <label class="bk-row">Typ <strong>HERSTEL</strong>: <input type="text" name="confirm" class="cf-input" style="width:9rem;" autocomplete="off" required></label>
            <button type="submit" class="cf-btn cf-btn-danger">♻️ Uploaden en terugzetten</button>
            <p class="bk-meta">Toegestaan: .zip uit dit scherm of .sql-dump. Uploadlimiet server: <?= $h($uploadLimit) ?>.
              Groter? Zet het bestand via FTP in <code>storage/backups/</code>; het verschijnt dan hieronder in de lijst.</p>
          </form>
        </div>
        <?php endif; ?>

        <!-- ───────── Cron / webcron ───────── -->
        <div class="cf-card bk-pad">
          <h2 style="margin-top:0;">Precies om <?= $h($state['time']) ?> laten draaien</h2>
          <p class="bk-meta">Zonder cron start de back-up bij het eerste bezoek <em>na</em> <?= $h($state['time']) ?>. Wil je het exact op tijd? Gebruik een van deze:</p>
          <p class="bk-meta"><strong>Webcron</strong> (bv. cron-job.org, dagelijks <?= $h($state['time']) ?>):</p>
          <code class="bk-url"><?= $h($siteUrl) ?>/cron/backup/<?= $h($token) ?></code>
          <p class="bk-meta"><strong>Server-cron</strong> (Strato/DirectAdmin):</p>
          <code class="bk-url">php <?= $h(CF_ROOT) ?>/cli/console.php backup:auto</code>
          <form method="post" action="/admin/backup/token" style="margin-top:.6rem;" onsubmit="return confirm('De oude cron-URL werkt daarna niet meer. Doorgaan?');">
            <?= $csrf ?>
            <button type="submit" class="cf-btn-ghost">🔄 Nieuw token</button>
          </form>
        </div>
      </div>

      <!-- ───────── Alle back-ups ───────── -->
      <div class="cf-card" style="margin-top:1rem;">
        <?php if (empty($backups)): ?>
          <div class="cf-table-empty">Nog geen back-ups.</div>
        <?php else: ?>
          <table class="cf-table">
            <thead><tr><th>Datum</th><th>Type</th><th>Grootte</th><th>Inhoud</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($backups as $b): ?>
              <tr>
                <td><?= $h($weekdays[$b['weekday']] ?? '') ?> <?= $h(date('d-m-Y', (int) strtotime($b['date']))) ?> <?= $h($b['time']) ?></td>
                <td><span class="cf-badge"><?= $h($typeLabel[$b['type']] ?? $b['type']) ?></span></td>
                <td><?= $h($fmtSize((int) $b['size'])) ?></td>
                <td class="bk-meta">DB<?= $b['uploads'] ? ' + bestanden' : '' ?><?= $b['cms_version'] ? ' · v' . $h($b['cms_version']) : '' ?></td>
                <td>
                  <div class="cf-table-actions">
                    <a class="cf-btn-sm" href="/admin/backup/download/<?= $h($b['name']) ?>">⬇️ Download</a>
                    <?php if ($canRestore): ?>
                      <button type="button" class="cf-btn-sm" data-restore="<?= $h($b['name']) ?>"
                              data-label="<?= $h(date('d-m-Y', (int) strtotime($b['date'])) . ' ' . $b['time']) ?>"
                              data-uploads="<?= $b['uploads'] ? '1' : '0' ?>">♻️ Terugzetten</button>
                    <?php endif; ?>
                    <form method="post" action="/admin/backup/verwijder" style="display:inline" onsubmit="return confirm('Deze back-up verwijderen?');">
                      <?= $csrf ?><input type="hidden" name="name" value="<?= $h($b['name']) ?>">
                      <button type="submit" class="cf-btn-sm cf-btn-danger">🗑️</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($canRestore): ?>
<dialog class="bk-dlg" id="bk-dlg">
  <form method="post" action="/admin/backup/herstel" class="bk-pad">
    <?= $csrf ?>
    <input type="hidden" name="name" id="bk-name">
    <h2 style="margin-top:0;">♻️ Terugzetten</h2>
    <p>Je zet de back-up van <strong id="bk-label"></strong> terug. De <strong>huidige database wordt overschreven</strong>.
       Vooraf wordt automatisch een veiligheidsback-up gemaakt waarmee je dit kunt terugdraaien.</p>
    <label class="bk-row" id="bk-up-row"><input type="checkbox" name="restore_uploads" value="1"> Ook bestanden terugzetten</label>
    <label class="bk-row">Typ <strong>HERSTEL</strong>: <input type="text" name="confirm" class="cf-input" style="width:9rem;" autocomplete="off" required></label>
    <div class="bk-row">
      <button type="submit" class="cf-btn cf-btn-danger">Terugzetten</button>
      <button type="button" class="cf-btn-ghost" id="bk-cancel">Annuleren</button>
    </div>
  </form>
</dialog>
<script>
(function () {
  var dlg = document.getElementById('bk-dlg');
  if (!dlg || !dlg.showModal) { return; }
  document.querySelectorAll('[data-restore]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('bk-name').value = btn.getAttribute('data-restore');
      document.getElementById('bk-label').textContent = btn.getAttribute('data-label');
      document.getElementById('bk-up-row').style.display = btn.getAttribute('data-uploads') === '1' ? '' : 'none';
      dlg.showModal();
    });
  });
  document.getElementById('bk-cancel').addEventListener('click', function () { dlg.close(); });
})();
</script>
<?php endif; ?>
</body>
</html>
<?php
// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: views/admin_index.php | Role: View | Version: 1.0.0          ║
// ║  Created: 2026-10-10 | Status: New — Backup & herstel               ║
// ╚══════════════════════════════════════════════════════════════════════╝
