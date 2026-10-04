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

use CommunityFusion\Core\I18n\Trans;

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

    <div class="admin-nav-section"><?= htmlspecialchars(Trans::get('admin.sidebar.section_content')) ?></div>
    <?php
    $navItem('dashboard', '/admin', '📊', Trans::get('admin.sidebar.dashboard'));
    $navItem('news', '/admin/news', '📰', Trans::get('admin.sidebar.news'));
    $navItem('blog', '/admin/blog', '✍️', Trans::get('admin.sidebar.blog'));
    $navItem('downloads', '/admin/downloads', '📥', Trans::get('admin.sidebar.downloads'));
    $navItem('pages', '/admin/pages', '📄', Trans::get('admin.sidebar.pages'));
    $navItem('media', '/admin/media', '🖼️', Trans::get('admin.sidebar.media'));
    $navItem('gallery', '/admin/gallery', '📷', Trans::get('admin.sidebar.gallery'));
    ?>

    <div class="admin-nav-section"><?= htmlspecialchars(Trans::get('admin.sidebar.section_community')) ?></div>
    <?php
    $navItem('users', '/admin/users', '👥', Trans::get('admin.sidebar.users'));
    $navItem('roles', '/admin/roles', '🔑', Trans::get('admin.sidebar.roles'));
    $navItem('forum', '/admin/forum/boards', '💬', Trans::get('admin.sidebar.forum'));
    $navItem('forum_moderation', '/admin/forum/moderatie', '🛡️', Trans::get('admin.sidebar.forum_moderation'));
    $navItem('contact', '/admin/contact', '✉️', Trans::get('admin.sidebar.contact'));
    ?>

    <div class="admin-nav-section"><?= htmlspecialchars(Trans::get('admin.sidebar.section_appearance')) ?></div>
    <?php
    $navItem('blocks', '/admin/blocks', '🧩', Trans::get('admin.sidebar.blocks'));
    $navItem('themes', '/admin/themes', '🎨', Trans::get('admin.sidebar.themes'));
    $navItem('menus', '/admin/menus', '🔗', Trans::get('admin.sidebar.menus'));
    ?>

    <div class="admin-nav-section"><?= htmlspecialchars(Trans::get('admin.sidebar.section_system')) ?></div>
    <?php
    $navItem('modules', '/admin/modules', '⚙️', Trans::get('admin.sidebar.modules'));
    $navItem('settings', '/admin/settings', '🛠️', Trans::get('admin.sidebar.settings'));
    $navItem('logs', '/admin/logs', '📋', Trans::get('admin.sidebar.logs'));
    $navItem('marketplace', '/admin/marketplace', '🏪', Trans::get('admin.sidebar.marketplace'));
    $navItem('apistatus', '/admin/api-status', '📡', Trans::get('admin.sidebar.api_status'));
    ?>

  </nav>
  <div style="padding:1rem;border-top:1px solid var(--border);">
    <a href="/" class="admin-nav-link"><span class="nav-icon">🌐</span> <?= htmlspecialchars(Trans::get('admin.sidebar.view_site')) ?></a>
    <a href="/logout" class="admin-nav-link"><span class="nav-icon">👋</span> <?= htmlspecialchars(Trans::get('admin.sidebar.logout')) ?></a>
  </div>
</aside>
<?php include __DIR__ . '/admin_mobile.php'; ?>

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
