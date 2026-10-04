<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
use CommunityFusion\Core\Security\CsrfProtection;
// $applications, $members, $ranks beschikbaar vanuit GuildAdminController
$pendingCount  = count(array_filter($applications ?? [], fn($a) => $a['status'] === 'pending'));
$approvedCount = count(array_filter($applications ?? [], fn($a) => $a['status'] === 'approved'));
$rejectedCount = count(array_filter($applications ?? [], fn($a) => $a['status'] === 'rejected'));
$statusColors  = ['pending' => 'var(--gold)', 'approved' => 'var(--success)', 'rejected' => 'var(--error)'];
$statusIcons   = ['pending' => '⏳', 'approved' => '✅', 'rejected' => '❌'];
$classIcons    = ['Death Knight'=>'🩸','Demon Hunter'=>'👁️','Druid'=>'🌿','Evoker'=>'🐉',
                  'Hunter'=>'🏹','Mage'=>'🔵','Monk'=>'☯️','Paladin'=>'⚔️','Priest'=>'✨',
                  'Rogue'=>'🗡️','Shaman'=>'⚡','Warlock'=>'🟣','Warrior'=>'🛡️'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Guild Beheer — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<style>
  :root{--sidebar-w:240px;}
  .admin-wrap{display:flex;min-height:100vh;}
  .admin-sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);
    display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:50;}
  .admin-logo{padding:1.2rem 1.5rem;font-size:1.1rem;font-weight:800;
    background:linear-gradient(135deg,#a855f7,#6c3df4);-webkit-background-clip:text;
    -webkit-text-fill-color:transparent;border-bottom:1px solid var(--border);}
  .admin-nav{padding:1rem;flex:1;overflow-y:auto;}
  .admin-nav-link{display:flex;align-items:center;gap:.7rem;padding:.55rem .9rem;border-radius:8px;
    font-size:.875rem;color:var(--text-dim);transition:all .15s;margin-bottom:2px;}
  .admin-nav-link:hover,.admin-nav-link.active{background:rgba(108,61,244,.15);color:var(--accent2);}
  .admin-main{margin-left:var(--sidebar-w);flex:1;}
  .admin-topbar{height:56px;background:rgba(17,24,39,.95);border-bottom:1px solid var(--border);
    display:flex;align-items:center;justify-content:space-between;padding:0 1.5rem;
    position:sticky;top:0;z-index:40;}
  .admin-content{padding:2rem;}
  .stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:2rem;}
  .stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
    padding:1.2rem;text-align:center;}
  .stat-val{font-size:2rem;font-weight:800;}
  .stat-lbl{font-size:.78rem;color:var(--muted);margin-top:.2rem;}
  .app-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
    padding:1.2rem;margin-bottom:1rem;transition:border-color .2s;}
  .app-card:hover{border-color:rgba(108,61,244,.3);}
  .app-header{display:flex;align-items:center;gap:.8rem;margin-bottom:.8rem;}
  .app-name{font-weight:700;font-size:.95rem;}
  .app-class{font-size:.8rem;color:var(--muted);}
  .app-body{font-size:.85rem;color:var(--text-dim);line-height:1.5;margin-bottom:.8rem;}
  .app-actions{display:flex;gap:.6rem;}
  .btn-approve{padding:.45rem 1rem;border-radius:6px;font-size:.8rem;font-weight:700;
    background:rgba(16,185,129,.15);color:var(--success);border:1px solid rgba(16,185,129,.3);cursor:pointer;}
  .btn-reject{padding:.45rem 1rem;border-radius:6px;font-size:.8rem;font-weight:700;
    background:rgba(239,68,68,.1);color:var(--error);border:1px solid rgba(239,68,68,.3);cursor:pointer;}
  .btn-approve:hover{background:rgba(16,185,129,.25);}
  .btn-reject:hover{background:rgba(239,68,68,.2);}
  #toast{position:fixed;bottom:1.5rem;right:1.5rem;padding:.7rem 1.2rem;border-radius:8px;
    background:var(--success);color:#fff;font-size:.875rem;font-weight:600;
    transform:translateY(100px);opacity:0;transition:all .3s;z-index:999;}
  #toast.show{transform:translateY(0);opacity:1;}
  #toast.error{background:var(--error);}
