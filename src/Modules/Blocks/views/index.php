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
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>
  /* Layout builder */
  .block-builder {
    display: grid;
    grid-template-columns: 280px minmax(0, 1fr);
    gap: 1.5rem;
    align-items: start;
  }
  @media (max-width: 1000px) {
    .block-builder { grid-template-columns: minmax(0, 1fr); }
    .palette { position: static; }
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
    grid-template-columns: minmax(170px, 1fr) minmax(0, 2fr) minmax(170px, 1fr);
    grid-template-rows: auto auto 1fr auto;
    gap: .75rem;
    min-height: 500px;
  }

  /* Zone droptargets */
  @media (max-width: 700px) {
    .preview-body {
      grid-template-areas: "header" "topmenu" "content" "sidebar_left" "sidebar_right" "footer";
      grid-template-columns: minmax(0, 1fr); grid-template-rows: none; min-height: 0;
    }
  }
  .zone-drop {
    min-width: 0;
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
    flex-wrap: wrap;           /* knoppen schuiven naar een tweede regel in smalle zones i.p.v. buiten de zone te vallen */
    min-width: 0;
  }
  .placed-block > div:not(.block-actions):not(.block-vis) { flex: 1 1 6rem; min-width: 0; overflow-wrap: anywhere; }
  .placed-block:hover { border-color: var(--accent); box-shadow: 0 2px 8px rgba(108,61,244,.2); }
  .placed-block.dragging { opacity: .4; }
  .placed-block .block-drag-handle { color: var(--muted); cursor: grab; font-size: .9rem; }
  .placed-block .block-name { flex: 1; font-weight: 600; }
  .placed-block .block-type { color: var(--muted); font-size: .72rem; }
  .placed-block .block-actions { display:flex; gap:.3rem; margin-left:auto; }
  .block-btn {
    padding: .2rem .5rem; border-radius: 4px; font-size: .7rem;
    border: 1px solid var(--border); background: transparent;
    color: var(--muted); cursor: pointer; transition: all .15s;
  }
  .block-btn:hover { border-color: var(--accent2); color: var(--accent2); }
  .modal :focus-visible, .block-btn:focus-visible, .palette-item:focus-visible { outline: 2px solid var(--accent2); outline-offset: 2px; }
  .block-btn.delete:hover { border-color: var(--error); color: var(--error); }
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
    color-scheme: dark;
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

  <?php $activeNav = 'blocks'; include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>🧩 Blokken Beheer</h1>
      <div style="display:flex;gap:.8rem;align-items:center;flex-wrap:wrap;">
        <span style="font-size:.78rem;color:var(--muted);">Sleep blokken naar een zone, of gebruik "Blok toevoegen" · Wijzigingen worden direct opgeslagen</span>
        <button type="button" class="cf-btn" onclick="openAddModal()">+ Blok Toevoegen</button>
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
            if ($slug === 'markup' && empty($canMarkupPalette)) { continue; }   // zonder recht geen Markup-blok in het palet
            $icon = $typeIcons[$slug] ?? '📦';
          ?>
          <div class="palette-item"
               draggable="true"
               data-type="<?= htmlspecialchars($slug) ?>"
               data-name="<?= htmlspecialchars($type->getName()) ?>"
               data-fields="<?= htmlspecialchars((string) json_encode(\CommunityFusion\Core\Block\BlockSettings::describe($type->getConfigSchema()), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES) ?>"
               ondragstart="paletteDragStart(event)" ondragend="dragEnd()">
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

                <div class="zone-label"><?= htmlspecialchars($zoneLabel) ?>
                  <button type="button" class="block-btn" title="Voorbeeld van deze zone" aria-label="Voorbeeld van <?= htmlspecialchars($zoneLabel) ?>" onclick="zonePreview('<?= $zoneSlug ?>', '<?= htmlspecialchars($zoneLabel, ENT_QUOTES) ?>')">👁 Voorbeeld</button></div>

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
                       ondragstart="blockDragStart(event)" ondragend="dragEnd()">
                    <span class="block-drag-handle">⠿</span>
                    <div class="block-vis <?= $isVisible ? 'on' : 'off' ?>"></div>
                    <div>
                      <div class="block-name"><?= htmlspecialchars($block['title'] ?: $block['type_slug']) ?></div>
                      <div class="block-type"><?= htmlspecialchars($block['type_slug']) ?></div>
                    </div>
                    <div class="block-actions">
                      <button type="button" class="block-btn" title="<?= $isVisible ? 'Verbergen' : 'Tonen' ?>" aria-label="<?= $isVisible ? 'Blok verbergen' : 'Blok tonen' ?>" onclick="toggleVisible(<?= $block['id'] ?>, <?= $isVisible ? 0 : 1 ?>)">
                        <?= $isVisible ? '👁️' : '🚫' ?>
                      </button>
                      <button type="button" class="block-btn" title="Instellingen" aria-label="Instellingen van dit blok" onclick="openSettings(<?= (int) $block['id'] ?>)">⚙️</button>
                      <button type="button" class="block-btn delete" title="Verwijderen" aria-label="Blok verwijderen" onclick="deleteBlock(<?= $block['id'] ?>)">🗑️</button>
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

