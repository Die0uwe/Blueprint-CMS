<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
// $package, $isInstalled, $installed beschikbaar vanuit MarketplaceController::detail()
$typeIcons  = ['module' => '⚙️', 'theme' => '🎨', 'block' => '🧩'];
$typeColors = ['module' => '#6c3df4', 'theme' => '#f59e0b', 'block' => '#1D9E75'];
$typeIcon   = $typeIcons[$package['type'] ?? 'module'] ?? '📦';
$typeColor  = $typeColors[$package['type'] ?? 'module'] ?? '#6c3df4';
$tags       = json_decode($package['tags'] ?? '[]', true) ?? [];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\CommunityFusion\Core\I18n\Trans::locale(), ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars($package['name'] ?? '') ?> — Marketplace — Blueprint CMS</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<style>
  :root{--sidebar-w:240px;}
  .admin-wrap{display:flex;min-height:100vh;}
  .admin-sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);
    display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:50;}
  .admin-logo{padding:1.2rem 1.5rem;font-size:1.1rem;font-weight:800;
    background:linear-gradient(135deg,#a855f7,#6c3df4);-webkit-background-clip:text;
    -webkit-text-fill-color:transparent;border-bottom:1px solid var(--border);}
  .admin-nav{padding:1rem;flex:1;}
  .admin-nav-link{display:flex;align-items:center;gap:.7rem;padding:.55rem .9rem;border-radius:8px;
    font-size:.875rem;color:var(--text-dim);margin-bottom:2px;transition:all .15s;}
  .admin-nav-link:hover,.admin-nav-link.active{background:rgba(108,61,244,.15);color:var(--accent2);}
  .admin-main{margin-left:var(--sidebar-w);flex:1;}
  .admin-topbar{height:56px;background:rgba(17,24,39,.95);border-bottom:1px solid var(--border);
    display:flex;align-items:center;gap:1rem;padding:0 1.5rem;position:sticky;top:0;z-index:40;}
  .admin-content{padding:2rem;max-width:900px;}
  .pkg-hero{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);
    padding:2rem;margin-bottom:1.5rem;display:flex;gap:1.5rem;align-items:flex-start;}
  .pkg-icon-lg{width:72px;height:72px;border-radius:14px;display:flex;align-items:center;
    justify-content:center;font-size:2.2rem;flex-shrink:0;}
  .pkg-title{font-size:1.5rem;font-weight:800;margin-bottom:.3rem;}
  .pkg-by{color:var(--muted);font-size:.875rem;}
  .pkg-badges{display:flex;gap:.4rem;flex-wrap:wrap;margin:.5rem 0;}
  .badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:.7rem;font-weight:700;}
  .badge-verified{background:rgba(16,185,129,.15);color:var(--success);}
  .badge-featured{background:rgba(245,158,11,.15);color:var(--gold);}
  .badge-type{background:rgba(108,61,244,.15);color:var(--accent2);}
  .pkg-stats{display:flex;gap:1.5rem;margin-top:.8rem;font-size:.85rem;color:var(--muted);}
  .pkg-actions{margin-left:auto;display:flex;flex-direction:column;gap:.6rem;flex-shrink:0;}
  .btn-install-lg{padding:.7rem 1.6rem;border-radius:8px;font-size:.9rem;font-weight:700;
    background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;border:none;
    cursor:pointer;box-shadow:0 4px 20px rgba(108,61,244,.35);text-align:center;min-width:160px;}
  .btn-installed{padding:.7rem 1.6rem;border-radius:8px;font-size:.9rem;font-weight:700;
    background:rgba(16,185,129,.15);color:var(--success);border:1px solid rgba(16,185,129,.3);
    cursor:default;text-align:center;min-width:160px;}
  .btn-uninstall{padding:.5rem 1rem;border-radius:6px;font-size:.78rem;font-weight:600;
    background:rgba(239,68,68,.1);color:var(--error);border:1px solid rgba(239,68,68,.3);cursor:pointer;}
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
      <a href="/admin/marketplace" class="admin-nav-link active">🏪 Marketplace</a>
      <a href="/admin/modules" class="admin-nav-link">⚙️ Modules</a>
      <a href="/admin/blocks" class="admin-nav-link">🧩 Blokken</a>
      <a href="/" class="admin-nav-link">🌐 Site</a>
    </nav>
  </aside>
  <div class="admin-main">
    <header class="admin-topbar">
      <a href="/admin/marketplace" style="color:var(--muted);font-size:.85rem;">← Marketplace</a>
      <h1 style="font-size:1rem;font-weight:700;"><?= htmlspecialchars($package['name'] ?? '') ?></h1>
    </header>
    <div class="admin-content">

      <div class="pkg-hero">
        <div class="pkg-icon-lg" style="background:<?= htmlspecialchars($typeColor,ENT_QUOTES) ?>22;border:1px solid <?= htmlspecialchars($typeColor,ENT_QUOTES) ?>44">
          <?= $typeIcon ?>
        </div>
        <div style="flex:1">
          <div class="pkg-title"><?= htmlspecialchars($package['name'] ?? '') ?></div>
          <div class="pkg-by">door <?= htmlspecialchars($package['author'] ?? 'Onbekend') ?>
            <?php if ($package['author_url']): ?>
              · <a href="<?= htmlspecialchars($package['author_url'],ENT_QUOTES) ?>" target="_blank" rel="noopener" style="color:var(--accent2)">Website</a>
            <?php endif; ?>
          </div>
          <div class="pkg-badges">
            <span class="badge badge-type"><?= ucfirst($package['type'] ?? '') ?></span>
            <?php if ($package['is_verified']): ?><span class="badge badge-verified">✓ Verified</span><?php endif; ?>
            <?php if ($package['is_featured']): ?><span class="badge badge-featured">⭐ Featured</span><?php endif; ?>
            <?php if ($package['is_premium']): ?>
              <span class="badge" style="background:rgba(168,85,247,.15);color:var(--accent2)">💎 Premium</span>
            <?php else: ?>
              <span class="badge" style="background:rgba(255,255,255,.06);color:var(--muted)">Gratis</span>
            <?php endif; ?>
            <?php foreach ($tags as $tag): ?>
              <span class="badge" style="background:rgba(255,255,255,.06);color:var(--muted)"><?= htmlspecialchars($tag) ?></span>
            <?php endforeach; ?>
          </div>
          <div class="pkg-stats">
            <span>📦 v<?= htmlspecialchars($package['version'] ?? '') ?></span>
            <span>📥 <?= number_format((int)($package['downloads'] ?? 0)) ?> downloads</span>
            <span>🔧 min CMS <?= htmlspecialchars($package['min_cms'] ?? '1.0.0') ?></span>
            <span>📄 <?= htmlspecialchars($package['license'] ?? 'GPL-3.0') ?></span>
          </div>
        </div>
        <div class="pkg-actions">
          <?php if ($isInstalled): ?>
            <div class="btn-installed">✅ Geïnstalleerd<br><small style="font-weight:400;color:var(--muted)">v<?= htmlspecialchars($installed['version'] ?? '') ?></small></div>
            <button class="btn-uninstall" onclick="uninstallPkg('<?= htmlspecialchars($package['slug'],ENT_QUOTES) ?>')">🗑️ Verwijderen</button>
          <?php else: ?>
            <button class="btn-install-lg" onclick="installPkg('<?= htmlspecialchars($package['slug'],ENT_QUOTES) ?>', '<?= htmlspecialchars($package['download_url'] ?? '',ENT_QUOTES) ?>')">
              📥 Installeren
            </button>
          <?php endif; ?>
          <?php if ($package['homepage_url']): ?>
            <a href="<?= htmlspecialchars($package['homepage_url'],ENT_QUOTES) ?>" target="_blank" rel="noopener"
               style="text-align:center;font-size:.78rem;color:var(--accent2);">
              🔗 Meer informatie
            </a>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($package['description']): ?>
      <div class="cf-card" style="margin-bottom:1rem;">
        <div class="cf-card-header">📄 Omschrijving</div>
        <div class="cf-card-body">
          <p style="color:var(--text-dim);line-height:1.7;font-size:.9rem;">
            <?= nl2br(htmlspecialchars($package['description'])) ?>
          </p>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>
<div id="toast"></div>
<script>
function showToast(msg,type='success'){
  const t=document.getElementById('toast');
  t.textContent=msg; t.className=type==='error'?'show error':'show';
  setTimeout(()=>t.className='',3000);
}
async function installPkg(slug,url){
  showToast('⏳ Installeren...');
  const r=await fetch('/admin/marketplace/install',{
    method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body:JSON.stringify({slug,download_url:url})
  });
  const d=await r.json();
  if(d.success){showToast('✅ Geïnstalleerd!');setTimeout(()=>location.reload(),1000);}
  else showToast(d.error||'Fout','error');
}
async function uninstallPkg(slug){
  if(!confirm(`${slug} verwijderen?`))return;
  const r=await fetch('/admin/marketplace/uninstall',{
    method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body:JSON.stringify({slug})
  });
  const d=await r.json();
  if(d.success){showToast('🗑️ Verwijderd');setTimeout(()=>location.reload(),800);}
  else showToast(d.error||'Fout','error');
}
</script>
</body>
</html>
