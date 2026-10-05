/*!
 * Blueprint CMS — gedeelde editor-loader (Copyright (C) 2026 DieOuwe, GPL-3.0-or-later)
 *
 * Hangt zich automatisch aan elke  <textarea data-editor="...">:
 *   richtext       volledige WYSIWYG-editor (TinyMCE, self-hosted)
 *   richtext-lite  compacte editor (forum, reacties): geen tabellen/afbeeldingen
 *   code           code-editor voor HTML-blokken: snippet-knoppen, voorbeeld, regelafbreking,
 *                  volledig scherm, Tab = inspringen
 *   plain          tekstveld met teller + volledig scherm
 *
 * De toegestane tags/attributen komen overeen met ContentSanitizer::ALLOWED.
 * De server sanitized altijd opnieuw: dit script is gemak, geen beveiliging.
 */
(function () {
  'use strict';

  var BASE = '/assets/vendor/tinymce';

  // Dezelfde whitelist als ContentSanitizer (zodat de editor niets aanbiedt dat de server weggooit).
  var VALID = 'p,br,hr,strong/b,em/i,u,s,del,sub,sup,small,code,pre,kbd,blockquote,ul,ol,li,' +
              'h1,h2,h3,h4,h5,h6,span,div,table,thead,tbody,tr,th[colspan|rowspan],td[colspan|rowspan],' +
              'a[href|title],img[src|alt|title|width|height]';

  function parseRgb(str) {
    var m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(str || '');
    return m ? [+m[1], +m[2], +m[3]] : null;
  }
  function isDark() {
    var rgb = parseRgb(getComputedStyle(document.body).backgroundColor) ||
              parseRgb(getComputedStyle(document.documentElement).backgroundColor);
    if (!rgb) return true; // alle huidige thema's zijn donker
    return (0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2]) < 140;
  }
  function cssVar(name, fallback) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
  }

  function isEmptyHtml(html) {
    var d = document.createElement('div');
    d.innerHTML = html || '';
    return d.textContent.replace(/ /g, '').trim() === '' && !d.querySelector('img,hr,table');
  }

  function initRich(el, lite) {
    var dark = isDark();
    var required = el.hasAttribute('required');
    // Een verborgen required-textarea blokkeert het formulier ("niet focusbaar"): zelf valideren.
    el.removeAttribute('required');
    if (required) el.dataset.cfRequired = '1';

    var toolbar = lite
      ? 'bold italic underline | bullist numlist | blockquote link | removeformat'
      : 'blocks | bold italic underline strikethrough | bullist numlist | blockquote hr | link image table | code fullscreen | removeformat';
    var plugins = lite
      ? 'lists link autolink'
      : 'lists link image table code autolink fullscreen wordcount charmap searchreplace visualblocks';

    window.tinymce.init({
      target: el,
      license_key: 'gpl',
      base_url: BASE,
      suffix: '.min',
      language: 'nl',
      language_url: BASE + '/langs/nl.js',
      skin: dark ? 'oxide-dark' : 'oxide',
      content_css: dark ? 'dark' : 'default',
      content_style: 'body{background:' + cssVar('--surface', dark ? '#111827' : '#fff') +
                     ';color:' + cssVar('--text', dark ? '#e2e8f0' : '#111') +
                     ';font-family:inherit;font-size:15px;line-height:1.6;padding:8px}' +
                     'a{color:' + cssVar('--accent2', '#a855f7') + '}',
      menubar: false,
      branding: false,
      promotion: false,
      statusbar: !lite,
      min_height: lite ? 160 : 320,
      autoresize_bottom_margin: 8,
      resize: true,
      plugins: plugins,
      toolbar: toolbar,
      block_formats: 'Alinea=p; Kop 2=h2; Kop 3=h3; Kop 4=h4; Citaat=blockquote; Code=pre',
      valid_elements: VALID,
      invalid_elements: 'script,style,iframe,object,embed,form,input',
      convert_urls: false,
      relative_urls: false,
      remove_script_host: false,
      link_title: true,
      target_list: false,
      link_assume_external_targets: 'https',
      image_description: true,
      image_dimensions: false,
      image_advtab: false,
      paste_data_images: false,
      browser_spellcheck: true,
      setup: function (ed) {
        ed.on('init', function () { el.dataset.cfEditorReady = '1'; });
      }
    });

    var form = el.form;
    if (form && !form.dataset.cfEditorBound) {
      form.dataset.cfEditorBound = '1';
      form.addEventListener('submit', function (e) {
        window.tinymce.triggerSave();
        var fields = form.querySelectorAll('textarea[data-cf-required="1"]');
        for (var i = 0; i < fields.length; i++) {
          if (isEmptyHtml(fields[i].value)) {
            e.preventDefault();
            var ed = window.tinymce.get(fields[i].id);
            if (ed) ed.focus();
            window.alert('Dit veld is verplicht.');
            return;
          }
        }
      }, true);
    }
  }

  // ── Code-editor (HTML-blok): snippet-knoppen, voorbeeld, regelafbreking, volledig scherm ──────────
  var CSS = '.cf-ed{border:1px solid var(--border,#334155);border-radius:8px;background:var(--surface,#111827);overflow:hidden}' +
    '.cf-ed-bar{display:flex;flex-wrap:wrap;gap:.25rem;padding:.35rem;border-bottom:1px solid var(--border,#334155);background:var(--bg2,rgba(255,255,255,.04));align-items:center}' +
    '.cf-ed-bar button{font:inherit;font-size:.78rem;padding:.2rem .5rem;border:1px solid var(--border,#334155);border-radius:6px;background:transparent;color:var(--text,#e2e8f0);cursor:pointer}' +
    '.cf-ed-bar button:hover,.cf-ed-bar button[aria-pressed=true]{background:var(--accent,#6c3df4);color:var(--on-accent,#fff)}' +
    '.cf-ed-sp{flex:1}' +
    '.cf-ed-body{display:flex;min-height:12rem}' +
    '.cf-ed-body textarea{flex:1;min-width:0;border:0!important;border-radius:0!important;margin:0;resize:vertical;min-height:12rem;background:transparent;color:inherit;box-sizing:border-box}' +
    '.cf-ed-body iframe{flex:1;min-width:0;border:0;border-left:1px solid var(--border,#334155);background:#0f172a;min-height:12rem}' +
    '.cf-ed-foot{padding:.2rem .5rem;font-size:.72rem;color:var(--muted,#8091a7);border-top:1px solid var(--border,#334155)}' +
    '.cf-ed.cf-ed-full{position:fixed;inset:0;z-index:99999;border-radius:0;display:flex;flex-direction:column;background:var(--bg,#0b1120)}' +
    '.cf-ed.cf-ed-full .cf-ed-body{flex:1;min-height:0}' +
    '.cf-ed.cf-ed-full .cf-ed-body textarea{resize:none;height:100%;font-size:.95rem}' +
    'html.cf-ed-lock{overflow:hidden}';

  var SNIPPETS = [
    ['B', '<strong>|</strong>', 'Vet'], ['I', '<em>|</em>', 'Cursief'], ['H2', '<h2>|</h2>', 'Kop 2'], ['H3', '<h3>|</h3>', 'Kop 3'],
    ['¶', '<p>|</p>', 'Alinea'], ['Link', '<a href="https://">|</a>', 'Link'], ['Img', '<img src="" alt="|">', 'Afbeelding'],
    ['Lijst', '<ul>\n  <li>|</li>\n</ul>', 'Opsomming'], ['Div', '<div>\n|\n</div>', 'Blok (div)'],
    ['Knop', '<a class="cf-btn" href="#">|</a>', 'Knop'], ['<br>', '<br>\n', 'Regeleinde'], ['<!-- -->', '<!-- | -->', 'Commentaar']
  ];

  function injectCss() {
    if (document.getElementById('cf-ed-css')) return;
    var st = document.createElement('style');
    st.id = 'cf-ed-css';
    st.textContent = CSS;
    document.head.appendChild(st);
  }

  function btn(label, title, onClick, pressable) {
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = label;
    b.title = title;
    if (pressable) b.setAttribute('aria-pressed', 'false');
    b.addEventListener('click', onClick);
    return b;
  }

  function insertSnippet(el, tpl) {
    var s = el.selectionStart, t = el.selectionEnd, sel = el.value.slice(s, t);
    var i = tpl.indexOf('|');
    var out = i < 0 ? tpl : tpl.slice(0, i) + sel + tpl.slice(i + 1);
    el.value = el.value.slice(0, s) + out + el.value.slice(t);
    var caret = i < 0 ? s + out.length : s + i + sel.length;
    el.focus();
    el.selectionStart = el.selectionEnd = caret;
    el.dispatchEvent(new Event('input', { bubbles: true }));
  }

  /** Verpakt een textarea in een editor met knoppenbalk. mode: 'code' (snippets + voorbeeld) of 'plain'. */
  function wrapEditor(el, mode) {
    injectCss();
    var wrap = document.createElement('div'); wrap.className = 'cf-ed';
    var bar = document.createElement('div'); bar.className = 'cf-ed-bar';
    var body = document.createElement('div'); body.className = 'cf-ed-body';
    var foot = document.createElement('div'); foot.className = 'cf-ed-foot';
    el.parentNode.insertBefore(wrap, el);
    wrap.appendChild(bar); wrap.appendChild(body); wrap.appendChild(foot);
    body.appendChild(el);

    var frame = null, previewBtn = null, wrapBtn = null, fullBtn = null;

    function count() {
      var v = el.value;
      foot.textContent = (v === '' ? 0 : v.split('\n').length) + ' regels · ' + v.length + ' tekens';
    }
    function refreshPreview() {
      if (!frame) return;
      frame.srcdoc = '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/assets/css/blueprint.css">' +
        '<body style="background:#0f172a;color:#e2e8f0;font-family:system-ui,sans-serif;padding:1rem">' + el.value;
    }
    function setFull(on) {
      wrap.classList.toggle('cf-ed-full', on);
      document.documentElement.classList.toggle('cf-ed-lock', on);
      fullBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      fullBtn.textContent = on ? '✕ Sluiten' : '⛶ Volledig scherm';
      if (on) el.focus();
    }

    if (mode === 'code') {
      SNIPPETS.forEach(function (sn) {
        bar.appendChild(btn(sn[0], sn[2], function () { insertSnippet(el, sn[1]); }));
      });
    }
    var sp = document.createElement('span'); sp.className = 'cf-ed-sp'; bar.appendChild(sp);
    if (mode === 'code') {
      wrapBtn = btn('Regelafbreking', 'Lange regels afbreken aan/uit', function () {
        var on = el.getAttribute('wrap') === 'off';
        el.setAttribute('wrap', on ? 'soft' : 'off');
        el.style.whiteSpace = on ? 'pre-wrap' : 'pre';
        wrapBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      }, true);
      bar.appendChild(wrapBtn);
      previewBtn = btn('Voorbeeld', 'Voorbeeld naast de code (scripts worden niet uitgevoerd)', function () {
        var on = !frame;
        if (on) {
          frame = document.createElement('iframe');
          frame.setAttribute('sandbox', ''); // geen scripts, geen formulieren
          frame.title = 'Voorbeeld';
          body.appendChild(frame);
          refreshPreview();
        } else {
          frame.remove(); frame = null;
        }
        previewBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      }, true);
      bar.appendChild(previewBtn);
    }
    fullBtn = btn('⛶ Volledig scherm', 'Volledig scherm (Esc om te sluiten)', function () {
      setFull(!wrap.classList.contains('cf-ed-full'));
    }, true);
    bar.appendChild(fullBtn);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && wrap.classList.contains('cf-ed-full')) setFull(false);
    });
    var t = null;
    el.addEventListener('input', function () {
      count();
      if (frame) { clearTimeout(t); t = setTimeout(refreshPreview, 250); }
    });
    count();
    return wrap;
  }

  function initCode(el) {
    el.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab' || e.ctrlKey || e.metaKey || e.altKey) return;
      e.preventDefault();
      var s = el.selectionStart, t = el.selectionEnd;
      el.value = el.value.slice(0, s) + '  ' + el.value.slice(t);
      el.selectionStart = el.selectionEnd = s + 2;
    });
    el.style.tabSize = 2;
    el.style.whiteSpace = 'pre';
    el.setAttribute('wrap', 'off');
    wrapEditor(el, 'code');
  }

  function boot() {
    var rich = document.querySelectorAll('textarea[data-editor="richtext"], textarea[data-editor="richtext-lite"]');
    if (rich.length && !window.tinymce) {
      // Editor-bestanden ontbreken: formulier blijft gewoon bruikbaar als platte textarea.
      if (window.console) console.warn('[cf-editor] TinyMCE niet geladen — textarea blijft ongewijzigd.');
    } else {
      rich.forEach(function (el, i) {
        if (!el.id) el.id = 'cf-editor-' + i;
        initRich(el, el.dataset.editor === 'richtext-lite');
      });
    }
    document.querySelectorAll('textarea[data-editor="code"]').forEach(initCode);
    // Gewone tekstvelden (blokken, formulieren): ook volledig scherm + teller
    document.querySelectorAll('textarea[data-editor="plain"]').forEach(function (el) { wrapEditor(el, 'plain'); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
