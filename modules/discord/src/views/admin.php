<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// Eén view voor alle Discord-beheerschermen, gekozen met $tab (status|widget|notifications|roles).
// Gemeenschappelijk: $flash, $moduleOn, $guildId, $hasToken, $callback. Zie DiscordAdminController::view().

use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'discord';
$e   = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrf = CsrfProtection::field();
$yn  = static fn (?bool $v): string => $v === null ? '<span class="cf-badge cf-badge-gray">onbekend</span>' : ($v ? '<span class="cf-badge cf-badge-green">Ja</span>' : '<span class="cf-badge cf-badge-red">Nee</span>');
$tabs = ['status' => ['/admin/discord', 'Status'], 'widget' => ['/admin/discord/widget', 'Widget'], 'notifications' => ['/admin/discord/meldingen', 'Meldingen'], 'roles' => ['/admin/discord/rollen', 'Rolkoppeling']];
$titles = ['status' => 'Status', 'widget' => 'Widget', 'notifications' => 'Meldingen', 'roles' => 'Rolkoppeling'];
?>
<!DOCTYPE html>
<html lang="<?= $e(\CommunityFusion\Core\I18n\Trans::locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Discord — <?= $e($titles[$tab] ?? '') ?> — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../../../src/Modules/Shared/views/admin_styles.php'; ?>
<style>
.dc-tabs { display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:1.2rem; }
.dc-tabs a { padding:.4rem .9rem; border-radius:8px; border:1px solid var(--border); color:var(--text-dim); text-decoration:none; font-size:.85rem; }
.dc-tabs a.on { background:rgba(108,61,244,.15); color:var(--text); border-color:rgba(108,61,244,.5); }
.dc-card { margin-bottom:1.2rem; padding:1rem 1.2rem; }
.dc-card h2 { font-size:1rem; margin:0 0 .6rem; }
.dc-help { color:var(--text-dim); font-size:.8rem; margin:.3rem 0 0; line-height:1.5; }
.dc-dl { display:grid; grid-template-columns:max-content 1fr; gap:.35rem 1rem; font-size:.88rem; margin:0; }
.dc-dl dt { color:var(--text-dim); } .dc-dl dd { margin:0; }
.dc-row { display:flex; gap:.6rem; flex-wrap:wrap; align-items:end; }
.dc-row > div { min-width:180px; }
.dc-warn { background:rgba(245,158,11,.12); border:1px solid rgba(245,158,11,.4); border-radius:10px; padding:.7rem 1rem; margin-bottom:1rem; font-size:.85rem; }
code.dc-url { word-break:break-all; background:rgba(255,255,255,.08); padding:.1rem .35rem; border-radius:4px; }
</style>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../../../src/Modules/Shared/views/admin_sidebar.php'; ?>
  <div class="admin-main">
    <header class="admin-topbar"><h1>🎮 Discord</h1></header>
    <div class="admin-content">

      <nav class="dc-tabs" aria-label="Discord-beheer">
        <?php foreach ($tabs as $k => [$href, $label]): ?>
          <a href="<?= $e($href) ?>" class="<?= $k === $tab ? 'on' : '' ?>"><?= $e($label) ?></a>
        <?php endforeach; ?>
        <a href="/admin/marketplace/package/discord/instellingen">⚙️ Moduleinstellingen</a>
      </nav>

      <?php if ($flash): ?>
        <div class="cf-alert <?= $flash['type'] === 'ok' ? 'cf-alert-success' : 'cf-alert-error' ?>" role="status"><?= $e($flash['msg']) ?></div>
      <?php endif; ?>

      <?php if (!$moduleOn): ?>
        <div class="dc-warn">⚠️ De Discord-module staat <strong>uit</strong>. Instellingen worden wel bewaard, maar blokken, inloggen en meldingen werken pas als je de module inschakelt (Beheer → Marketplace).</div>
      <?php endif; ?>

