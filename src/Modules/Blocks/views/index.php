<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $zones, $allTypes, $placed zijn beschikbaar vanuit BlockController::index()
//
// De pagina-JS haalde tot v1.16.0 een CSRF-token op via
// `document.querySelector('meta[name="csrf"]')?.content` — een meta-tag die
// nergens op deze pagina bestond, dus CSRF was altijd een lege string en
// iedere fetch()-actie hier (blok toevoegen/verwijderen/herordenen) werd
// door CsrfProtection::validateRequest() afgewezen. Gefixt met hetzelfde,
// wél werkende patroon als Marketplace/views/index.php: een verborgen
// `_csrf_token`-input die CsrfProtection::field() rendert.
use CommunityFusion\Core\Security\CsrfProtection;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\CommunityFusion\Core\I18n\Trans::locale(), ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Blokken Beheer — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<style>
  :root { --sidebar-w: 240px; }
  .admin-wrap  { display:flex; min-height:100vh; }
  .admin-sidebar {
    width: var(--sidebar-w); background: var(--surface);
    border-right:1px solid var(--border); display:flex;
    flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:50;
  }
  .admin-logo { padding:1.2rem 1.5rem; font-size:1.1rem; font-weight:800;
    background:linear-gradient(135deg,#a855f7,#6c3df4); -webkit-background-clip:text;
    -webkit-text-fill-color:transparent; border-bottom:1px solid var(--border); }
  .admin-nav { padding:1rem; }
  .admin-nav-link { display:flex;align-items:center;gap:.7rem;padding:.55rem .9rem;
    border-radius:8px;font-size:.875rem;color:var(--text-dim);transition:all .15s;margin-bottom:2px; }
  .admin-nav-link:hover,.admin-nav-link.active { background:rgba(108,61,244,.15);color:var(--accent2); }
  .admin-main { margin-left:var(--sidebar-w); flex:1; }
  .admin-topbar { height:56px;background:rgba(17,24,39,.95);border-bottom:1px solid var(--border);
    display:flex;align-items:center;justify-content:space-between;padding:0 1.5rem;
    position:sticky;top:0;z-index:40; }
  .admin-content { padding:2rem; }

  /* Layout builder */
  .block-builder {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 1.5rem;
    align-items: start;
  }

  /* Block palette */
  .palette {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    position: sticky;
    top: 80px;
  }
  .palette-header {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid var(--border);
    font-weight: 700;
    font-size: .875rem;
    display: flex;
    align-items: center;
    gap: .5rem;
  }
  .palette-item {
    display: flex;
    align-items: center;
    gap: .8rem;
    padding: .7rem 1.2rem;
    cursor: grab;
    border-bottom: 1px solid rgba(255,255,255,.04);
    font-size: .85rem;
    transition: background .15s;
    user-select: none;
  }
  .palette-item:hover { background: rgba(108,61,244,.1); }
  .palette-item:active { cursor: grabbing; }
  .palette-item-icon { font-size: 1.2rem; width: 28px; text-align: center; }
  .palette-item-name { font-weight: 600; }
  .palette-item-desc { font-size: .75rem; color: var(--muted); margin-top: .1rem; }

  /* Zones */
  .zones-grid {
    display: flex;
    flex-direction: column;
    gap: 1rem;
  }

  /* Site preview wrapper */
  .site-preview {
    background: rgba(255,255,255,.02);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
  }
  .preview-label {
    padding: .5rem 1rem;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--muted);
    border-bottom: 1px solid var(--border);
    background: rgba(255,255,255,.02);
  }
  .preview-body {
    padding: 1rem;
    display: grid;
    grid-template-areas:
      "header header header"
      "topmenu topmenu topmenu"
      "sidebar_left content sidebar_right"
      "footer footer footer";
    grid-template-columns: 180px 1fr 180px;
    grid-template-rows: auto auto 1fr auto;
    gap: .75rem;
    min-height: 500px;
  }

  /* Zone droptargets */
  .zone-drop {
    border: 2px dashed var(--border);
    border-radius: 8px;
    min-height: 60px;
    padding: .5rem;
    transition: border-color .2s, background .2s;
    position: relative;
  }
  .zone-drop[data-zone="header"]        { grid-area: header; }
  .zone-drop[data-zone="topmenu"]       { grid-area: topmenu; }
  .zone-drop[data-zone="sidebar_left"]  { grid-area: sidebar_left; min-height: 200px; }
  .zone-drop[data-zone="content"]       { grid-area: content; min-height: 200px; }
  .zone-drop[data-zone="sidebar_right"] { grid-area: sidebar_right; min-height: 200px; }
  .zone-drop[data-zone="footer"]        { grid-area: footer; }

  .zone-drop.drag-over { border-color: var(--accent); background: rgba(108,61,244,.06); }
  .zone-label {
    font-size: .65rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .08em; color: var(--muted);
    margin-bottom: .4rem;
  }
  .zone-empty {
    text-align: center; color: var(--border);
    font-size: .78rem; padding: .8rem;
  }

  /* Geplaatste blocks */
  .placed-block {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: .5rem .7rem;
    margin-bottom: .4rem;
    display: flex;
    align-items: center;
    gap: .6rem;
    font-size: .82rem;
    cursor: grab;
    transition: border-color .15s, box-shadow .15s;
    position: relative;
  }
  .placed-block:hover { border-color: var(--accent); box-shadow: 0 2px 8px rgba(108,61,244,.2); }
  .placed-block.dragging { opacity: .4; }
  .placed-block .block-drag-handle { color: var(--muted); cursor: grab; font-size: .9rem; }
  .placed-block .block-name { flex: 1; font-weight: 600; }
  .placed-block .block-type { color: var(--muted); font-size: .72rem; }
  .placed-block .block-actions { display:flex; gap:.3rem; }
  .block-btn {
    padding: .2rem .5rem; border-radius: 4px; font-size: .7rem;
    border: 1px solid var(--border); background: transparent;
    color: var(--muted); cursor: pointer; transition: all .15s;
  }
  .block-btn:hover { border-color: var(--accent2); color: var(--accent2); }
  .block-btn.delete:hover { border-color: var(--error); color: var(--error); }
  .block-btn:disabled { opacity: .3; cursor: default; pointer-events: none; }
  .block-btn:focus-visible { outline: 2px solid var(--accent2); outline-offset: 1px; }
  .block-vis { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .block-vis.on  { background: var(--success); }
  .block-vis.off { background: var(--muted); }

  /* Toast */
  #toast {
    position: fixed; bottom: 1.5rem; right: 1.5rem;
    padding: .7rem 1.2rem; border-radius: 8px;
    background: var(--success); color: #fff;
    font-size: .875rem; font-weight: 600;
    transform: translateY(100px); opacity: 0;
    transition: all .3s; z-index: 999;
    box-shadow: 0 4px 20px rgba(0,0,0,.4);
  }
  #toast.show { transform: translateY(0); opacity: 1; }
  #toast.error { background: var(--error); }

  /* Add block modal */
  .modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.7); z-index: 200;
    align-items: center; justify-content: center;
  }
  .modal-overlay.open { display: flex; }
  .modal {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-lg); width: 500px; max-width: 95vw;
    max-height: 85vh; overflow-y: auto;
  }
  .modal-header {
    padding: 1.2rem 1.5rem; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    font-weight: 700;
  }
  .modal-body { padding: 1.5rem; }
  .modal-close { background: none; border: none; color: var(--muted); cursor: pointer; font-size: 1.3rem; }
