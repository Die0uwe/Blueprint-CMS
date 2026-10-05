/*! Blueprint CMS — AI chatbox (Copyright (C) 2026 DieOuwe, GPL-3.0-or-later) */
(function () {
  'use strict';
  var ENDPOINT = '/api/ollama/chat';
  var MAX_HISTORY = 20;

  function add(msgs, cls, text) {
    var d = document.createElement('div');
    d.className = 'cf-aichat-msg ' + cls;
    var s = document.createElement('span');
    s.textContent = text;            // nooit innerHTML: antwoorden van een model zijn onbetrouwbaar
    d.appendChild(s);
    msgs.appendChild(d);
    msgs.scrollTop = msgs.scrollHeight;
    return d;
  }

  function init(box) {
    if (box.dataset.init) return;
    box.dataset.init = '1';
    var msgs = box.querySelector('.cf-aichat-msgs'),
        form = box.querySelector('.cf-aichat-form'),
        input = box.querySelector('.cf-aichat-input'),
        send = box.querySelector('.cf-aichat-send'),
        chips = box.querySelector('.cf-aichat-chips'),
        history = [], busy = false;

    function reset() {
      history = []; msgs.textContent = '';
      var w = box.dataset.welcome || '';
      if (w) add(msgs, 'cf-aichat-ai', w);
      if (chips) chips.hidden = false;
    }

    function ask(text) {
      text = (text || '').trim();
      if (!text || busy) return;
      busy = true; send.disabled = true; input.value = '';
      if (chips) chips.hidden = true;
      history.push({ role: 'user', content: text });
      history = history.slice(-MAX_HISTORY);
      add(msgs, 'cf-aichat-user', text);
      var wait = add(msgs, 'cf-aichat-ai', '●●●');
      wait.classList.add('cf-aichat-typing');

      fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ messages: history })
      })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, d: d, status: r.status }; }); })
        .then(function (x) {
          wait.classList.remove('cf-aichat-typing');
          if (x.ok && x.d.reply) {
            history.push({ role: 'assistant', content: x.d.reply });
            wait.firstChild.textContent = x.d.reply;
          } else {
            history.pop();           // mislukte vraag niet in de geschiedenis laten
            wait.classList.add('cf-aichat-err');
            wait.firstChild.textContent = x.status === 429
              ? 'Even rustig aan — probeer het over een minuut opnieuw.'
              : (x.d && x.d.error ? x.d.error : 'De AI is nu niet bereikbaar.');
          }
        })
        .catch(function () {
          wait.classList.remove('cf-aichat-typing'); wait.classList.add('cf-aichat-err');
          history.pop();
          wait.firstChild.textContent = 'Geen verbinding.';
        })
        .then(function () { busy = false; send.disabled = false; msgs.scrollTop = msgs.scrollHeight; input.focus(); });
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
    box.querySelector('.cf-aichat-clear').addEventListener('click', reset);
    [].forEach.call(box.querySelectorAll('.cf-aichat-chip'), function (b) {
      b.addEventListener('click', function () { ask(b.textContent); });
    });
    reset();
  }

  function boot() { [].forEach.call(document.querySelectorAll('.cf-aichat'), init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
