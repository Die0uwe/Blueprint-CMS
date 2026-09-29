<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// Variabelen beschikbaar: $catalog, $installed, $updates, $tab, $type, $search, $sortBy
$updateCount    = count($updates);
$installedCount = count($installed);

$typeIcons  = ['module' => '⚙️', 'theme' => '🎨', 'block' => '🧩'];
$typeColors = ['module' => '#6c3df4', 'theme' => '#f59e0b', 'block' => '#1D9E75'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Marketplace — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<style>
  :root { --sidebar-w: 240px; }
  .admin-wrap { display:flex; min-height:100vh; }
  .admin-sidebar { width:var(--sidebar-w); background:var(--surface); border-right:1px solid var(--border);
    display:flex; flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:50; }
  .admin-logo { padding:1.2rem 1.5rem; font-size:1.1rem; font-weight:800;
    background:linear-gradient(135deg,#a855f7,#6c3df4); -webkit-background-clip:text;
    -webkit-text-fill-color:transparent; border-bottom:1px solid var(--border); }
  .admin-nav { padding:1rem; flex:1; overflow-y:auto; }
  .admin-nav-link { display:flex; align-items:center; gap:.7rem; padding:.55rem .9rem;
    border-radius:8px; font-size:.875rem; color:var(--text-dim); transition:all .15s; margin-bottom:2px; }
  .admin-nav-link:hover,.admin-nav-link.active { background:rgba(108,61,244,.15); color:var(--accent2); }
  .admin-main { margin-left:var(--sidebar-w); flex:1; }
  .admin-topbar { height:56px; background:rgba(17,24,39,.95); border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between; padding:0 1.5rem;
    position:sticky; top:0; z-index:40; backdrop-filter:blur(8px); }
  .admin-content { padding:2rem; }

  /* Tab nav */
  .tab-nav { display:flex; gap:4px; background:var(--surface); border:1px solid var(--border);
    border-radius:10px; padding:4px; margin-bottom:1.5rem; width:fit-content; }
  .tab-btn { padding:.45rem 1.2rem; border-radius:7px; font-size:.875rem; font-weight:600;
    border:none; background:transparent; color:var(--muted); cursor:pointer; transition:all .15s;
    display:flex; align-items:center; gap:.4rem; }
  .tab-btn.active { background:var(--accent); color:#fff; box-shadow:0 2px 8px rgba(108,61,244,.4); }
  .tab-btn .badge { background:rgba(255,255,255,.2); border-radius:10px;
    font-size:.7rem; padding:1px 6px; font-weight:700; }

  /* Search + filters */
  .market-toolbar { display:flex; gap:.8rem; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; }
  .market-search { flex:1; min-width:200px; display:flex; gap:.5rem; }
  .market-search input { flex:1; }
  .filter-pills { display:flex; gap:.4rem; flex-wrap:wrap; }
  .pill-btn { padding:.35rem .9rem; border-radius:6px; font-size:.8rem; font-weight:600;
    border:1px solid var(--border); background:transparent; color:var(--muted); cursor:pointer; transition:all .15s; }
  .pill-btn.active,.pill-btn:hover { background:rgba(108,61,244,.15); border-color:var(--accent); color:var(--accent2); }

  /* Package grid */
  .pkg-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:1rem; }
  .pkg-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius);
    overflow:hidden; transition:border-color .2s, box-shadow .2s; display:flex; flex-direction:column; }
  .pkg-card:hover { border-color:rgba(108,61,244,.5); box-shadow:0 4px 20px rgba(108,61,244,.15); }
  .pkg-card-header { padding:1.1rem 1.2rem .6rem; display:flex; align-items:flex-start; gap:.8rem; }
  .pkg-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center;
    justify-content:center; font-size:1.3rem; flex-shrink:0; }
  .pkg-name { font-weight:700; font-size:.95rem; line-height:1.2; }
  .pkg-author { font-size:.75rem; color:var(--muted); margin-top:.15rem; }
  .pkg-badges { display:flex; gap:.3rem; flex-wrap:wrap; margin:.2rem 0; }
  .pkg-badge { font-size:.65rem; font-weight:700; padding:1px 6px; border-radius:4px; text-transform:uppercase; }
  .badge-verified { background:rgba(16,185,129,.15); color:var(--success); }
  .badge-featured { background:rgba(245,158,11,.15); color:var(--gold); }
  .badge-premium  { background:rgba(168,85,247,.15); color:var(--accent2); }
  .badge-free     { background:rgba(255,255,255,.06); color:var(--muted); }
  .pkg-desc { font-size:.83rem; color:var(--text-dim); line-height:1.5;
    padding:0 1.2rem; flex:1; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2;
    -webkit-box-orient:vertical; }
  .pkg-footer { padding:.8rem 1.2rem; display:flex; align-items:center; justify-content:space-between;
    border-top:1px solid var(--border); margin-top:.8rem; }
  .pkg-meta { font-size:.75rem; color:var(--muted); display:flex; gap:.7rem; }
  .pkg-actions { display:flex; gap:.5rem; }
  .btn-install { padding:.4rem .9rem; border-radius:6px; font-size:.8rem; font-weight:700;
    background:linear-gradient(135deg,var(--accent),var(--accent2)); color:#fff; border:none; cursor:pointer;
    transition:opacity .2s; }
  .btn-install:hover { opacity:.88; }
  .btn-install.installed { background:rgba(16,185,129,.15); color:var(--success); border:1px solid rgba(16,185,129,.3); }
  .btn-install.update { background:rgba(245,158,11,.15); color:var(--gold); border:1px solid rgba(245,158,11,.3); }
  .btn-sm { padding:.3rem .7rem; border-radius:5px; font-size:.75rem; font-weight:600; cursor:pointer;
    border:1px solid var(--border); background:transparent; color:var(--muted); transition:all .15s; }
  .btn-sm:hover { border-color:var(--error); color:var(--error); }

  /* Installed tab */
  .installed-list { display:flex; flex-direction:column; gap:.6rem; }
  .inst-row { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius);
    padding:.9rem 1.2rem; display:flex; align-items:center; gap:1rem; transition:border-color .2s; }
  .inst-row:hover { border-color:var(--border); }
  .inst-icon { width:36px; height:36px; border-radius:8px; display:flex; align-items:center;
    justify-content:center; font-size:1.1rem; flex-shrink:0; }
  .inst-name { font-weight:700; font-size:.9rem; }
  .inst-meta { font-size:.75rem; color:var(--muted); margin-top:.1rem; }
  .inst-actions { margin-left:auto; display:flex; gap:.5rem; align-items:center; }
  .toggle-switch { position:relative; width:36px; height:20px; }
  .toggle-switch input { opacity:0; width:0; height:0; position:absolute; }
  .toggle-track { position:absolute; inset:0; background:var(--border); border-radius:10px;
    cursor:pointer; transition:background .2s; }
  .toggle-switch input:checked + .toggle-track { background:var(--success); }
  .toggle-thumb { position:absolute; top:2px; left:2px; width:16px; height:16px; background:#fff;
    border-radius:50%; transition:transform .2s; pointer-events:none; }
  .toggle-switch input:checked ~ .toggle-thumb { transform:translateX(16px); }

  /* Update badge */
  .update-dot { width:8px; height:8px; background:var(--gold); border-radius:50%;
    flex-shrink:0; box-shadow:0 0 6px var(--gold); }

  /* Upload zone */
  .upload-zone { border:2px dashed var(--border); border-radius:var(--radius-lg);
    padding:3rem; text-align:center; cursor:pointer; transition:all .2s; background:rgba(255,255,255,.01); }
  .upload-zone:hover,.upload-zone.drag { border-color:var(--accent); background:rgba(108,61,244,.06); }
  .upload-zone-icon { font-size:2.5rem; margin-bottom:.8rem; }
  .upload-zone-text { font-size:.9rem; color:var(--muted); }
  .upload-zone-sub  { font-size:.78rem; color:var(--muted); margin-top:.3rem; }

  /* Toast */
  #toast { position:fixed; bottom:1.5rem; right:1.5rem; padding:.7rem 1.2rem; border-radius:8px;
    background:var(--success); color:#fff; font-size:.875rem; font-weight:600;
    transform:translateY(100px); opacity:0; transition:all .3s; z-index:999;
    box-shadow:0 4px 20px rgba(0,0,0,.4); pointer-events:none; }
  #toast.show { transform:translateY(0); opacity:1; }
  #toast.error { background:var(--error); }
  #toast.warning { background:var(--gold); }

  /* Progress bar */
  #install-progress { display:none; margin:.5rem 0; }
  #install-progress.show { display:block; }
  .progress-bar { height:4px; background:var(--border); border-radius:2px; overflow:hidden; }
  .progress-fill { height:100%; background:linear-gradient(90deg,var(--accent),var(--accent2));
    border-radius:2px; width:0%; transition:width .3s; animation:progress-pulse 1.5s ease-in-out infinite; }
  @keyframes progress-pulse { 0%,100%{opacity:1} 50%{opacity:.6} }