<!-- Blok-instellingen (gegenereerd uit getConfigSchema() van het blocktype) -->
<div class="modal-overlay" id="settingsModal">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="setTitle">
    <div class="modal-header">
      <span id="setTitle">⚙️ Instellingen</span>
      <button class="modal-close" type="button" onclick="closeSettings()" aria-label="Sluiten">✕</button>
    </div>
    <div class="modal-body">
      <form id="settingsForm" autocomplete="off">
        <div class="cf-form-group">
          <label class="cf-label" for="set-title">Titel (optioneel)</label>
          <input class="cf-input" type="text" id="set-title" maxlength="200">
        </div>
        <div id="settingsFields"></div>
        <div id="markupSection" hidden style="margin-top:1rem;border-top:1px solid var(--border);padding-top:1rem;">
          <div class="cf-label" id="markupHead">Markup</div>
          <p id="markupNote" style="color:var(--muted);font-size:.8rem;margin:.3rem 0 .6rem;"></p>
          <textarea class="cf-input" id="markupText" rows="10" spellcheck="false" style="font-family:monospace;white-space:pre;" aria-label="Markup"></textarea>
          <div style="display:flex;gap:.6rem;margin-top:.6rem;">
            <button type="button" class="cf-btn cf-btn-ghost" id="markupPreviewBtn">👁 Voorbeeld</button>
            <button type="button" class="cf-btn" id="markupSaveBtn">Markup opslaan</button>
          </div>
          <p id="markupErr" role="alert" style="color:#ef4444;font-size:.85rem;display:none;"></p>
          <iframe id="markupFrame" title="Voorbeeld" sandbox="" referrerpolicy="no-referrer" hidden style="width:100%;height:220px;margin-top:.6rem;border:1px solid var(--border);border-radius:8px;background:#0a0c14;"></iframe>
        </div>
        <p id="settingsErr" role="alert" style="color:#ef4444;font-size:.85rem;display:none;"></p>
        <div style="display:flex;justify-content:flex-end;gap:.8rem;margin-top:1.5rem;">
          <button type="button" class="cf-btn cf-btn-ghost" onclick="closeSettings()">Annuleren</button>
          <button type="submit" class="cf-btn" id="setSave">Opslaan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Zone-voorbeeld -->
<div class="modal-overlay" id="zoneModal">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="zoneTitle" style="max-width:720px;width:95%;">
    <div class="modal-header"><span id="zoneTitle">Voorbeeld</span><button class="modal-close" type="button" onclick="hideModal(document.getElementById('zoneModal'))" aria-label="Sluiten">✕</button></div>
    <div class="modal-body"><iframe id="zoneFrame" title="Zone-voorbeeld" sandbox="" referrerpolicy="no-referrer" style="width:100%;height:420px;border:1px solid var(--border);border-radius:8px;background:#0a0c14;"></iframe>
    <p style="color:var(--muted);font-size:.78rem;margin-top:.5rem;">Zoals bezoekers de zichtbare blokken van deze zone zien (zonder scripts en zonder site-CSS).</p></div>
  </div>
</div>

