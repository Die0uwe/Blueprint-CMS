<?php
// $items vanuit ApiStatusController::index()
$activeNav = 'apistatus';
$labels = [
    'off' => ['Uit', '⚪'], 'unconfigured' => ['Niet ingesteld', '🟡'],
    'configured' => ['Ingesteld', '🔵'], 'ok' => ['Werkt', '🟢'], 'error' => ['Fout', '🔴'],
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\CommunityFusion\Core\I18n\Trans::locale(), ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>API-overzicht — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>
  .api-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:1rem; }
  .api-card { padding:1.1rem 1.25rem; border-left:4px solid var(--border); }
  .api-card[data-status=ok]{border-left-color:#22c55e} .api-card[data-status=error]{border-left-color:#ef4444}
  .api-card[data-status=unconfigured]{border-left-color:#eab308} .api-card[data-status=configured]{border-left-color:#3b82f6}
  .api-head { display:flex; align-items:center; justify-content:space-between; gap:.75rem; }
  .api-title { font-weight:700; font-size:1.05rem; }
  .api-dot { font-size:1.1rem; }
  .api-desc { color:var(--text-dim); font-size:.85rem; margin:.25rem 0 .6rem; }
  .api-msg { font-size:.85rem; min-height:1.3em; margin-bottom:.7rem; }
  .api-actions { display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
  .api-switch { display:inline-flex; align-items:center; gap:.4rem; font-size:.85rem; cursor:pointer; }
  .api-actions button { font:inherit; cursor:pointer; }
  .api-actions button:disabled { opacity:.45; cursor:not-allowed; }
  .api-summary { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1.25rem; font-size:.9rem; }
</style>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>
  <div class="admin-main">
    <header class="admin-topbar"><h1>📡 API-overzicht</h1></header>
    <div class="admin-content">
      <?= \CommunityFusion\Core\Security\CsrfProtection::field() ?>
      <div class="cf-toolbar">
        <div class="api-summary" id="api-summary"></div>
        <button type="button" class="cf-btn" id="test-all">🔄 Test alles</button>
      </div>
      <div class="api-grid">
        <?php foreach ($items as $it): $l = $labels[$it['status']]; ?>
          <div class="cf-card api-card" data-slug="<?= htmlspecialchars($it['slug'], ENT_QUOTES) ?>"
               data-status="<?= htmlspecialchars($it['status'], ENT_QUOTES) ?>"
               data-testable="<?= $it['testable'] ? '1' : '0' ?>">
            <div class="api-head">
              <span class="api-title"><?= htmlspecialchars($it['icon'] . ' ' . $it['label']) ?></span>
              <span class="api-dot" title="<?= htmlspecialchars($l[0]) ?>"><?= $l[1] ?></span>
            </div>
            <div class="api-desc"><?= htmlspecialchars($it['description']) ?></div>
            <div class="api-msg"><?= htmlspecialchars($it['message']) ?></div>
            <div class="api-actions">
              <label class="api-switch">
                <input type="checkbox" class="api-toggle" <?= $it['enabled'] ? 'checked' : '' ?> <?= $it['installed'] ? '' : 'disabled' ?>>
                <span>Aan</span>
              </label>
              <button type="button" class="cf-btn-ghost api-test" <?= $it['testable'] ? '' : 'disabled' ?>>Test</button>
              <a class="cf-btn-ghost" href="/admin/marketplace/package/<?= rawurlencode($it['slug']) ?>/instellingen">Instellingen</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  const CSRF = document.querySelector('input[name="_csrf_token"]')?.value || '';
  const LABEL = {off:['Uit','⚪'],unconfigured:['Niet ingesteld','🟡'],configured:['Ingesteld','🔵'],ok:['Werkt','🟢'],error:['Fout','🔴']};
  const cards = [...document.querySelectorAll('.api-card')];

  async function post(url, body) {
    const r = await fetch(url, {method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({...body, _csrf_token: CSRF})});
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
  }
  function paint(card, status, message) {
    card.dataset.status = status;
    card.querySelector('.api-dot').textContent = LABEL[status][1];
    card.querySelector('.api-dot').title = LABEL[status][0];
    if (message != null) card.querySelector('.api-msg').textContent = message;
    summary();
  }
  function summary() {
    const n = {}; cards.forEach(c => n[c.dataset.status] = (n[c.dataset.status] || 0) + 1);
    document.getElementById('api-summary').textContent =
      Object.keys(LABEL).filter(k => n[k]).map(k => LABEL[k][1] + ' ' + n[k] + ' ' + LABEL[k][0].toLowerCase()).join('  ·  ');
  }
  async function test(card) {
    if (card.dataset.testable !== '1') return;
    const st = card.dataset.status;
    if (st === 'off' || st === 'unconfigured') return;
    card.querySelector('.api-msg').textContent = 'Testen…';
    try {
      const d = await post('/admin/api-status/test', {slug: card.dataset.slug});
      paint(card, d.status, d.message + (d.ms ? ' (' + d.ms + ' ms)' : ''));
    } catch (e) { paint(card, 'error', 'Test mislukt: ' + e.message); }
  }
  cards.forEach(card => {
    card.querySelector('.api-test').addEventListener('click', () => test(card));
    card.querySelector('.api-toggle').addEventListener('change', async ev => {
      const box = ev.target; box.disabled = true;
      try {
        const d = await post('/admin/api-status/toggle', {slug: card.dataset.slug, enable: box.checked ? 1 : 0});
        paint(card, d.item.status, d.item.message);
        card.dataset.testable = d.item.testable ? '1' : '0';
      } catch (e) { box.checked = !box.checked; card.querySelector('.api-msg').textContent = 'Schakelen mislukt: ' + e.message; }
      box.disabled = false;
    });
  });
  document.getElementById('test-all').addEventListener('click', () => Promise.all(cards.map(test)));
  summary();
  Promise.all(cards.map(test)); // meteen live controleren bij openen
})();
</script>
</body>
</html>
