// editor.js — Bericht-editor (Editor A). ES-module, geen externe libraries.
// Kleuren worden uitsluitend met createElement + textContent opgebouwd (nooit innerHTML).
import { tokenize } from './tokenizer.js';

const $ = (id) => document.getElementById(id);
const cfg = JSON.parse($('ed-config').textContent);
const csrf = $('ed-csrf').value;
const ta = $('ed-text'), overlay = $('ed-overlay'), status = $('ed-status'), saveBtn = $('ed-save');
const editorBox = $('ed-editor'), panes = $('ed-panes');
const frame = $('ed-preview'), errBox = $('ed-error');

// De view zet één "\n" direct na <textarea>; de HTML-parser negeert die al, dus ta.value is de echte bron.
// (Niet nog eens een leidende regeleinde strippen: dat at bij elke keer laden een lege eerste regel op.)
const initial = ta.value;
let lastSaved = initial, dirty = false, previewCtl = null, previewTimer = 0, renderRaf = 0, tabTrap = true, retryTimer = 0;

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
// Bij elke toetsaanslag wordt alleen het gewijzigde deel van de regels in de DOM vervangen (vergelijk per regel
// met de vorige versie). Boven een grens (MAX_*) is kleuren te zwaar voor een vlotte editor: dan valt de editor
// vanzelf terug op de Bron-weergave (puur tekst) en komt de markering terug zodra de tekst weer kleiner is.
const MAX_HIGHLIGHT_CHARS = 150000, MAX_HIGHLIGHT_LINES = 4000;
const autoNote = $('ed-auto-note');
let userSource = false;            // gekozen via de knop Bron
let autoSource = false;            // automatisch: te groot voor kleuren
let prevKeys = [], prevNodes = [];

function lineCount(text) { let n = 1, i = -1; while ((i = text.indexOf('\n', i + 1)) !== -1) n++; return n; }
function applySourceClass() {
  editorBox.classList.toggle('is-source', userSource || autoSource);
  if (autoNote) autoNote.hidden = !autoSource;
}
function makeLine(tokens) {
  const line = document.createElement('span');
  line.className = 'ln';
  if (!tokens.length) line.appendChild(document.createTextNode('​'));   // lege regel houdt hoogte
  for (const [cls, text] of tokens) {
    if (cls) { const s = document.createElement('span'); s.className = cls; s.textContent = text; line.appendChild(s); }
    else line.appendChild(document.createTextNode(text));
  }
  return line;
}
function renderOverlay() {
  renderRaf = 0;
  const text = ta.value;
  const tooBig = text.length > MAX_HIGHLIGHT_CHARS || lineCount(text) > MAX_HIGHLIGHT_LINES;
  if (tooBig !== autoSource) { autoSource = tooBig; applySourceClass(); }
  if (userSource || autoSource) return;
  const lines = [[]];
  for (const [cls, part] of (cfg.plainOnly ? [['', text]] : tokenize(text))) {
    part.split('\n').forEach((piece, i) => {
      if (i > 0) lines.push([]);
      if (piece !== '') lines[lines.length - 1].push([cls, piece]);
    });
  }
  const keys = lines.map((l) => l.map((t) => t[0] + '\u0001' + t[1]).join('\u0002'));
  const n = keys.length, m = prevKeys.length;
  let a = 0; while (a < n && a < m && keys[a] === prevKeys[a]) a++;
  let e = 0; while (e < n - a && e < m - a && keys[n - 1 - e] === prevKeys[m - 1 - e]) e++;
  const fresh = [];
  for (let i = a; i < n - e; i++) fresh.push(makeLine(lines[i]));
  const anchor = e > 0 ? prevNodes[m - e] : null;
  for (let i = a; i < m - e; i++) prevNodes[i].remove();
  const frag = document.createDocumentFragment();
  fresh.forEach((node) => frag.appendChild(node));
  overlay.insertBefore(frag, anchor);
  prevNodes = prevNodes.slice(0, a).concat(fresh, prevNodes.slice(m - e));
  prevKeys = keys;
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
  if (!frame) return;
  clearTimeout(retryTimer);
  if (previewCtl) previewCtl.abort();
  previewCtl = new AbortController();
  try {
    const data = await api('/admin/editor/preview', { type: cfg.type, title: cfg.title || '', markup: ta.value }, previewCtl.signal);
    frame.srcdoc = data.html;           // sandbox="" : geen scripts, geen same-origin
    errBox.hidden = true;
  } catch (e) {
    if (e.name === 'AbortError') return;
    errBox.textContent = e.message; errBox.hidden = false;
    // Te veel previews: later vanzelf opnieuw proberen, anders blijft de preview verouderd tot de volgende toets.
    if (e.status === 429) { clearTimeout(retryTimer); retryTimer = setTimeout(runPreview, 8000); }
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
// Invoegen gaat via execCommand('insertText') zodat Ctrl+Z het netjes ongedaan maakt (setRangeText doet dat niet);
// setRangeText blijft de terugval voor browsers zonder execCommand.
function insertAt(s, e, text) {
  ta.focus();
  ta.setSelectionRange(s, e);
  let ok = false;
  try { ok = document.execCommand('insertText', false, text); } catch { ok = false; }
  if (!ok || ta.value.slice(s, s + text.length) !== text) {
    ta.setRangeText(text, s, e, 'end');
    onInput();
  }
}
function wrap(before, after) {
  const { selectionStart: s, selectionEnd: e, value } = ta;
  const inner = value.slice(s, e);
  insertAt(s, e, before + inner + after);
  // Cursor in het midden (zonder selectie) of de oorspronkelijke tekst weer geselecteerd (met selectie)
  ta.setSelectionRange(s + before.length, s + before.length + inner.length);
}
function onInput() {
  dirty = ta.value !== lastSaved;
  if (dirty) setStatus('Niet opgeslagen');
  else if (status.textContent === 'Niet opgeslagen') setStatus('');   // teruggedraaid naar de opgeslagen tekst
  scheduleOverlay(); schedulePreview();
}

ta.addEventListener('input', onInput);
ta.addEventListener('scroll', syncScroll);
// Ctrl/Cmd+S werkt overal op de pagina (ook met de focus op een knop), niet alleen in het tekstvak.
document.addEventListener('keydown', (ev) => {
  if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') { ev.preventDefault(); if (!saveBtn.disabled) save(); }
});
ta.addEventListener('keydown', (ev) => {
  if (ev.key === 'Escape') { tabTrap = false; return; }   // toetsenbord-toegankelijkheid: Esc laat Tab weer los
  if (ev.key === 'Tab' && tabTrap && !ev.shiftKey) { ev.preventDefault(); wrap('  ', ''); return; }
  if (ev.key !== 'Tab') tabTrap = true;
});
saveBtn.addEventListener('click', save);
window.addEventListener('beforeunload', (ev) => { if (dirty) { ev.preventDefault(); ev.returnValue = ''; } });
setInterval(autosave, 30000);

// ── Modus en toolbar ─────────────────────────────────────────────────────
function setMode(markup) {
  userSource = !markup;
  applySourceClass();
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
  // Verwerpen: het concept op de server vervangen door de opgeslagen tekst, anders komt de banner bij elke herlaadbeurt terug.
  $('ed-draft-discard').addEventListener('click', async () => {
    $('ed-draft').hidden = true;
    try { await api('/admin/editor/draft', { type: cfg.type, id: cfg.id, content: lastSaved }); }
    catch (e) { setStatus(e.message, true); }
  });
}

renderOverlay();
if (!cfg.plainOnly) runPreview();
