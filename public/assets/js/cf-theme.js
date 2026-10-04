/* Blueprint CMS — thema-kiezer + mobiel menu. */
(function () {
  'use strict';
  var d = document.documentElement;

  /* ── Mobiel menu ─────────────────────────────────────────────────────── */
  var tog = document.getElementById('cf-menu-toggle');
  var header = document.getElementById('cf-header');
  if (tog && header) {
    var setOpen = function (open) {
      header.classList.toggle('nav-open', open);
      tog.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    tog.addEventListener('click', function () { setOpen(!header.classList.contains('nav-open')); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
    window.addEventListener('resize', function () { if (window.innerWidth > 768) setOpen(false); });
    header.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('.cf-nav-link')) setOpen(false);
    });
  }

  /* ── Thema-kiezer ────────────────────────────────────────────────────── */
  var box = document.getElementById('cf-theme-switch');
  var btn = document.getElementById('cf-theme-btn');
  var menu = document.getElementById('cf-theme-menu');
  var listEl = document.getElementById('cf-theme-list');
  var data = document.getElementById('cf-themes');
  if (!box || !btn || !menu || !listEl || !data || d.getAttribute('data-choice') === '0') return;

  var themes = [];
  try { themes = JSON.parse(data.textContent); } catch (e) { return; }
  if (themes.length < 2) return;
  box.hidden = false;

  function store(k, v) { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) {} }
  function load(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function find(slug) { for (var i = 0; i < themes.length; i++) if (themes[i].slug === slug) return themes[i]; return null; }

  function currentMode() {
    var th = find(d.getAttribute('data-theme'));
    if (!th) return 'dark';
    return d.getAttribute('data-mode') === 'alt' ? (th.mode === 'dark' ? 'light' : 'dark') : th.mode;
  }
  function applyMode(pref) {
    var th = find(d.getAttribute('data-theme'));
    if (th && th.hasAlt && pref && pref !== th.mode) d.setAttribute('data-mode', 'alt');
    else d.removeAttribute('data-mode');
  }
  function paint() {
    var slug = d.getAttribute('data-theme');
    var rows = listEl.children;
    for (var i = 0; i < rows.length; i++) rows[i].setAttribute('aria-checked', rows[i].getAttribute('data-slug') === slug ? 'true' : 'false');
    var m = currentMode();
    var mb = menu.querySelectorAll('[data-mode-pref]');
    for (i = 0; i < mb.length; i++) mb[i].classList.toggle('active', mb[i].getAttribute('data-mode-pref') === m);
  }

  themes.forEach(function (th) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'cf-theme-item'; b.setAttribute('role', 'menuitemradio');
    b.setAttribute('data-slug', th.slug);
    var sw = document.createElement('span'); sw.className = 'cf-theme-swatch';
    (th.swatch || []).forEach(function (c) { var i = document.createElement('i'); i.style.background = c; sw.appendChild(i); });
    var name = document.createElement('span'); name.textContent = th.name;
    b.appendChild(sw); b.appendChild(name);
    b.addEventListener('click', function () {
      d.setAttribute('data-theme', th.slug); store('cf_theme', th.slug);
      applyMode(load('cf_mode')); paint();
    });
    listEl.appendChild(b);
  });
  menu.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-mode-pref]') : null;
    if (!t) return;
    var pref = t.getAttribute('data-mode-pref');
    store('cf_mode', pref); applyMode(pref); paint();
  });

  function toggle(open) {
    menu.hidden = !open; btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  btn.addEventListener('click', function (e) { e.stopPropagation(); toggle(menu.hidden); });
  document.addEventListener('click', function (e) { if (!box.contains(e.target)) toggle(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') toggle(false); });
  paint();
})();