<?php if ($tab === 'status'): ?>
      <div class="cf-card dc-card">
        <h2>Verbinding</h2>
        <dl class="dc-dl">
          <dt>Guild/Server ID</dt><dd><?= $guildId !== '' ? $e($guildId) : '<em>niet ingesteld</em>' ?></dd>
          <dt>Bot Token</dt><dd><?= $hasToken ? 'ingesteld ✔' : '<em>niet ingesteld</em>' ?></dd>
        </dl>
        <form method="post" action="/admin/discord/test" style="margin-top:.8rem;">
          <?= $csrf ?>
          <button class="cf-btn" <?= $hasToken ? '' : 'disabled' ?>>🔌 Test verbinding</button>
        </form>
        <?php if (!$hasToken): ?><p class="dc-help">Vul het Bot Token en Guild/Server ID in bij de <a href="/admin/marketplace/package/discord/instellingen">moduleinstellingen</a>.</p><?php endif; ?>
      </div>

      <?php if ($test): ?>
      <div class="cf-card dc-card">
        <h2>Resultaat van de test</h2>
        <dl class="dc-dl">
          <dt>Bot-token geldig</dt><dd><?= $yn($test->tokenValid) ?><?= $test->botName !== '' ? ' &nbsp;' . $e($test->botName) : '' ?></dd>
          <dt>Bot zit in de server</dt><dd><?= $yn($test->inGuild) ?><?= $test->guildName !== '' ? ' &nbsp;' . $e($test->guildName) : '' ?><?= $test->memberCount !== null ? ' (' . (int) $test->memberCount . ' leden)' : '' ?></dd>
          <dt>Widget aan</dt><dd><?= $yn($test->widgetEnabled) ?></dd>
          <dt>Widget-kanaal</dt><dd><?= $test->widgetChannelId !== null ? '#' . $e($test->widgetChannelName !== '' ? $test->widgetChannelName : $test->widgetChannelId) : '<em>geen</em>' ?></dd>
          <dt>Rechten van de bot</dt><dd><?= $test->permissions === null ? '<span class="cf-badge cf-badge-gray">niet te bepalen</span>' : ($test->permissions === [] ? '<em>geen relevante rechten</em>' : $e(implode(', ', $test->permissions))) ?></dd>
          <dt>Serverbeheer (widget aanzetten)</dt><dd><?= $yn($test->canManageGuild) ?></dd>
        </dl>
        <?php foreach ($test->problems as $p): ?><p style="color:#fca5a5;font-size:.85rem;margin:.5rem 0 0;">⚠ <?= $e($p) ?></p><?php endforeach; ?>
        <?php if ($test->problems === []): ?><p style="color:#86efac;font-size:.85rem;margin:.6rem 0 0;">✔ Alles lijkt in orde.</p><?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="cf-card dc-card">
        <h2>Bot uitnodigen</h2>
        <?php if ($inviteUrl !== null): ?>
          <p class="dc-help" style="margin-top:0;">Nodig de bot uit met rechten <em>Kanalen bekijken</em> en <em>Serverbeheer</em> (permissions=1056, scope=bot):</p>
          <p><a class="cf-btn-sm" href="<?= $e($inviteUrl) ?>" target="_blank" rel="noopener noreferrer">Bot uitnodigen in Discord</a></p>
        <?php else: ?>
          <p class="dc-help" style="margin-top:0;">Vul het <em>Client ID</em> in bij de moduleinstellingen, dan verschijnt hier de uitnodigingslink voor de bot.</p>
        <?php endif; ?>
      </div>

      <div class="cf-card dc-card">
        <h2>Callback-URL voor inloggen</h2>
        <p class="dc-help" style="margin-top:0;">Zet deze URL exact bij <em>OAuth2 → Redirects</em> in het <a href="https://discord.com/developers/applications" target="_blank" rel="noopener noreferrer">Discord Developer Portal</a>:</p>
        <p><code class="dc-url"><?= $e($callback['effective']) ?></code></p>
        <?php foreach ($callback['warnings'] as $w): ?><div class="dc-warn">⚠️ <?= $e($w) ?></div><?php endforeach; ?>
      </div>

