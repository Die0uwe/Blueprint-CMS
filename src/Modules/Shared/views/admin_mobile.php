<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
//
// Mobiele admin (Golf 5). De zijbalk was vast 240px breed en liet op een telefoon
// geen ruimte voor de inhoud. Onder 900px schuift de zijbalk nu uit beeld en opent
// via een hamburgerknop (door het script hieronder in de topbalk gezet, zodat elk
// admin-scherm hem krijgt zonder dat al die views aangepast hoeven). Wordt geladen
// door admin_sidebar.php en door de schermen met eigen zijbalk-markup.
?>
<style>
  .admin-nav-toggle, .admin-nav-backdrop { display: none; }
  @media (max-width: 900px) {
    .admin-wrap > .admin-sidebar {
      width: min(280px, 85vw); transform: translateX(-100%); transition: transform .22s ease;
      overflow-y: auto; z-index: 70; box-shadow: none;
    }
    body.admin-nav-open .admin-wrap > .admin-sidebar { transform: translateX(0); box-shadow: 0 0 40px rgba(0,0,0,.5); }
    .admin-nav-backdrop { position: fixed; inset: 0; z-index: 65; background: rgba(0,0,0,.55); }
    body.admin-nav-open .admin-nav-backdrop { display: block; }
    body.admin-nav-open { overflow: hidden; }
    .admin-wrap > .admin-main { margin-left: 0; min-width: 0; width: 100%; }
    .admin-nav-toggle {
      display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
      width: 44px; height: 44px; margin-right: .75rem; font-size: 1.25rem; line-height: 1;
      background: transparent; border: 1px solid var(--border); border-radius: 8px;
      color: var(--text); cursor: pointer;
    }
    .admin-topbar { padding: 0 1rem; gap: .5rem; justify-content: flex-start; }
    .admin-topbar h1 { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .admin-content { padding: 1rem; }
    .admin-nav-link { min-height: 44px; }
    /* Brede tabellen scrollen binnen hun kaart i.p.v. de pagina te rekken. */
    .admin-content .cf-card { overflow-x: auto; }
    .admin-content .cf-table { min-width: 560px; }
    .admin-content .cf-toolbar, .admin-content .cf-card-header { flex-wrap: wrap; }
    /* Vaste meerkolomsrasters (inline) worden één kolom; auto-fill/auto-fit-rasters blijven. */
    .admin-content [style*="grid-template-columns"]:not([style*="auto-fill"]):not([style*="auto-fit"]) { grid-template-columns: 1fr !important; }
    .admin-content .cf-input, .admin-content .cf-select, .admin-content .cf-textarea,
    .admin-content input[type="text"], .admin-content input[type="email"], .admin-content input[type="password"],
    .admin-content input[type="url"], .admin-content input[type="number"], .admin-content select, .admin-content textarea { font-size: 16px; }
    .admin-content .cf-btn, .admin-content .cf-btn-sm, .admin-content .cf-btn-ghost { min-height: 40px; }
  }
</style>
<script>
(function () {
  function init() {
    var bar = document.querySelector('.admin-topbar');
    var side = document.querySelector('.admin-sidebar');
    if (!bar || !side || document.querySelector('.admin-nav-toggle')) return;
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'admin-nav-toggle'; btn.textContent = '☰';
    btn.setAttribute('aria-label', 'Menu'); btn.setAttribute('aria-expanded', 'false');
    bar.insertBefore(btn, bar.firstChild);
    var back = document.createElement('div'); back.className = 'admin-nav-backdrop';
    document.body.appendChild(back);
    function set(open) {
      document.body.classList.toggle('admin-nav-open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    btn.addEventListener('click', function () { set(!document.body.classList.contains('admin-nav-open')); });
    back.addEventListener('click', function () { set(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') set(false); });
    window.addEventListener('resize', function () { if (window.innerWidth > 900) set(false); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
</script>
