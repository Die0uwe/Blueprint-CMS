// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later
// AI Studio — init en UI-logica (vanilla ES-module, geen externe libraries).
//
// Veiligheidsregels in deze file:
//  - AI-tekst en gebruikersinvoer worden ALLEEN via textContent in de DOM gezet,
//    nooit via innerHTML/insertAdjacentHTML/document.write.
//  - Code in antwoorden komt in <pre><code> (textContent).
//  - De editor wordt nooit door een AI-antwoord gevuld. Een voorstel opent de
//    diff-modal; pas na een klik op "Toepassen" vraagt deze file de SERVER om het
//    gevalideerde resultaat (POST apply-diff) en zet pas dán de editorwaarde.

import { Editor } from './editor.js';
import { SseStream } from './sse.js';

const root = document.getElementById('aistudio');
if (root) init(root);

function init(root) {
  const csrf = root.dataset.csrf || '';
  const api = root.dataset.urlBase || '/admin/ai-studio';
  const $ = (id) => document.getElementById(id);

  const els = {
    list: $('studio-conv-list'), newBtn: $('studio-new'), messages: $('studio-messages'),
    form: $('studio-form'), input: $('studio-input'), send: $('studio-send'), stop: $('studio-stop'),
    ctx: $('studio-ctx'), provider: $('studio-provider'), model: $('studio-model'),
    lang: $('studio-lang'), clear: $('studio-editor-clear'), info: $('studio-editor-info'),
    modal: $('studio-diff'), diffBody: $('studio-diff-body'), diffStats: $('studio-diff-stats'),
    diffError: $('studio-diff-error'), apply: $('studio-diff-apply'), reject: $('studio-diff-reject'),
  };

  const state = { conversationId: null, stream: null, proposal: null };
  const editor = new Editor($('studio-editor'), {
    language: els.lang.value,
    onChange: () => { els.info.textContent = ''; },
  });

  // ── hulpfuncties ──────────────────────────────────────────────────────

  async function post(path, body) {
    const res = await fetch(api + path, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body),
    });
    let json = null;
    try { json = await res.json(); } catch (_) { /* geen JSON */ }
    if (!res.ok) throw new Error((json && json.error) || 'Verzoek mislukt (HTTP ' + res.status + ').');
    return json;
  }

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  /** Splits tekst in gewone tekst en ``` codeblokken en bouw er DOM van (alleen textContent). */
  function renderRich(container, text) {
    container.textContent = '';
    const re = /```([^\n`]*)\n([\s\S]*?)(?:```|$)/g;
    let last = 0;
    let m;
    while ((m = re.exec(text)) !== null) {
      if (m.index > last) container.appendChild(el('p', 'msg-text', text.slice(last, m.index)));
      const lang = m[1].trim();
      const pre = el('pre', 'msg-code');
      pre.dataset.lang = lang === 'proposed-file' ? 'voorstel voor de editor' : lang;
      pre.appendChild(el('code', '', m[2].replace(/\n$/, '')));
      container.appendChild(pre);
      last = re.lastIndex;
    }
    if (last < text.length) container.appendChild(el('p', 'msg-text', text.slice(last)));
  }

  function addMessage(role, text, note) {
    const wrap = el('article', 'msg msg-' + role);
    wrap.appendChild(el('header', 'msg-role', role === 'user' ? 'Jij' : 'AI'));
    const body = el('div', 'msg-body');
    renderRich(body, text);
    wrap.appendChild(body);
    els.messages.appendChild(wrap);
    els.messages.scrollTop = els.messages.scrollHeight;
    if (note) wrap.appendChild(el('p', 'studio-info', note));
    return { wrap, body };
  }

  function setBusy(busy) {
    els.send.disabled = busy;
    els.stop.hidden = !busy;
    els.input.readOnly = busy;
  }

  // ── gesprekken ────────────────────────────────────────────────────────

  function addConvItem(id, title) {
    const li = el('li');
    li.dataset.id = String(id);
    const open = el('button', 'studio-conv-open', title);
    open.type = 'button';
    const del = el('button', 'studio-conv-del', '✕');
    del.type = 'button';
    del.setAttribute('aria-label', 'Verwijder gesprek');
    li.append(open, del);
    els.list.prepend(li);
    return li;
  }

  function markActive() {
    for (const li of els.list.children) li.classList.toggle('active', Number(li.dataset.id) === state.conversationId);
  }

  async function openConversation(id) {
    if (state.stream) return;
    state.conversationId = id;
    markActive();
    els.messages.textContent = '';
    try {
      const res = await fetch(api + '/conversation/' + id, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
      if (!res.ok) throw new Error('Gesprek laden mislukt.');
      const json = await res.json();
      for (const m of json.messages || []) {
        const note = m.applied ? 'Het voorstel uit dit antwoord is toegepast.'
          : m.has_proposal ? 'Dit antwoord bevatte een wijzigingsvoorstel. Vraag het opnieuw om het te bekijken (de editor kan sindsdien veranderd zijn).' : '';
        addMessage(m.role, String(m.content), note);
      }
    } catch (err) {
      els.messages.appendChild(el('p', 'studio-error', err.message));
    }
  }

  async function newConversation(title) {
    const json = await post('/conversation/new', { title: title || '', provider: els.provider.value });
    addConvItem(json.id, json.title);
    state.conversationId = json.id;
    markActive();
    els.messages.textContent = '';
    return json.id;
  }

  els.newBtn.addEventListener('click', async () => {
    try { await newConversation(''); els.input.focus(); } catch (err) { alert(err.message); }
  });

  els.list.addEventListener('click', async (e) => {
    const li = e.target.closest('li');
    if (!li) return;
    const id = Number(li.dataset.id);
    if (e.target.closest('.studio-conv-del')) {
      if (!confirm('Dit gesprek verwijderen?')) return;
      try {
        await post('/conversation/delete', { id });
        li.remove();
        if (state.conversationId === id) { state.conversationId = null; els.messages.textContent = ''; }
      } catch (err) { alert(err.message); }
    } else if (e.target.closest('.studio-conv-open')) {
      openConversation(id);
    }
  });

  // ── provider / model ──────────────────────────────────────────────────

  function syncModel() {
    const opt = els.provider.selectedOptions[0];
    els.model.value = opt ? opt.dataset.model || '' : '';
  }
  els.provider.addEventListener('change', syncModel);
  syncModel();

  // ── editor ────────────────────────────────────────────────────────────

  els.lang.addEventListener('change', () => editor.setLanguage(els.lang.value));
  els.clear.addEventListener('click', () => { editor.value = ''; editor.focus(); });

  // ── chat ──────────────────────────────────────────────────────────────

  els.input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); els.form.requestSubmit(); }
  });
  els.stop.addEventListener('click', () => { if (state.stream) state.stream.close(); });

  els.form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = els.input.value.trim();
    if (!text || state.stream) return;

    setBusy(true);
    try {
      if (state.conversationId === null) await newConversation(text.slice(0, 60));
    } catch (err) {
      addMessage('assistant', 'Fout: ' + err.message);
      setBusy(false);
      return;
    }

    addMessage('user', text);
    els.input.value = '';
    const reply = addMessage('assistant', '');
    let full = '';
    let proposal = null;
    let messageId = null;

    const body = {
      conversation_id: state.conversationId, provider: els.provider.value,
      model: els.model.value.trim(), message: text,
    };
    if (els.ctx.checked && editor.value !== '') body.editor = { content: editor.value, language: els.lang.value };

    const stream = new SseStream(api + '/chat', { csrf, body });
    state.stream = stream;

    stream.addEventListener('delta', (d) => {
      full += String(d.text || '');
      renderRich(reply.body, full);
      els.messages.scrollTop = els.messages.scrollHeight;
    });
    stream.addEventListener('proposal', (d) => { proposal = d; });
    stream.addEventListener('notice', (d) => reply.wrap.appendChild(el('p', 'studio-info', String(d.message || ''))));
    stream.addEventListener('error', (d) => reply.wrap.appendChild(el('p', 'studio-error', String(d.message || 'Fout.'))));
    stream.addEventListener('done', (d) => { messageId = d.message_id; });
    stream.onerror = (err) => reply.wrap.appendChild(el('p', 'studio-error', err.message));
    stream.onclose = () => {
      state.stream = null;
      setBusy(false);
      if (full === '' && !reply.wrap.querySelector('.studio-error')) reply.wrap.remove();
      if (proposal) {
        addProposalButtonInline(reply.wrap, proposal);
        showDiff(proposal);
      }
      els.input.focus();
    };
  });

  function addProposalButtonInline(wrap, proposal) {
    const btn = el('button', 'cf-btn-sm', 'Voorstel opnieuw bekijken');
    btn.type = 'button';
    btn.addEventListener('click', () => showDiff(proposal));
    wrap.appendChild(btn);
  }

  // ── diff-modal ────────────────────────────────────────────────────────

  function showDiff(p) {
    state.proposal = p;
    els.diffBody.textContent = '';
    for (const line of String(p.diff).split('\n')) {
      if (line === '') continue;
      const kind = line.startsWith('+++') || line.startsWith('---') ? 'meta'
        : line.startsWith('@@') ? 'hunk'
        : line.startsWith('+') ? 'add'
        : line.startsWith('-') ? 'del' : 'ctx';
      els.diffBody.appendChild(el('span', 'diff-' + kind, line + '\n'));
    }
    const s = p.stats || {};
    els.diffStats.textContent = '+' + (s.added ?? 0) + ' regels, −' + (s.removed ?? 0) + ' regels. Er verandert niets in de editor totdat je op Toepassen klikt.';
    els.diffError.hidden = true;
    els.modal.hidden = false;
    els.apply.focus();
  }

  function closeDiff() {
    els.modal.hidden = true;
    state.proposal = null;
    els.input.focus();
  }

  els.reject.addEventListener('click', closeDiff);
  els.modal.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDiff(); });

  els.apply.addEventListener('click', async () => {
    if (!state.proposal) return;
    els.apply.disabled = true;
    els.diffError.hidden = true;
    try {
      // De server rekent het resultaat uit en weigert als de editor sinds het voorstel is gewijzigd.
      const out = await post('/apply-diff', { message_id: state.proposal.message_id, base: editor.value });
      editor.value = String(out.content);
      els.info.textContent = 'Wijziging toegepast.';
      closeDiff();
    } catch (err) {
      els.diffError.textContent = err.message;
      els.diffError.hidden = false;
    } finally { els.apply.disabled = false; }
  });
}