<?php elseif ($tab === 'widget'): ?>
      <div class="cf-card dc-card">
        <h2>Server-widget</h2>
        <?php if ($apiError): ?>
          <div class="dc-warn">⚠️ <?= $e($apiError) ?></div>
        <?php elseif ($widget !== null): ?>
          <dl class="dc-dl">
            <dt>Widget aan</dt><dd><?= $yn((bool) ($widget['enabled'] ?? false)) ?></dd>
            <dt>Kanaal</dt><dd><?php
              $cur = isset($widget['channel_id']) ? (string) $widget['channel_id'] : '';
              $name = '';
              foreach ($channels as $c) { if ($c['id'] === $cur) { $name = $c['name']; } }
              echo $cur !== '' ? '#' . $e($name !== '' ? $name : $cur) : '<em>geen kanaal</em>';
            ?></dd>
          </dl>
          <form method="post" action="/admin/discord/widget" style="margin-top:1rem;">
            <?= $csrf ?>
            <div class="dc-row">
              <div>
                <label class="cf-label" for="dc-ch">Uitnodigingskanaal</label>
                <select id="dc-ch" name="channel_id" class="cf-input">
                  <?php foreach ($channels as $c): ?>
                    <option value="<?= $e($c['id']) ?>" <?= $c['id'] === $cur ? 'selected' : '' ?>>#<?= $e($c['name']) ?><?= $c['type'] === 5 ? ' (aankondigingen)' : '' ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="cf-btn" name="action" value="enable" <?= $channels === [] ? 'disabled' : '' ?>>Inschakelen</button>
              <?php if (!empty($widget['enabled'])): ?><button class="cf-btn-ghost" name="action" value="disable">Uitschakelen</button><?php endif; ?>
            </div>
          </form>
        <?php endif; ?>
        <p class="dc-help">De bot heeft het recht <strong>Serverbeheer</strong> (MANAGE_GUILD) nodig om de widget aan te zetten. Geeft Discord een foutmelding, dan zie je die hier letterlijk terug.
          Het widget-blok toont daarna de server; wil je geen widget, gebruik dan het blok <em>Discord Status</em>.</p>
        <?php if (!empty($inviteUrl)): ?><p class="dc-help">Mist de bot dat recht? <a href="<?= $e($inviteUrl) ?>" target="_blank" rel="noopener noreferrer">Nodig hem opnieuw uit</a> met de juiste rechten.</p><?php endif; ?>
      </div>

<?php elseif ($tab === 'notifications'): ?>
      <div class="cf-card dc-card">
        <h2>Nieuws melden in Discord</h2>
        <p class="dc-help" style="margin-top:0;">Maak in Discord een webhook: Kanaalinstellingen → Integraties → Webhooks → Nieuwe webhook → URL kopiëren. De URL is een geheim: hij wordt versleuteld bewaard en hier nooit meer getoond.</p>
        <form method="post" action="/admin/discord/meldingen/opslaan">
          <?= $csrf ?>
          <div class="cf-form-group">
            <label class="cf-label" for="dc-wh">Webhook-URL</label>
            <input id="dc-wh" type="password" name="webhook_url" class="cf-input" autocomplete="off" spellcheck="false"
                   placeholder="<?= $webhookSet ? $e($webhookMask . ' — laat leeg om te behouden') : 'https://discord.com/api/webhooks/…' ?>">
            <p class="dc-help">Alleen https://discord.com/api/webhooks/&lt;id&gt;/&lt;token&gt; wordt geaccepteerd.</p>
          </div>
          <label style="display:flex;align-items:center;gap:.4rem;margin:.6rem 0;">
            <input type="checkbox" name="announce_news" value="1" style="width:auto;" <?= $announce ? 'checked' : '' ?>>
            Een nieuw gepubliceerd nieuwsartikel automatisch melden
          </label>
          <button class="cf-btn">Opslaan</button>
        </form>
        <?php if ($webhookSet): ?>
          <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1rem;">
            <form method="post" action="/admin/discord/meldingen/test"><?= $csrf ?><button class="cf-btn-sm">Testbericht sturen</button></form>
            <form method="post" action="/admin/discord/meldingen/verwijderen" onsubmit="return confirm('Webhook verwijderen? Nieuwsmeldingen stoppen dan.');"><?= $csrf ?><button class="cf-btn-sm">Webhook verwijderen</button></form>
          </div>
          <p class="dc-help">Huidige webhook: <?= $e($webhookMask) ?></p>
        <?php endif; ?>
        <p class="dc-help">Meldingen werken alleen als de Discord-module aan staat. Een mislukte melding wordt gelogd en blokkeert het publiceren nooit.</p>
      </div>

