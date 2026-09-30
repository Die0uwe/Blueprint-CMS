<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
//
// Gedeelde basis-CSS voor /admin/* schermen (sidebar/topbar/wrap-chrome).
// Zie admin_sidebar.php voor de uitleg waarom dit een aparte partial is.
// Losse schermen voegen hun eigen extra <style> blok toe ná deze include
// voor pagina-specifieke opmaak (bv. een formulier of een tabel).
?>
<style>
  :root { --sidebar-w: 240px; }
  .admin-wrap { display: flex; min-height: 100vh; }
  .admin-sidebar {
    width: var(--sidebar-w); background: var(--surface);
    border-right: 1px solid var(--border); display: flex; flex-direction: column;
    position: fixed; top: 0; left: 0; height: 100vh; z-index: 50;
  }
  .admin-logo {
    padding: 1.2rem 1.5rem; font-size: 1.1rem; font-weight: 800;
    background: linear-gradient(135deg, #a855f7, #6c3df4);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    border-bottom: 1px solid var(--border);
  }
  .admin-nav { padding: 1rem; flex: 1; min-height: 0; overflow-y: auto; color-scheme: dark; }   /* anders vallen de laatste links (Plugins, Uitloggen) op lage schermen buiten beeld */
  .admin-nav-section {
    font-size: .7rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .1em; color: var(--muted); padding: .8rem .5rem .3rem;
  }
  .admin-nav-link {
    display: flex; align-items: center; gap: .7rem; padding: .55rem .9rem;
    border-radius: 8px; font-size: .875rem; color: var(--text-dim);
    transition: all .15s; margin-bottom: 2px;
  }
  .admin-nav-link:hover, .admin-nav-link.active { background: rgba(108,61,244,.15); color: var(--accent2); }
  .admin-nav-link .nav-icon { font-size: 1rem; width: 20px; text-align: center; }
  .admin-main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; min-width: 0; }
  .admin-topbar {
    height: 56px; background: rgba(17,24,39,.95); border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; padding: 0 1.5rem;
    position: sticky; top: 0; z-index: 40; backdrop-filter: blur(8px);
  }
  .admin-topbar h1 { font-size: 1rem; font-weight: 700; }
  .admin-content { padding: 2rem; flex: 1; }
  /* De standaard focusring is bijna zwart en valt op het donkere thema weg. */
  .admin-wrap :focus-visible { outline: 2px solid var(--accent2, #a855f7); outline-offset: 2px; }

  /* Smalle schermen: de vaste zijbalk wordt een gewone blok bovenaan in plaats van 240px van de breedte op te eisen. */
  @media (max-width: 800px) {
    .admin-wrap { flex-direction: column; }
    .admin-sidebar { position: static; width: auto; height: auto; max-height: 40vh; border-right: 0; border-bottom: 1px solid var(--border); }
    .admin-main { margin-left: 0; min-height: 0; }
    .admin-topbar { height: auto; min-height: 56px; flex-wrap: wrap; gap: .5rem; padding: .6rem 1rem; position: static; }
    .admin-content { padding: 1rem; }
    .admin-content .cf-table, .admin-content table { display: block; overflow-x: auto; }
  }
</style>