</style>
</head>
<body>
<div class="admin-wrap">

  <!-- Sidebar -->
  <aside class="admin-sidebar">
    <div class="admin-logo">🔮 Blueprint CMS</div>
    <nav class="admin-nav">
      <a href="/admin" class="admin-nav-link">📊 Dashboard</a>
      <a href="/admin/news" class="admin-nav-link">📰 Nieuws</a>
      <a href="/admin/users" class="admin-nav-link">👥 Gebruikers</a>
      <a href="/admin/blocks" class="admin-nav-link">🧩 Blokken</a>
      <a href="/admin/themes" class="admin-nav-link">🎨 Thema's</a>
      <a href="/admin/modules" class="admin-nav-link">⚙️ Modules</a>
      <a href="/admin/marketplace" class="admin-nav-link active">🏪 Marketplace</a>
      <a href="/admin/ollama" class="admin-nav-link">🤖 Ollama AI</a>
      <a href="/admin/wow" class="admin-nav-link">🐉 WoW</a>
      <a href="/admin/settings" class="admin-nav-link">🛠️ Instellingen</a>
      <a href="/" class="admin-nav-link">🌐 Bekijk Site</a>
      <a href="/logout" class="admin-nav-link">👋 Uitloggen</a>
    </nav>
  </aside>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>🏪 Marketplace</h1>
      <div style="display:flex;gap:.8rem;align-items:center;">
        <?php if ($updateCount > 0): ?>
          <span style="background:rgba(245,158,11,.15);color:var(--gold);border:1px solid rgba(245,158,11,.3);
            padding:.3rem .8rem;border-radius:6px;font-size:.78rem;font-weight:700;">
            ⬆️ <?= $updateCount ?> update<?= $updateCount > 1 ? 's' : '' ?> beschikbaar
          </span>
        <?php endif; ?>
        <span style="font-size:.78rem;color:var(--muted);"><?= count($catalog) ?> packages beschikbaar</span>
      </div>
    </header>

    <div class="admin-content">

      <!-- Tab navigatie -->
      <div class="tab-nav">
        <button class="tab-btn <?= $tab === 'browse' ? 'active' : '' ?>" onclick="switchTab('browse')">
          🔍 Browsen
          <span class="badge"><?= count($catalog) ?></span>
        </button>
        <button class="tab-btn <?= $tab === 'installed' ? 'active' : '' ?>" onclick="switchTab('installed')">
          ✅ Geïnstalleerd
          <span class="badge"><?= $installedCount ?></span>
        </button>
        <button class="tab-btn <?= $tab === 'updates' ? 'active' : '' ?>" onclick="switchTab('updates')">
          ⬆️ Updates
          <?php if ($updateCount > 0): ?>
          <span class="badge" style="background:rgba(245,158,11,.3);color:var(--gold);"><?= $updateCount ?></span>
          <?php endif; ?>
        </button>
        <button class="tab-btn <?= $tab === 'upload' ? 'active' : '' ?>" onclick="switchTab('upload')">
          📦 ZIP Upload
        </button>
      </div>

      <?php if (!empty($configurableModules)): ?>
      <!-- Golf 10: providers/API-instellingen — buiten de tab-structuur om
           altijd zichtbaar, ongeacht welke tab actief is en ongeacht of de
           module via cf_marketplace_installed getrackt wordt (zie
           MarketplaceController::getConfigurableModules() voor waarom dat
           niet volstaat voor installer-tijd ingeschakelde modules). -->
      <div style="background:rgba(108,61,244,.08);border:1px solid rgba(108,61,244,.25);border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem;">
        <div style="font-weight:600;margin-bottom:.5rem;">🔑 Providers &amp; API-instellingen</div>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
          <?php foreach ($configurableModules as $cm): ?>
            <a class="btn-sm" href="/admin/marketplace/package/<?= htmlspecialchars($cm['slug'], ENT_QUOTES) ?>/instellingen">
              ⚙️ <?= htmlspecialchars($cm['name']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- TAB: BROWSEN -->
      <div id="tab-browse" class="<?= $tab !== 'browse' ? 'hidden' : '' ?>" style="display:<?= $tab === 'browse' ? 'block' : 'none' ?>">

        <!-- Toolbar -->
        <div class="market-toolbar">
          <div class="market-search">
            <input class="cf-input" type="text" id="search-input"
                   placeholder="Zoek modules, thema's..."
                   value="<?= htmlspecialchars($search, ENT_QUOTES) ?>"
                   oninput="debounceSearch(this.value)">
          </div>
          <div class="filter-pills">
            <?php foreach (['' => 'Alles', 'module' => '⚙️ Modules', 'theme' => '🎨 Thema\'s', 'block' => '🧩 Blocks'] as $v => $l): ?>
            <button class="pill-btn <?= $type === $v ? 'active' : '' ?>"
                    onclick="filterType('<?= $v ?>')"><?= $l ?></button>
            <?php endforeach; ?>
          </div>
          <select class="cf-select" style="width:140px" onchange="sortBy(this.value)">
            <option value="featured" <?= $sortBy==='featured'?'selected':'' ?>>⭐ Featured</option>
            <option value="downloads" <?= $sortBy==='downloads'?'selected':'' ?>>📥 Populair</option>
            <option value="newest" <?= $sortBy==='newest'?'selected':'' ?>>🆕 Nieuwste</option>
            <option value="name" <?= $sortBy==='name'?'selected':'' ?>>🔤 Naam</option>
          </select>
        </div>

        <!-- Package grid -->
        <div class="pkg-grid">
          <?php foreach ($catalog as $pkg):
            $isInst = isset($installed[array_search($pkg['slug'], array_column($installed, 'package_slug'))]);
            $hasUpd  = isset($updates[$pkg['slug']]);
            $typeIcon = $typeIcons[$pkg['type']] ?? '📦';
            $typeColor = $typeColors[$pkg['type']] ?? '#6c3df4';
          ?>
          <div class="pkg-card">
            <div class="pkg-card-header">
              <div class="pkg-icon" style="background:<?= htmlspecialchars($typeColor, ENT_QUOTES) ?>22;border:1px solid <?= htmlspecialchars($typeColor, ENT_QUOTES) ?>44">
                <?= $typeIcon ?>
              </div>
              <div style="flex:1;min-width:0;">
                <div class="pkg-name"><?= htmlspecialchars($pkg['name']) ?></div>
                <div class="pkg-author">door <?= htmlspecialchars($pkg['author'] ?? 'Onbekend') ?></div>
                <div class="pkg-badges">
                  <?php if ($pkg['is_verified']): ?><span class="pkg-badge badge-verified">✓ Verified</span><?php endif; ?>
                  <?php if ($pkg['is_featured']): ?><span class="pkg-badge badge-featured">⭐ Featured</span><?php endif; ?>
                  <?php if ($pkg['is_premium']): ?><span class="pkg-badge badge-premium">💎 Premium</span>
                  <?php else: ?><span class="pkg-badge badge-free">Gratis</span><?php endif; ?>
                </div>
              </div>
            </div>
            <p class="pkg-desc"><?= htmlspecialchars($pkg['description'] ?? '') ?></p>
            <div class="pkg-footer">
              <div class="pkg-meta">
                <span>v<?= htmlspecialchars($pkg['version']) ?></span>
                <span>📥 <?= number_format((int)($pkg['downloads'] ?? 0)) ?></span>
              </div>
              <div class="pkg-actions">
                <?php
                $instData = null;
                foreach ($installed as $i) {
                    if ($i['package_slug'] === $pkg['slug']) { $instData = $i; break; }
                }
                if ($instData):
                    if ($hasUpd): ?>
                      <button class="btn-install update" onclick="updatePkg('<?= htmlspecialchars($pkg['slug'], ENT_QUOTES) ?>')">
                        ⬆️ Update
                      </button>
                    <?php else: ?>
                      <span class="btn-install installed">✅ Geïnstalleerd</span>
                    <?php endif;
                    ?>
                    <button class="btn-sm" onclick="uninstallPkg('<?= htmlspecialchars($pkg['slug'], ENT_QUOTES) ?>')" title="Verwijderen">🗑️</button>
                <?php else: ?>
                  <button class="btn-install" onclick="installPkg('<?= htmlspecialchars($pkg['slug'], ENT_QUOTES) ?>', '<?= htmlspecialchars($pkg['download_url'] ?? '', ENT_QUOTES) ?>')">
                    📥 Installeren
                  </button>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>

          <?php if (empty($catalog)): ?>
            <div style="grid-column:1/-1;text-align:center;padding:3rem;color:var(--muted);">
              <div style="font-size:2rem;margin-bottom:.5rem;">🔍</div>
              <p>Geen packages gevonden voor "<strong><?= htmlspecialchars($search) ?></strong>".</p>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- TAB: GEÏNSTALLEERD -->
      <div id="tab-installed" style="display:<?= $tab === 'installed' ? 'block' : 'none' ?>">
        <div class="installed-list">
          <?php foreach ($installed as $pkg):
            $typeIcon  = $typeIcons[$pkg['type'] ?? 'module'] ?? '📦';
            $typeColor = $typeColors[$pkg['type'] ?? 'module'] ?? '#6c3df4';
            $hasUpd    = isset($updates[$pkg['package_slug']]);
            $enabled   = (bool)($pkg['is_enabled'] ?? 1);
          ?>
          <div class="inst-row">
            <div class="inst-icon" style="background:<?= htmlspecialchars($typeColor, ENT_QUOTES) ?>22">
              <?= $typeIcon ?>
            </div>
            <div>
              <div class="inst-name">
                <?= htmlspecialchars($pkg['name'] ?? $pkg['package_slug']) ?>
                <?php if ($hasUpd): ?><span style="color:var(--gold);font-size:.72rem;">⬆️ Update beschikbaar</span><?php endif; ?>
              </div>
              <div class="inst-meta">
                v<?= htmlspecialchars($pkg['version']) ?> ·
                <?= ucfirst($pkg['type'] ?? 'module') ?> ·
                Geïnstalleerd <?= date('d M Y', strtotime($pkg['installed_at'])) ?>
              </div>
            </div>
            <div class="inst-actions">
              <?php if ($hasUpd): ?>
                <button class="btn-install update" style="font-size:.78rem;padding:.3rem .7rem"
                        onclick="updatePkg('<?= htmlspecialchars($pkg['package_slug'], ENT_QUOTES) ?>')">
                  ⬆️ Updaten
                </button>
              <?php endif; ?>
              <?php
                // Golf 10: alleen tonen als de module echt een settings-schema
                // declareert in zijn module.json — voorkomt een dode link naar
                // een leeg formulier voor modules zonder configureerbare opties.
                $manifestPath = CF_ROOT . "/modules/{$pkg['package_slug']}/module.json";
                $hasSettings  = false;
                if (is_file($manifestPath)) {
                    $m = json_decode(file_get_contents($manifestPath), true);
                    $hasSettings = !empty($m['settings']);
                }
              ?>
              <?php if ($hasSettings): ?>
                <a class="btn-sm" href="/admin/marketplace/package/<?= htmlspecialchars($pkg['package_slug'], ENT_QUOTES) ?>/instellingen" title="Instellingen">⚙️</a>
              <?php endif; ?>
              <label class="toggle-switch" title="<?= $enabled ? 'Uitschakelen' : 'Inschakelen' ?>">
                <input type="checkbox" <?= $enabled ? 'checked' : '' ?>
                       onchange="togglePkg('<?= htmlspecialchars($pkg['package_slug'], ENT_QUOTES) ?>', this.checked)">
                <div class="toggle-track"></div>
                <div class="toggle-thumb"></div>
              </label>
              <button class="btn-sm" onclick="uninstallPkg('<?= htmlspecialchars($pkg['package_slug'], ENT_QUOTES) ?>')" title="Verwijderen">🗑️</button>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($installed)): ?>
            <div style="text-align:center;padding:3rem;color:var(--muted);">
              <p>Nog geen packages geïnstalleerd.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- TAB: UPDATES -->
      <div id="tab-updates" style="display:<?= $tab === 'updates' ? 'block' : 'none' ?>">
        <?php if (empty($updates)): ?>
          <div style="text-align:center;padding:3rem;color:var(--muted);">
            <div style="font-size:2rem;margin-bottom:.5rem;">✅</div>
            <p>Alle packages zijn up-to-date!</p>
          </div>
        <?php else: ?>
          <div style="margin-bottom:1rem;display:flex;justify-content:flex-end;">
            <button class="cf-btn" onclick="updateAll()">⬆️ Alles updaten</button>
          </div>
          <div class="installed-list">
            <?php foreach ($updates as $slug => $newVersion):
              $pkg = null;
              foreach ($installed as $i) { if ($i['package_slug'] === $slug) { $pkg = $i; break; } }
            ?>
            <div class="inst-row">
              <div class="update-dot"></div>
              <div>
                <div class="inst-name"><?= htmlspecialchars($pkg['name'] ?? $slug) ?></div>
                <div class="inst-meta">
                  v<?= htmlspecialchars($pkg['version'] ?? '?') ?> → <span style="color:var(--gold)">v<?= htmlspecialchars($newVersion) ?></span>
                </div>
              </div>
              <div class="inst-actions">
                <button class="btn-install update" onclick="updatePkg('<?= htmlspecialchars($slug, ENT_QUOTES) ?>')">
                  ⬆️ Updaten naar v<?= htmlspecialchars($newVersion) ?>
                </button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- TAB: ZIP UPLOAD -->
      <div id="tab-upload" style="display:<?= $tab === 'upload' ? 'block' : 'none' ?>">
        <div class="cf-card" style="max-width:600px;">
          <div class="cf-card-header">📦 Module / Thema installeren via ZIP</div>
          <div class="cf-card-body">
            <div class="upload-zone" id="upload-zone"
                 ondragover="event.preventDefault();this.classList.add('drag')"
                 ondragleave="this.classList.remove('drag')"
                 ondrop="handleDrop(event)"
                 onclick="document.getElementById('zip-input').click()">
              <input type="file" id="zip-input" accept=".zip" style="display:none" onchange="uploadZip(this)">
              <div class="upload-zone-icon">📦</div>
              <div class="upload-zone-text">Sleep een ZIP bestand hier naartoe</div>
              <div class="upload-zone-sub">of klik om te bladeren · Max 50MB · Alleen .zip bestanden</div>
            </div>

            <div id="install-progress">
              <div style="font-size:.82rem;color:var(--muted);margin-bottom:.4rem;" id="progress-text">Installeren...</div>
              <div class="progress-bar"><div class="progress-fill" id="progress-fill" style="width:60%"></div></div>
            </div>

            <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border);">
              <p style="font-size:.82rem;color:var(--muted);font-weight:600;margin-bottom:.6rem;">ZIP structuur vereisten:</p>
              <ul style="font-size:.8rem;color:var(--muted);list-style:none;display:flex;flex-direction:column;gap:.3rem;">
                <li>✅ <code>module.json</code> of <code>theme.json</code> in root van ZIP</li>
                <li>✅ Verplichte velden: <code>slug</code>, <code>name</code>, <code>version</code></li>
                <li>✅ PHP klassen in <code>src/</code> submap</li>
                <li>✅ Templates in <code>templates/</code> submap</li>
                <li>❌ Geen <code>..</code> of absolute paden in ZIP entries</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<div id="toast"></div>