</style>
</head>
<body>
<?= CsrfProtection::field() ?>
<div class="admin-wrap">

  <!-- Sidebar (zelfde als dashboard) -->
  <aside class="admin-sidebar">
    <div class="admin-logo">🔮 Blueprint CMS</div>
    <nav class="admin-nav">
      <a href="/admin" class="admin-nav-link">📊 Dashboard</a>
      <a href="/admin/news" class="admin-nav-link">📰 Nieuws</a>
      <a href="/admin/pages" class="admin-nav-link">📄 Pagina's</a>
      <a href="/admin/users" class="admin-nav-link">👥 Gebruikers</a>
      <a href="/admin/blocks" class="admin-nav-link active">🧩 Blokken</a>
      <a href="/admin/themes" class="admin-nav-link">🎨 Thema's</a>
      <a href="/admin/modules" class="admin-nav-link">⚙️ Modules</a>
      <a href="/admin/settings" class="admin-nav-link">🛠️ Instellingen</a>
      <a href="/" class="admin-nav-link">🌐 Bekijk Site</a>
      <a href="/logout" class="admin-nav-link">👋 Uitloggen</a>
    </nav>
  </aside>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>🧩 Blokken Beheer</h1>
      <div style="display:flex;gap:.8rem;align-items:center;">
        <span style="font-size:.78rem;color:var(--muted);">Drag & Drop om te herordenen · ✏️ om een blok te bewerken</span>
        <button class="cf-btn" onclick="openAddModal()">+ Blok Toevoegen</button>
      </div>
    </header>

    <div class="admin-content">
      <div class="block-builder">

        <!-- Block Palette -->
        <div class="palette">
          <div class="palette-header">🎯 Beschikbare Blokken</div>
          <?php
          $typeIcons = [
            'text'          => '📝',
            'html'          => '🖥️',
            'news-latest'   => '📰',
            'login'         => '🔐',
            'site-stats'    => '📊',
            'advertisement' => '📣',
            'discord-widget'=> '🎮',
            'twitch-live'   => '📺',
          ];
          foreach ($allTypes as $slug => $type):
            $icon = $typeIcons[$slug] ?? '📦';
          ?>
          <div class="palette-item"
               draggable="true"
               data-type="<?= htmlspecialchars($slug) ?>"
               data-name="<?= htmlspecialchars($type->getName()) ?>"
               ondragstart="paletteDragStart(event)">
            <div>
              <div style="font-size:1.3rem;"><?= $icon ?></div>
            </div>
            <div>
              <div class="palette-item-name"><?= htmlspecialchars($type->getName()) ?></div>
              <div class="palette-item-desc"><?= htmlspecialchars($slug) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Zone Builder -->
        <div class="zones-grid">
          <div class="site-preview">
            <div class="preview-label">🖼️ Site Layout — Sleep blokken naar een zone</div>
            <div class="preview-body">
              <?php foreach ($zones as $zoneSlug => $zoneLabel):
                $blocks = $placed[$zoneSlug] ?? [];
              ?>
              <div class="zone-drop"
                   data-zone="<?= $zoneSlug ?>"
                   ondragover="event.preventDefault();this.classList.add('drag-over')"
                   ondragleave="this.classList.remove('drag-over')"
                   ondrop="handleDrop(event, '<?= $zoneSlug ?>')">

                <div class="zone-label"><?= htmlspecialchars($zoneLabel) ?></div>

                <?php if (empty($blocks)): ?>
                  <div class="zone-empty">Sleep een blok hier naartoe</div>
                <?php else: ?>
                  <?php foreach ($blocks as $block):
                    $isVisible = (bool) $block['is_visible'];
                  ?>
                  <div class="placed-block"
                       draggable="true"
                       data-block-id="<?= $block['id'] ?>"
                       data-zone="<?= $zoneSlug ?>"
                       ondragstart="blockDragStart(event)">
                    <span class="block-drag-handle">⠿</span>
                    <div class="block-vis <?= $isVisible ? 'on' : 'off' ?>"></div>
                    <div>
                      <div class="block-name"><?= htmlspecialchars($block['title'] ?: $block['type_slug']) ?></div>
                      <div class="block-type"><?= htmlspecialchars($block['type_slug']) ?></div>
                    </div>
                    <div class="block-actions">
                      <button type="button" class="block-btn block-up" title="Omhoog" aria-label="Omhoog" onclick="stepBlock(<?= (int) $block['id'] ?>, -1)">▲</button>
                      <button type="button" class="block-btn block-down" title="Omlaag" aria-label="Omlaag" onclick="stepBlock(<?= (int) $block['id'] ?>, 1)">▼</button>
                      <a class="block-btn" href="/admin/blocks/<?= (int) $block['id'] ?>/edit" title="Bewerken" style="text-decoration:none;">✏️</a>
                      <button class="block-btn" onclick="toggleVisible(<?= $block['id'] ?>, <?= $isVisible ? 0 : 1 ?>)">
                        <?= $isVisible ? '👁️' : '🚫' ?>
                      </button>
                      <button class="block-btn delete" onclick="deleteBlock(<?= $block['id'] ?>)">🗑️</button>
                    </div>
                  </div>
                  <?php endforeach; ?>
                <?php endif; ?>

              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<!-- Add Block Modal -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="modal-header">
      ➕ Blok Toevoegen
      <button class="modal-close" onclick="closeAddModal()">✕</button>
    </div>
    <div class="modal-body">
      <form id="addBlockForm">
        <div class="cf-form-group">
          <label class="cf-label">Block Type</label>
          <select class="cf-select" name="type_slug" id="modalTypeSlug">
            <?php foreach ($allTypes as $slug => $type): ?>
              <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($type->getName()) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="cf-form-group">
          <label class="cf-label">Zone</label>
          <select class="cf-select" name="zone" id="modalZone">
            <?php foreach ($zones as $z => $label): ?>
              <option value="<?= $z ?>"><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="cf-form-group">
          <label class="cf-label">Titel (optioneel)</label>
          <input class="cf-input" type="text" name="title" placeholder="Blok-titel boven de content">
        </div>
        <div style="display:flex;justify-content:flex-end;gap:.8rem;margin-top:1.5rem;">
          <button type="button" class="cf-btn cf-btn-ghost" onclick="closeAddModal()">Annuleren</button>
          <button type="submit" class="cf-btn">Toevoegen →</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