<?php elseif ($tab === 'roles'): ?>
      <div class="cf-card dc-card">
        <h2>Rolkoppeling Discord → CMS</h2>
        <p class="dc-help" style="margin-top:0;">Wie een gekoppelde Discord-rol heeft, krijgt de bijbehorende CMS-rol. Met <em>verwijderen</em> aan verliest hij die CMS-rol weer als de Discord-rol weg is.
          De rollen <code>super_admin</code> en <code>admin</code> kunnen niet worden gekoppeld of via Discord worden gewijzigd.</p>
        <?php if ($apiError): ?><div class="dc-warn">⚠️ <?= $e($apiError) ?></div><?php endif; ?>

        <table class="cf-table" style="width:100%;">
          <thead><tr><th>Discord-rol (ID)</th><th>CMS-rol</th><th>Verwijderen bij weg</th><th style="text-align:right;">Acties</th></tr></thead>
          <tbody>
          <?php if (!$mappings): ?><tr><td colspan="4" class="cf-table-empty">Nog geen koppelingen.</td></tr><?php endif; ?>
          <?php
            $dNames = [];
            foreach ($discordRoles as $dr) { $dNames[$dr['id']] = $dr['name']; }
            foreach ($mappings as $m): ?>
            <tr>
              <td><?= isset($dNames[$m['discord_role_id']]) ? '<strong>@' . $e($dNames[$m['discord_role_id']]) . '</strong> ' : '' ?><small style="color:var(--muted);"><?= $e($m['discord_role_id']) ?></small></td>
              <td colspan="2">
                <form method="post" action="/admin/discord/rollen/<?= (int) $m['id'] ?>/bewerk" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                  <?= $csrf ?>
                  <select name="cms_role_id" class="cf-input" style="max-width:220px;" aria-label="CMS-rol">
                    <?php foreach ($cmsRoles as $r): if ($r['protected'] && $r['id'] !== $m['cms_role_id']) { continue; } ?>
                      <option value="<?= (int) $r['id'] ?>" <?= $r['id'] === $m['cms_role_id'] ? 'selected' : '' ?>><?= $e($r['display_name']) ?> (<?= $e($r['name']) ?>)</option>
                    <?php endforeach; ?>
                  </select>
                  <label style="display:flex;align-items:center;gap:.3rem;font-size:.85rem;"><input type="checkbox" name="auto_remove" value="1" style="width:auto;" <?= $m['auto_remove'] ? 'checked' : '' ?>> ja</label>
                  <button class="cf-btn-sm">Opslaan</button>
                </form>
              </td>
              <td style="text-align:right;">
                <form method="post" action="/admin/discord/rollen/<?= (int) $m['id'] ?>/verwijderen" onsubmit="return confirm('Koppeling verwijderen?');"><?= $csrf ?><button class="cf-btn-sm">Verwijderen</button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="cf-card dc-card">
        <h2>Koppeling toevoegen</h2>
        <form method="post" action="/admin/discord/rollen/toevoegen">
          <?= $csrf ?>
          <div class="dc-row">
            <?php if ($discordRoles): ?>
            <div>
              <label class="cf-label" for="dc-dr">Discord-rol</label>
              <select id="dc-dr" name="discord_role_id" class="cf-input">
                <?php foreach ($discordRoles as $dr): ?><option value="<?= $e($dr['id']) ?>">@<?= $e($dr['name']) ?><?= $dr['managed'] ? ' (bot/integratie)' : '' ?></option><?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
            <div>
              <label class="cf-label" for="dc-drm"><?= $discordRoles ? 'of rol-ID invullen' : 'Discord-rol-ID' ?></label>
              <input id="dc-drm" name="discord_role_manual" class="cf-input" inputmode="numeric" pattern="\d{15,25}" placeholder="123456789012345678" autocomplete="off">
            </div>
            <div>
              <label class="cf-label" for="dc-cr">CMS-rol</label>
              <select id="dc-cr" name="cms_role_id" class="cf-input">
                <?php foreach ($cmsRoles as $r): if ($r['protected']) { continue; } ?>
                  <option value="<?= (int) $r['id'] ?>"><?= $e($r['display_name']) ?> (<?= $e($r['name']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <label style="display:flex;align-items:center;gap:.3rem;font-size:.85rem;"><input type="checkbox" name="auto_remove" value="1" style="width:auto;" checked> Verwijderen als Discord-rol weg is</label>
            <button class="cf-btn">Toevoegen</button>
          </div>
          <p class="dc-help">Het rol-ID vind je met Ontwikkelaarsmodus aan in Discord: rechtsklik de rol (Serverinstellingen → Rollen) → "Rol-ID kopiëren". Een ingevuld ID gaat voor op de lijst.</p>
        </form>
      </div>

      <div class="cf-card dc-card">
        <h2>Wanneer wordt er gesynchroniseerd?</h2>
        <p class="dc-help" style="margin-top:0;">Bij inloggen/koppelen met Discord, en (met Bot Token) via de wachtrij <code>discord-sync</code>. Laat daarvoor een worker draaien:
          <code>php cli/console.php queue:work --queue=discord-sync</code></p>
        <?php if ($syncLog): ?>
        <table class="cf-table" style="width:100%;margin-top:.6rem;">
          <thead><tr><th>Moment</th><th>Gebruiker</th><th>Actie</th><th>Detail</th></tr></thead>
          <tbody>
          <?php foreach ($syncLog as $l): ?>
            <tr><td><?= $e($l['synced_at']) ?></td><td><?= $e($l['username'] ?? ('#' . $l['user_id'])) ?></td><td><?= $e($l['action']) ?></td><td><?= $e($l['detail'] ?? '') ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
<?php endif; ?>

    </div>
  </div>
</div>
</body>
</html>