<script>
let searchTimeout;
const CSRF = document.querySelector('input[name="_csrf_token"]')?.value || '';

function showToast(msg, type='success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = `show ${type}`;
  setTimeout(() => t.className = '', 3500);
}

function switchTab(tab) {
  ['browse','installed','updates','upload'].forEach(t => {
    document.getElementById('tab-'+t).style.display = t === tab ? 'block' : 'none';
  });
  document.querySelectorAll('.tab-btn').forEach((b,i) => {
    b.classList.toggle('active', ['browse','installed','updates','upload'][i] === tab);
  });
}

function debounceSearch(val) {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    const url = new URL(window.location);
    url.searchParams.set('search', val);
    url.searchParams.set('tab', 'browse');
    window.location = url;
  }, 400);
}

function filterType(type) {
  const url = new URL(window.location);
  url.searchParams.set('type', type);
  url.searchParams.set('tab', 'browse');
  window.location = url;
}

function sortBy(val) {
  const url = new URL(window.location);
  url.searchParams.set('sort', val);
  url.searchParams.set('tab', 'browse');
  window.location = url;
}

async function api(url, data) {
  const r = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify({ ...data, _csrf_token: CSRF }),
  });
  return r.json();
}

async function installPkg(slug, url) {
  showToast(`⏳ ${slug} installeren...`, 'warning');
  const d = await api('/admin/marketplace/install', { slug, download_url: url });
  if (d.success) { showToast(`✅ ${slug} geïnstalleerd!`); setTimeout(() => location.reload(), 1000); }
  else showToast(`❌ ${d.error}`, 'error');
}