<!-- Add Block Modal -->
<div class="modal-overlay" id="addModal">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addTitle">
    <div class="modal-header">
      <span id="addTitle">➕ Blok Toevoegen</span>
      <button class="modal-close" type="button" onclick="closeAddModal()" aria-label="Sluiten">✕</button>
    </div>
    <div class="modal-body">
      <form id="addBlockForm">
        <div class="cf-form-group">
          <label class="cf-label" for="modalTypeSlug">Blocktype</label>
          <select class="cf-select" name="type_slug" id="modalTypeSlug">
            <?php foreach ($allTypes as $slug => $type): ?>
              <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($type->getName()) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="cf-form-group">
          <label class="cf-label" for="modalZone">Zone</label>
          <select class="cf-select" name="zone" id="modalZone">
            <?php foreach ($zones as $z => $label): ?>
              <option value="<?= $z ?>"><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="cf-form-group">
          <label class="cf-label" for="modalTitle">Titel (optioneel)</label>
          <input class="cf-input" type="text" name="title" id="modalTitle" placeholder="Blok-titel boven de content">
        </div>
        <div style="display:flex;justify-content:flex-end;gap:.8rem;margin-top:1.5rem;">
          <button type="button" class="cf-btn cf-btn-ghost" onclick="closeAddModal()">Annuleren</button>
          <button type="submit" class="cf-btn">Toevoegen →</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div id="toast" role="status" aria-live="polite"></div>

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

function dragEnd() {
  document.querySelectorAll('.placed-block.dragging').forEach(el => el.classList.remove('dragging'));
  document.querySelectorAll('.zone-drop.drag-over').forEach(el => el.classList.remove('drag-over'));
  dragType = null; dragBlockId = null; dragSourceZone = null;
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
  try {
    const r = await fetch(url, opts);
    const d = await r.json().catch(() => null);
    return d ?? { error: `Fout ${r.status}` };   // geen JSON (bv. een 403- of 500-pagina): toch een nette melding
  } catch {
    return { error: 'Geen verbinding met de server' };
  }
}