const CSRF = document.querySelector('input[name="_csrf_token"]')?.value || '';

// ── Toast ──────────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = type === 'error' ? 'show error' : 'show';
  setTimeout(() => t.className = '', 3000);
}

// ── Drag & Drop Palette → Zone ─────────────────────────────────────────────
let dragType = null;
let dragBlockId = null;
let dragSourceZone = null;

function paletteDragStart(e) {
  dragType = e.currentTarget.dataset.type;
  dragBlockId = null;
  e.dataTransfer.effectAllowed = 'copy';
}

function blockDragStart(e) {
  dragBlockId = parseInt(e.currentTarget.dataset.blockId);
  dragSourceZone = e.currentTarget.dataset.zone;
  dragType = null;
  e.dataTransfer.effectAllowed = 'move';
  e.currentTarget.classList.add('dragging');
}

function handleDrop(e, zone) {
  e.preventDefault();
  e.currentTarget.classList.remove('drag-over');

  if (dragType) {
    // Nieuw blok vanuit palette
    addBlock(dragType, zone);
  } else if (dragBlockId) {
    // Bestaand blok verplaatsen
    moveBlock(dragBlockId, zone);
  }

  document.querySelectorAll('.placed-block.dragging').forEach(el => el.classList.remove('dragging'));
  dragType = null; dragBlockId = null; dragSourceZone = null;
}