async function uninstallPkg(slug) {
  if (!confirm(`${slug} verwijderen?`)) return;
  const d = await api('/admin/marketplace/uninstall', { slug });
  if (d.success) { showToast(`🗑️ ${slug} verwijderd`); setTimeout(() => location.reload(), 800); }
  else showToast(`❌ ${d.error}`, 'error');
}

async function togglePkg(slug, enable) {
  const d = await api('/admin/marketplace/toggle', { slug, enable: enable ? 1 : 0 });
  if (d.success) showToast(enable ? `✅ ${slug} ingeschakeld` : `⏸️ ${slug} uitgeschakeld`);
  else showToast(`❌ ${d.error}`, 'error');
}

async function updatePkg(slug) {
  showToast(`⏳ ${slug} updaten...`, 'warning');
  const d = await api('/admin/marketplace/update', { slug });
  if (d.success) { showToast(`✅ ${slug} bijgewerkt!`); setTimeout(() => location.reload(), 1000); }
  else showToast(`❌ ${d.error}`, 'error');
}

async function updateAll() {
  const updates = <?= json_encode(array_keys($updates)) ?>;
  for (const slug of updates) await updatePkg(slug);
}

function handleDrop(e) {
  e.preventDefault();
  document.getElementById('upload-zone').classList.remove('drag');
  const file = e.dataTransfer.files[0];
  if (file) uploadZipFile(file);
}

function uploadZip(input) {
  if (input.files[0]) uploadZipFile(input.files[0]);
}

function uploadZipFile(file) {
  if (!file.name.endsWith('.zip')) { showToast('❌ Alleen ZIP bestanden', 'error'); return; }
  const progress = document.getElementById('install-progress');
  const fill     = document.getElementById('progress-fill');
  const text     = document.getElementById('progress-text');
  progress.classList.add('show');
  text.textContent = `Uploaden: ${file.name}...`;

  const fd = new FormData();
  fd.append('package', file);
  fd.append('_csrf_token', CSRF);

  fetch('/admin/marketplace/upload', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      progress.classList.remove('show');
      if (d.success) { showToast(`✅ ${d.package?.name} geïnstalleerd!`); setTimeout(() => location.reload(), 1000); }
      else showToast(`❌ ${d.error}`, 'error');
    })
    .catch(() => { progress.classList.remove('show'); showToast('❌ Upload mislukt', 'error'); });
}
</script>

</body>
</html>