// Blocktypes met verplichte instellingen (bv. Tekst, HTML) kunnen niet met een lege config worden aangemaakt:
// dan openen we eerst het instellingenvenster in "nieuw blok"-modus. Overige types worden meteen geplaatst.
function paletteItem(typeSlug) { return document.querySelector(`.palette-item[data-type="${CSS.escape(typeSlug)}"]`); }
async function addBlock(typeSlug, zone, title = '') {
  const item = paletteItem(typeSlug);
  let fields = [];
  try { fields = JSON.parse(item?.dataset.fields || '[]'); } catch { fields = []; }
  if (fields.some((f) => f.required)) { openCreate(typeSlug, item.dataset.name || typeSlug, zone, title, fields); return true; }
  const d = await api('POST', '/admin/blocks/store', { type_slug: typeSlug, zone, title, config: {} });
  if (d.success) { showToast('✅ Blok toegevoegd'); setTimeout(() => location.reload(), 800); return true; }
  if (d.fields) { openCreate(typeSlug, item?.dataset.name || typeSlug, zone, title, fields, d); return true; }
  showToast(d.error || 'Fout', 'error');
  return false;
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


// ── Blok-instellingen: generieke renderer op basis van het schema ─────────
let settingsBlockId = null;
let createCtx = null;   // {type, zone} zolang het instellingenvenster een nieuw blok aanmaakt

// Modals: focus naar binnen bij openen, terug naar de opener bij sluiten, Esc sluit, Tab blijft binnen het venster.
const modalOpener = new Map();
function showModal(el, focusSel) {
  modalOpener.set(el.id, document.activeElement);
  el.classList.add('open');
  const target = (focusSel && el.querySelector(focusSel)) || el.querySelector('input:not([type=hidden]), select, textarea, button');
  target?.focus();
}
function hideModal(el) {
  el.classList.remove('open');
  const back = modalOpener.get(el.id); modalOpener.delete(el.id);
  if (back && document.contains(back)) back.focus();
}
document.addEventListener('keydown', (e) => {
  const open = [...document.querySelectorAll('.modal-overlay.open')].pop();
  if (!open) return;
  if (e.key === 'Escape') { e.preventDefault(); open.id === 'settingsModal' ? closeSettings() : open.id === 'addModal' ? closeAddModal() : hideModal(open); return; }
  if (e.key === 'Tab') {
    const f = [...open.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea')].filter((x) => !x.disabled && x.offsetParent !== null);
    if (!f.length) return;
    const first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }
});

function closeSettings() { hideModal(document.getElementById('settingsModal')); settingsBlockId = null; createCtx = null; }

function settingsField(f, value) {
  const wrap = document.createElement('div');
  wrap.className = 'cf-form-group';
  const id = 'set-f-' + f.key;
  const label = document.createElement('label');
  label.className = 'cf-label'; label.htmlFor = id;
  label.textContent = f.label + (f.required ? ' *' : '');
  let input;
  if (f.type === 'boolean') {
    input = document.createElement('input'); input.type = 'checkbox'; input.checked = !!value;
    label.prepend(input, ' ');
    input.id = id; wrap.appendChild(label);
  } else {
    if (f.type === 'select') {
      input = document.createElement('select'); input.className = 'cf-select';
      for (const o of f.options) { const op = document.createElement('option'); op.value = o.value; op.textContent = o.label; input.appendChild(op); }
      input.value = value ?? f.default ?? '';
    } else if (f.type === 'textarea' || f.type === 'code') {
      input = document.createElement('textarea'); input.className = 'cf-input'; input.rows = f.type === 'code' ? 8 : 4;
      if (f.type === 'code') input.style.fontFamily = 'monospace';
      input.value = value ?? '';
    } else {
      input = document.createElement('input'); input.className = 'cf-input';
      input.type = f.type === 'integer' ? 'number' : (f.type === 'url' ? 'url' : 'text');
      if (f.type === 'integer') { if (f.min !== null) input.min = f.min; if (f.max !== null) input.max = f.max; }
      input.value = value ?? '';
    }
    input.id = id; wrap.append(label, input);
  }
  input.dataset.key = f.key; input.dataset.type = f.type;
  if (f.required && f.type !== 'boolean') input.required = true;
  if (f.help) { const h = document.createElement('small'); h.style.color = 'var(--muted)'; h.textContent = f.help; wrap.appendChild(h); }
  return wrap;
}

async function openSettings(id) {
  const r = await fetch(`/admin/blocks/${id}/settings`, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' });
  const d = await r.json().catch(() => ({}));
  if (!r.ok) { showToast(d.error || 'Kon instellingen niet laden', 'error'); return; }
  settingsBlockId = id; createCtx = null;
  document.getElementById('setTitle').textContent = '⚙️ ' + d.name;
  document.getElementById('set-title').value = d.title || '';
  document.getElementById('settingsErr').style.display = 'none';
  const box = document.getElementById('settingsFields');
  box.replaceChildren(...d.fields.map((f) => settingsField(f, d.config[f.key])));
  if (!d.fields.length) { const p = document.createElement('p'); p.style.color = 'var(--muted)'; p.textContent = 'Dit blok heeft geen eigen instellingen.'; box.appendChild(p); }
  setupMarkup(d);
  document.getElementById('setSave').textContent = 'Opslaan';
  showModal(document.getElementById('settingsModal'), '#set-title');
}

// "Nieuw blok"-modus: dezelfde gegenereerde velden, maar opslaan maakt het blok aan (POST /admin/blocks/store).
function openCreate(typeSlug, name, zone, title, fields, errResponse = null) {
  settingsBlockId = null; createCtx = { type: typeSlug, zone };
  document.getElementById('setTitle').textContent = '➕ ' + name;
  document.getElementById('set-title').value = title || '';
  const box = document.getElementById('settingsFields');
  box.replaceChildren(...fields.map((f) => settingsField(f, f.default)));
  document.getElementById('markupSection').hidden = true;
  markupFrameReset();
  const err = document.getElementById('settingsErr');
  err.style.display = 'none';
  if (errResponse) showSettingsError(errResponse);
  document.getElementById('setSave').textContent = 'Toevoegen →';
  showModal(document.getElementById('settingsModal'), '#settingsFields [data-key]');
}
function showSettingsError(d) {
  const err = document.getElementById('settingsErr');
  err.textContent = (d.error || 'Fout') + (d.fields ? ' — ' + Object.entries(d.fields).map(([k, v]) => `${k}: ${v}`).join('; ') : '');
  err.style.display = 'block';
}

document.getElementById('settingsForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (settingsBlockId === null && createCtx === null) return;
  const config = {};
  document.querySelectorAll('#settingsFields [data-key]').forEach((el) => {
    config[el.dataset.key] = el.dataset.type === 'boolean' ? (el.checked ? '1' : '0') : el.value;
  });
  const title = document.getElementById('set-title').value;
  const saveBtn = document.getElementById('setSave');
  saveBtn.disabled = true;
  try {
    const d = createCtx
      ? await api('POST', '/admin/blocks/store', { type_slug: createCtx.type, zone: createCtx.zone, title, config })
      : await api('POST', `/admin/blocks/${settingsBlockId}/update`, { title, config });
    if (d.success) { showToast(createCtx ? '✅ Blok toegevoegd' : '✅ Instellingen opgeslagen'); closeSettings(); setTimeout(() => location.reload(), 500); return; }
    showSettingsError(d);
  } finally { saveBtn.disabled = false; }
});
document.getElementById('settingsModal').addEventListener('click', (e) => { if (e.target === e.currentTarget) closeSettings(); });


// ── Markup-sectie (alleen met recht blocks.override_template) ─────────────
let markupScope = null;
const mEl = (id) => document.getElementById(id);
function markupError(msg) { const e = mEl('markupErr'); e.textContent = msg || ''; e.style.display = msg ? 'block' : 'none'; }
function setupMarkup(d) {
  const sec = mEl('markupSection');
  sec.hidden = !d.can_markup || !d.markup;
  markupFrameReset();
  if (sec.hidden) return;
  markupScope = d.markup.scope;
  mEl('markupText').value = d.markup.value || '';
  mEl('markupHead').textContent = d.markup.scope === 'instance' ? 'Markup van dit blok' : `Sjabloon voor alle "${d.markup.slug}"-blokken`;
  mEl('markupNote').textContent = d.markup.scope === 'instance'
    ? 'HTML en Twig in een sandbox. Beschikbaar: title en today. PHP wordt nooit uitgevoerd.'
    : 'Vervangt de standaard-HTML van ALLE blokken van dit type. Beschikbaar: title, today en config.<naam>. Leeg laten en opslaan = terug naar standaard.' + (d.markup.has_override ? ' (Er is nu een eigen sjabloon actief.)' : '');
  markupError('');
}
function markupFrameReset() { const f = mEl('markupFrame'); f.hidden = true; f.removeAttribute('srcdoc'); }
async function markupCall(url, body) {
  const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ ...body, _csrf_token: CSRF }) });
  const d = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(d.error || `Fout ${r.status}`);
  return d;
}
mEl('markupPreviewBtn').addEventListener('click', async () => {
  try { const d = await markupCall('/admin/blocks/markup/preview', { markup: mEl('markupText').value }); markupError(''); const f = mEl('markupFrame'); f.srcdoc = d.html; f.hidden = false; }
  catch (e) { markupError(e.message); markupFrameReset(); }
});
mEl('markupSaveBtn').addEventListener('click', async () => {
  if (settingsBlockId === null) return;
  try { await markupCall(`/admin/blocks/${settingsBlockId}/markup`, { markup: mEl('markupText').value }); markupError(''); showToast('✅ Markup opgeslagen'); }
  catch (e) { markupError(e.message); }
});
async function zonePreview(zone, label) {
  const r = await fetch(`/admin/blocks/zone/${zone}/preview`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
  const d = await r.json().catch(() => ({}));
  if (!r.ok) { showToast(d.error || 'Voorbeeld mislukt', 'error'); return; }
  mEl('zoneTitle').textContent = 'Voorbeeld — ' + label;
  mEl('zoneFrame').srcdoc = d.html;
  showModal(mEl('zoneModal'), '.modal-close');
}
mEl('zoneModal').addEventListener('click', (e) => { if (e.target === e.currentTarget) hideModal(e.currentTarget); });

// ── Modal ─────────────────────────────────────────────────────────────────
function openAddModal() { showModal(document.getElementById('addModal'), '#modalTypeSlug'); }
function closeAddModal() { hideModal(document.getElementById('addModal')); }

document.getElementById('addBlockForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const btn = e.target.querySelector('button[type=submit]');
  btn.disabled = true;
  try {
    const handled = await addBlock(fd.get('type_slug'), fd.get('zone'), fd.get('title') || '');
    if (handled) { document.getElementById('addModal').classList.remove('open'); modalOpener.delete('addModal'); }
  } finally { btn.disabled = false; }
});

document.getElementById('addModal').addEventListener('click', (e) => {
  if (e.target === e.currentTarget) closeAddModal();
});
</script>

</body>
</html>