// ── API calls ─────────────────────────────────────────────────────────────
async function api(method, url, body = null) {
  const opts = { method, headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
  // CSRF-token hoort in de body: de const werd hier al sinds v1.16.0
  // uitgelezen maar nooit daadwerkelijk meegestuurd, dus elke actie op dit
  // scherm (blok toevoegen/verplaatsen/verwijderen/herordenen) werd door
  // CsrfProtection::validateRequest() afgewezen — samen met het feit dat
  // JSON-bodies vóór de Request-fix server-side sowieso nooit werden
  // uitgelezen, stond dit hele scherm dus non-functioneel. Zelfde patroon
  // als Marketplace/views/index.php. Gevonden tijdens de S13-inventarisatiepas.
  opts.body = JSON.stringify({ ...(body || {}), _csrf_token: CSRF });
  const r = await fetch(url, opts);
  return r.json();
}

async function addBlock(typeSlug, zone, title = '') {
  const d = await api('POST', '/admin/blocks/store', { type_slug: typeSlug, zone, title, config: {} });
  if (d.success) {
    showToast('✅ Blok toegevoegd');
    // Heeft het bloktype instellingen? Ga dan direct naar het bewerkscherm.
    setTimeout(() => { if (d.edit_url) location.href = d.edit_url; else location.reload(); }, 600);
  }
  else showToast(d.error || 'Fout', 'error');
}

async function moveBlock(blockId, newZone) {
  const d = await api('POST', `/admin/blocks/${blockId}/update`, { zone: newZone });
  if (d.success) { showToast('✅ Blok verplaatst'); setTimeout(() => location.reload(), 500); }
  else showToast(d.error || 'Fout', 'error');
}

async function deleteBlock(id) {
  if (!confirm('Dit blok verwijderen?')) return;
  const d = await api('POST', `/admin/blocks/${id}/delete`, {});
  if (d.success) { showToast('🗑️ Blok verwijderd'); setTimeout(() => location.reload(), 500); }
  else showToast(d.error || 'Fout', 'error');
}

async function toggleVisible(id, vis) {
  const d = await api('POST', `/admin/blocks/${id}/update`, { is_visible: vis });
  if (d.success) { showToast('👁️ Zichtbaarheid bijgewerkt'); setTimeout(() => location.reload(), 500); }
  else showToast(d.error || 'Fout', 'error');
}

// Omhoog/omlaag met de pijltjes: wissel met de buur in dezelfde zone en sla de volgorde op.
async function stepBlock(id, dir) {
  const el = document.querySelector(`.placed-block[data-block-id="${id}"]`);
  if (!el) return;
  const zone = el.closest('.zone-drop');
  const list = [...zone.querySelectorAll('.placed-block')];
  const i = list.indexOf(el), j = i + dir;
  if (j < 0 || j >= list.length) return;
  [list[i], list[j]] = [list[j], list[i]];
  const positions = {};
  list.forEach((b, n) => { positions[parseInt(b.dataset.blockId)] = n; });
  const d = await api('POST', '/api/v1/blocks/positions', { zone: zone.dataset.zone, positions });
  if (d.success) { showToast('✅ Volgorde opgeslagen'); setTimeout(() => location.reload(), 350); }
  else showToast(d.error || 'Fout', 'error');
}

// Bovenste blok: ▲ uit; onderste blok: ▼ uit.
document.querySelectorAll('.zone-drop').forEach(zone => {
  const list = zone.querySelectorAll('.placed-block');
  list.forEach((b, n) => {
    if (n === 0) b.querySelector('.block-up')?.setAttribute('disabled', '');
    if (n === list.length - 1) b.querySelector('.block-down')?.setAttribute('disabled', '');
  });
});

// Sortable binnen een zone (her-ordening op positie)
document.querySelectorAll('.zone-drop').forEach(zone => {
  zone.addEventListener('drop', async (e) => {
    // Na drop: herorder de blocks in de zone en sla op
    const zone_slug = zone.dataset.zone;
    const blocks = [...zone.querySelectorAll('.placed-block')];
    const positions = {};
    blocks.forEach((b, i) => { positions[parseInt(b.dataset.blockId)] = i; });
    if (Object.keys(positions).length > 0) {
      await api('POST', '/api/v1/blocks/positions', { zone: zone_slug, positions });
    }
  });
});

// ── Modal ─────────────────────────────────────────────────────────────────
function openAddModal() { document.getElementById('addModal').classList.add('open'); }
function closeAddModal() { document.getElementById('addModal').classList.remove('open'); }

document.getElementById('addBlockForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  await addBlock(fd.get('type_slug'), fd.get('zone'), fd.get('title') || '');
  closeAddModal();
});

document.getElementById('addModal').addEventListener('click', (e) => {
  if (e.target === e.currentTarget) closeAddModal();
});
</script>

<?php include CF_ROOT . '/src/Modules/Shared/views/admin_mobile.php'; ?>
</body>
</html>
