// editor.js — Bericht-editor (Editor A). ES-module, geen externe libraries.
// Kleuren worden uitsluitend met createElement + textContent opgebouwd (nooit innerHTML).
import { tokenize } from './tokenizer.js';

const $ = (id) => document.getElementById(id);
const cfg = JSON.parse($('ed-config').textContent);
const csrf = $('ed-csrf').value;
const ta = $('ed-text'), overlay = $('ed-overlay'), status = $('ed-status'), saveBtn = $('ed-save');
const editorBox = $('ed-editor'), panes = $('ed-panes');
const frame = $('ed-preview'), errBox = $('ed-error');

let initial = ta.value.replace(/^\n/, '');   // de view zet één "\n" voor de inhoud (HTML negeert die)
ta.value = initial;
let lastSaved = initial, dirty = false, previewCtl = null, previewTimer = 0, renderRaf = 0, tabTrap = true;

function setStatus(text, isError = false) { status.textContent = text; status.classList.toggle('is-error', isError); }

async function api(url, body, signal) {
  const res = await fetch(url, {
    method: 'POST', credentials: 'same-origin', signal,
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, Accept: 'application/json' },
    body: JSON.stringify(body),
  });
  let data = {};
  try { data = await res.json(); } catch { /* geen JSON */ }
  if (!res.ok) throw Object.assign(new Error(data.error || `Fout ${res.status}`), { status: res.status });
  return data;
}

// ── Overlay met kleurmarkering ───────────────────────────────────────────
function renderOverlay() {
  renderRaf = 0;
  if (editorBox.classList.contains('is-source')) return;
  const frag = document.createDocumentFragment();
  let line = document.createElement('span');
  line.className = 'ln';
  const endLine = () => {
    if (!line.firstChild) line.appendChild(document.createTextNode('​'));   // lege regel houdt hoogte
    frag.appendChild(line);
    line = document.createElement('span'); line.className = 'ln';
  };
  const toks = cfg.plainOnly ? [['', ta.value]] : tokenize(ta.value);
  for (const [cls, text] of toks) {
    const parts = text.split('\n');
    parts.forEach((part, i) => {
      if (i > 0) endLine();
      if (part === '') return;
      if (cls) { const s = document.createElement('span'); s.className = cls; s.textContent = part; line.appendChild(s); }
      else line.appendChild(document.createTextNode(part));
    });
  }
  endLine();
  overlay.replaceChildren(frag);
  syncScroll();
}
function scheduleOverlay() { if (!renderRaf) renderRaf = requestAnimationFrame(renderOverlay); }
function syncScroll() { overlay.scrollTop = ta.scrollTop; overlay.scrollLeft = ta.scrollLeft; }

// ── Preview ──────────────────────────────────────────────────────────────
function schedulePreview() {
  if (cfg.plainOnly || !$('ed-preview-toggle').checked) return;
  clearTimeout(previewTimer);
  previewTimer = setTimeout(runPreview, 600);
}
async function runPreview() {
  if (previewCtl) previewCtl.abort();
  previewCtl = new AbortController();
  try {
    const data = await api('/admin/editor/preview', { type: cfg.type, markup: ta.value }, previewCtl.signal);
    frame.srcdoc = data.html;           // sandbox="" : geen scripts, geen same-origin
    errBox.hidden = true;
  } catch (e) {
    if (e.name === 'AbortError') return;
    errBox.textContent = e.message; errBox.hidden = false;
  }
}

// ── Opslaan en concept ───────────────────────────────────────────────────
async function save() {
  saveBtn.disabled = true; setStatus('Opslaan…');
  try {
    const data = await api(`/admin/editor/${cfg.type}/${cfg.id}/save`, { markup: ta.value });
    lastSaved = ta.value; dirty = false; setStatus('Opgeslagen ' + data.saved_at);
  } catch (e) { setStatus(e.message, true); }
  finally { saveBtn.disabled = false; }
}
async function autosave() {
  if (!dirty || ta.value === lastSaved) return;
  try {
    const data = await api('/admin/editor/draft', { type: cfg.type, id: cfg.id, content: ta.value });
    setStatus('Concept bewaard ' + data.saved_at);
  } catch (e) { setStatus(e.message, true); }
}

// ── Invoegen en Tab ──────────────────────────────────────────────────────
function wrap(before, after) {
  const { selectionStart: s, selectionEnd: e, value } = ta;
  ta.focus();
  ta.setRangeText(before + value.slice(s, e) + after, s, e, 'end');
  if (s === e) ta.setSelectionRange(s + before.length, s + before.length);
  onInput();
}
function onInput() {
  dirty = ta.value !== lastSaved;
  if (dirty) setStatus('Niet opgeslagen');
  scheduleOverlay(); schedulePreview();
}

ta.addEventListener('input', onInput);
ta.addEventListener('scroll', syncScroll);
ta.addEventListener('keydown', (ev) => {
  if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') { ev.preventDefault(); save(); return; }
  if (ev.key === 'Escape') { tabTrap = false; return; }   // toetsenbord-toegankelijkheid: Esc laat Tab weer los
  if (ev.key === 'Tab' && tabTrap && !ev.shiftKey) { ev.preventDefault(); wrap('  ', ''); return; }
  if (ev.key !== 'Tab') tabTrap = true;
});
saveBtn.addEventListener('click', save);
window.addEventListener('beforeunload', (ev) => { if (dirty) { ev.preventDefault(); ev.returnValue = ''; } });
setInterval(autosave, 30000);

// ── Modus en toolbar ─────────────────────────────────────────────────────
function setMode(markup) {
  editorBox.classList.toggle('is-source', !markup);
  $('ed-mode-markup')?.classList.toggle('is-active', markup);
  $('ed-mode-source')?.classList.toggle('is-active', !markup);
  $('ed-mode-markup')?.setAttribute('aria-pressed', String(markup));
  $('ed-mode-source')?.setAttribute('aria-pressed', String(!markup));
  if (markup) renderOverlay();
}
$('ed-mode-markup')?.addEventListener('click', () => setMode(true));
$('ed-mode-source')?.addEventListener('click', () => setMode(false));
$('ed-preview-toggle')?.addEventListener('change', (ev) => {
  $('ed-preview-pane').classList.toggle('preview-off', !ev.target.checked);
  panes.classList.toggle('preview-off', !ev.target.checked);
  if (ev.target.checked) runPreview();
});
const btnBox = $('ed-buttons');
if (btnBox) {
  for (const b of cfg.toolbar) {
    const el = document.createElement('button');
    el.type = 'button'; el.className = 'ed-btn'; el.textContent = b.label; el.title = b.title;
    el.addEventListener('click', () => wrap(b.before, b.after));
    btnBox.appendChild(el);
  }
}

// ── Conceptbanner ────────────────────────────────────────────────────────
if (cfg.draft !== null && cfg.draft !== undefined) {
  $('ed-draft').hidden = false;
  if (cfg.draftAt) $('ed-draft-at').textContent = ' van ' + cfg.draftAt;
  $('ed-draft-restore').addEventListener('click', () => { ta.value = cfg.draft; $('ed-draft').hidden = true; onInput(); runPreview(); });
  $('ed-draft-discard').addEventListener('click', () => { $('ed-draft').hidden = true; });
}

renderOverlay();
if (!cfg.plainOnly) runPreview();