</style>
</head>
<body>
<div class="admin-wrap">
  <aside class="admin-sidebar">
    <div class="admin-logo">🔮 Blueprint CMS</div>
    <nav class="admin-nav">
      <a href="/admin" class="admin-nav-link">📊 Dashboard</a>
      <a href="/admin/news" class="admin-nav-link">📰 Nieuws</a>
      <a href="/admin/users" class="admin-nav-link">👥 Gebruikers</a>
      <a href="/admin/blocks" class="admin-nav-link">🧩 Blokken</a>
      <a href="/admin/marketplace" class="admin-nav-link">🏪 Marketplace</a>
      <a href="/admin/guild" class="admin-nav-link active">⚔️ Guild</a>
      <a href="/admin/wow" class="admin-nav-link">🐉 WoW</a>
      <a href="/admin/ollama" class="admin-nav-link">🤖 Ollama AI</a>
      <a href="/" class="admin-nav-link">🌐 Site</a>
    </nav>
  </aside>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>⚔️ Guild Beheer</h1>
      <a href="/guild/apply" class="cf-btn" style="font-size:.8rem;padding:.4rem .9rem;">+ Aanmeldpagina</a>
    </header>

    <div class="admin-content">

      <!-- Stats -->
      <div class="stat-grid">
        <div class="stat-card">
          <div class="stat-val" style="color:var(--gold)"><?= $pendingCount ?></div>
          <div class="stat-lbl">⏳ In behandeling</div>
        </div>
        <div class="stat-card">
          <div class="stat-val" style="color:var(--success)"><?= $approvedCount ?></div>
          <div class="stat-lbl">✅ Goedgekeurd</div>
        </div>
        <div class="stat-card">
          <div class="stat-val" style="color:var(--error)"><?= $rejectedCount ?></div>
          <div class="stat-lbl">❌ Afgewezen</div>
        </div>
      </div>

      <!-- Aanmeldingen -->
      <h2 style="font-size:1rem;font-weight:700;margin-bottom:1rem;">📋 Aanmeldingen</h2>

      <?php if (empty($applications)): ?>
        <div class="cf-alert cf-alert-warning">Geen aanmeldingen gevonden.</div>
      <?php else: ?>
        <?php foreach ($applications as $app):
          $statusColor = $statusColors[$app['status']] ?? 'var(--muted)';
          $statusIcon  = $statusIcons[$app['status']] ?? '?';
          $classIcon   = $classIcons[$app['class'] ?? ''] ?? '⚔️';
        ?>
        <div class="app-card">
          <div class="app-header">
            <span style="font-size:1.4rem;"><?= $classIcon ?></span>
            <div>
              <div class="app-name"><?= htmlspecialchars($app['character_name']) ?></div>
              <div class="app-class">
                <?= htmlspecialchars($app['spec'] ?? '') ?> <?= htmlspecialchars($app['class'] ?? '') ?>
                <?php if ($app['item_level']): ?>
                  · <span style="color:var(--gold)">⚙️ <?= (int)$app['item_level'] ?> iLvl</span>
                <?php endif; ?>
                <?php if ($app['team_name']): ?>
                  · Team: <?= htmlspecialchars($app['team_name']) ?>
                <?php endif; ?>
              </div>
            </div>
            <div style="margin-left:auto;font-size:.82rem;font-weight:700;color:<?= $statusColor ?>">
              <?= $statusIcon ?> <?= ucfirst($app['status']) ?>
            </div>
            <div style="font-size:.75rem;color:var(--muted)">
              <?= date('d M Y', strtotime($app['created_at'])) ?>
            </div>
          </div>
          <div class="app-body">
            <?= nl2br(htmlspecialchars(substr($app['about'], 0, 300))) ?>
            <?= strlen($app['about']) > 300 ? '...' : '' ?>
          </div>
          <?php if ($app['status'] === 'pending'): ?>
          <div class="app-actions">
            <button class="btn-approve" onclick="reviewApp(<?= $app['id'] ?>, 'approve')">✅ Goedkeuren (→ Trial)</button>
            <button class="btn-reject"  onclick="reviewApp(<?= $app['id'] ?>, 'reject')">❌ Afwijzen</button>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- Guild leden -->
      <h2 style="font-size:1rem;font-weight:700;margin:2rem 0 1rem;">👥 Actieve Leden (<?= count($members ?? []) ?>)</h2>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.6rem;">
        <?php foreach ($members ?? [] as $m):
          $classIcon = $classIcons[$m['class'] ?? ''] ?? '⚔️';
          $rankColor = htmlspecialchars($m['rank_color'] ?? '#64748b', ENT_QUOTES);
        ?>
        <div style="display:flex;align-items:center;gap:.7rem;padding:.6rem .9rem;
                    background:var(--surface);border:1px solid var(--border);border-radius:8px;">
          <span style="font-size:1.2rem;"><?= $classIcon ?></span>
          <div>
            <div style="font-weight:600;font-size:.875rem;"><?= htmlspecialchars($m['character_name']) ?></div>
            <div style="font-size:.75rem;color:var(--muted);">
              <?php if ($m['spec'] && $m['class']): ?>
                <?= htmlspecialchars($m['spec']) ?> <?= htmlspecialchars($m['class']) ?>
              <?php endif; ?>
              <?php if ($m['item_level']): ?>
                · <span style="color:var(--gold)">⚙️ <?= (int)$m['item_level'] ?></span>
              <?php endif; ?>
            </div>
            <?php if ($m['rank_name']): ?>
              <div style="font-size:.7rem;font-weight:700;color:<?= $rankColor ?>;">
                <?= htmlspecialchars($m['rank_name']) ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

    </div>
  </div>
</div>
<div id="toast"></div>
<?= CsrfProtection::field() ?>

<script>
const CSRF = document.querySelector('input[name="_csrf_token"]')?.value || '';
function showToast(msg, type='success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = type === 'error' ? 'show error' : 'show';
  setTimeout(() => t.className = '', 3000);
}

async function reviewApp(id, action) {
  const note = action === 'reject' ? prompt('Reden voor afwijzing (optioneel):') ?? '' : '';
  const r = await fetch(`/admin/guild/applications/${id}/${action}`, {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body: JSON.stringify({ note, _csrf_token: CSRF })
  });
  const d = await r.json();
  if (d.success) {
    showToast(action === 'approve' ? '✅ Goedgekeurd — lid toegevoegd als Trial' : '❌ Aanmelding afgewezen');
    setTimeout(() => location.reload(), 1000);
  } else {
    showToast(d.error || 'Fout', 'error');
  }
}
</script>
<?php include CF_ROOT . '/src/Modules/Shared/views/admin_mobile.php'; ?>
</body>
</html>
