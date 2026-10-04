/*!
 * Blueprint CMS — gedeelde editor-loader (Copyright (C) 2026 DieOuwe, GPL-3.0-or-later)
 *
 * Hangt zich automatisch aan elke  <textarea data-editor="...">:
 *   richtext       volledige WYSIWYG-editor (TinyMCE, self-hosted)
 *   richtext-lite  compacte editor (forum, reacties): geen tabellen/afbeeldingen
 *   code           monospace code-veld (Tab = inspringen) — voor HTML-blokken
 *   plain          ongewijzigd (korte tekstvelden)
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
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
