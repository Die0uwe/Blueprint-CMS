<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
//
// This work is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This work is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
// ============================================================================
//
// Gedeelde admin-sidebar partial (Wave 2). dashboard.php en Blocks/views/index.php
// herhaalden allebei dezelfde sidebar-markup handmatig — elk nieuw /admin/* scherm
// deed dat opnieuw kopiëren. Vanaf Wave 2 gebruiken NIEUWE admin-schermen (News,
// Pages, de placeholder-schermen) deze ene partial; de twee oudere bestanden zijn
// bewust niet aangepast om die diff klein te houden — een verdere opschoning
// (dashboard.php en Blocks/views/*.php ook laten includen) is een goede
// vervolgstap maar valt buiten deze wave.
//
// Verwacht: $activeNav (string) — welke link de 'active' class krijgt.
// Optioneel: $pageTitle (string) — titel in de topbar (view zet deze zelf verder in).

$activeNav ??= '';

$navItem = static function (string $key, string $href, string $icon, string $label) use ($activeNav): void {
    $active = $activeNav === $key ? ' active' : '';
    echo '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="admin-nav-link' . $active . '">'
       . '<span class="nav-icon">' . $icon . '</span> ' . htmlspecialchars($label, ENT_QUOTES)
       . '</a>' . "\n";
};
?>
<aside class="admin-sidebar">
  <div class="admin-logo">🔮 Blueprint CMS</div>
  <nav class="admin-nav">

    <div class="admin-nav-section">Content</div>
    <?php
    $navItem('dashboard', '/admin', '📊', 'Dashboard');
    $navItem('news', '/admin/news', '📰', 'Nieuws');
    $navItem('pages', '/admin/pages', '📄', "Pagina's");
    $navItem('media', '/admin/media', '🖼️', 'Media');
    ?>

    <div class="admin-nav-section">Community</div>
    <?php
    $navItem('users', '/admin/users', '👥', 'Gebruikers');
    $navItem('roles', '/admin/roles', '🔑', 'Rollen');
    $navItem('forum', '/admin/forum/boards', '💬', 'Forumborden');
    $navItem('contact', '/admin/contact', '✉️', 'Contact');
    ?>

    <div class="admin-nav-section">Uiterlijk</div>
    <?php
    $navItem('blocks', '/admin/blocks', '🧩', 'Blokken');
    $navItem('themes', '/admin/themes', '🎨', "Thema's");
    $navItem('menus', '/admin/menus', '🔗', "Menu's");
    ?>

    <div class="admin-nav-section">Systeem</div>
    <?php
    $navItem('modules', '/admin/modules', '⚙️', 'Modules');
    $navItem('settings', '/admin/settings', '🛠️', 'Instellingen');
    $navItem('logs', '/admin/logs', '📋', 'Logs');
    $navItem('marketplace', '/admin/marketplace', '🏪', 'Marketplace');
    ?>

  </nav>
  <div style="padding:1rem;border-top:1px solid var(--border);">
    <a href="/" class="admin-nav-link"><span class="nav-icon">🌐</span> Bekijk Site</a>
    <a href="/logout" class="admin-nav-link"><span class="nav-icon">👋</span> Uitloggen</a>
  </div>
</aside>

<?php
// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : admin_sidebar.php                                    ║
// ║  Role         : View partial                                        ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-09-28                                           ║
// ║  Status       : New — Wave 2                                         ║
// ║  Notes        : Gedeelde /admin/* sidebar; zie kop-commentaar        ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
