<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $items, $page, $pages, $total, $action, $actions beschikbaar vanuit
// LogAdminController::index()

$activeNav = 'logs';

$actionColors = [
    'auth.login'          => 'green',
    'auth.login_failed'   => 'red',
    'forum.topic.delete'  => 'red',
    'forum.board.delete'  => 'red',
    'roles.delete'        => 'red',
];
$colorFor = static fn(string $a) => $actionColors[$a] ?? 'gray';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Systeemlogs — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>📋 Systeemlogs</h1>
    </header>

    <div class="admin-content">
      <div class="cf-toolbar">
        <form method="get" action="/admin/logs" style="display:flex;gap:.5rem;align-items:center;">
          <select name="action" class="cf-input" onchange="this.form.submit()" style="min-width:220px;">
            <option value="">Alle acties</option>
            <?php foreach ($actions as $a): ?>
              <option value="<?= htmlspecialchars($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($action !== ''): ?>
            <a href="/admin/logs" class="cf-btn-ghost">Wissen</a>
          <?php endif; ?>
        </form>
        <span style="color:var(--text-dim);font-size:.85rem;"><?= $total ?> event(s)</span>
      </div>

      <div class="cf-card">
        <?php if (empty($items)): ?>
          <div class="cf-table-empty">Nog geen loggegevens.</div>
        <?php else: ?>
          <table class="cf-table">
            <thead>
              <tr>
                <th>Tijdstip</th>
                <th>Actie</th>
                <th>Gebruiker</th>
                <th>IP</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $row): ?>
                <tr>
                  <td style="white-space:nowrap;"><?= htmlspecialchars(date('d-m-Y H:i:s', strtotime((string) $row['created_at']))) ?></td>
                  <td><span class="cf-badge cf-badge-<?= $colorFor($row['action']) ?>"><?= htmlspecialchars($row['action']) ?></span></td>
                  <td><?= $row['username'] !== null ? htmlspecialchars($row['username']) : '<span style="color:var(--text-dim);">—</span>' ?></td>
                  <td style="color:var(--text-dim);font-size:.8rem;"><?= htmlspecialchars((string) ($row['ip_address'] ?? '—')) ?></td>
                  <td style="font-size:.8rem;color:var(--text-dim);max-width:360px;">
                    <?php if (!empty($row['context'])): ?>
                      <?php $ctx = json_decode((string) $row['context'], true) ?? []; ?>
                      <?php foreach ($ctx as $k => $v): ?>
                        <?= htmlspecialchars($k) ?>=<?= htmlspecialchars(is_scalar($v) ? (string) $v : json_encode($v)) ?>
                      <?php endforeach; ?>
                    <?php else: ?>—<?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <?php if ($pages > 1): ?>
        <div class="cf-pagination">
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a href="/admin/logs?page=<?= $i ?><?= $action !== '' ? '&action=' . urlencode($action) : '' ?>"
               class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
