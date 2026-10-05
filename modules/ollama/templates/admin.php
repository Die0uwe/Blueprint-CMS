<?php // Copyright (C) 2026 DieOuwe — GPL-3.0-or-later ?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Ollama AI — Blueprint Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<style>
  .admin-wrap{display:flex;min-height:100vh;}
  .admin-sidebar{width:240px;background:var(--surface);border-right:1px solid var(--border);padding:1rem;position:fixed;top:0;left:0;height:100vh;}
  .admin-logo{font-size:1.1rem;font-weight:800;background:linear-gradient(135deg,#a855f7,#6c3df4);-webkit-background-clip:text;-webkit-text-fill-color:transparent;padding:.5rem 0 1rem;}
  .admin-nav-link{display:flex;align-items:center;gap:.6rem;padding:.5rem .8rem;border-radius:8px;font-size:.85rem;color:var(--text-dim);margin-bottom:2px;}
  .admin-nav-link:hover,.admin-nav-link.active{background:rgba(108,61,244,.15);color:var(--accent2);}
  .admin-main{margin-left:240px;flex:1;}
  .admin-content{padding:2rem;max-width:780px;}
  .status-badge{display:inline-flex;align-items:center;gap:.4rem;padding:.3rem .8rem;border-radius:20px;font-size:.78rem;font-weight:700;}
  .status-badge.online{background:rgba(16,185,129,.15);color:var(--success);border:1px solid rgba(16,185,129,.3);}
  .status-badge.offline{background:rgba(239,68,68,.1);color:var(--error);border:1px solid rgba(239,68,68,.3);}
  .model-tag{display:inline-block;padding:.2rem .6rem;background:rgba(108,61,244,.15);color:var(--accent2);border-radius:4px;font-size:.75rem;font-family:var(--font-mono);margin:.2rem;}
  .info-box{background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:8px;padding:1rem;font-size:.83rem;color:var(--text-dim);line-height:1.7;}
  code{background:rgba(255,255,255,.06);padding:.1rem .4rem;border-radius:4px;font-family:var(--font-mono);font-size:.82rem;}
</style>
</head>
<body>
<div class="admin-wrap">
  <aside class="admin-sidebar">
    <div class="admin-logo">🔮 Blueprint CMS</div>
    <a href="/admin" class="admin-nav-link">📊 Dashboard</a>
    <a href="/admin/blocks" class="admin-nav-link">🧩 Blokken</a>
    <a href="/admin/modules" class="admin-nav-link">⚙️ Modules</a>
    <a href="/admin/ollama" class="admin-nav-link active">🤖 Ollama AI</a>
    <a href="/admin/wow" class="admin-nav-link">🐉 WoW</a>
    <a href="/" class="admin-nav-link">🌐 Site</a>
  </aside>

  <div class="admin-main">
    <div class="admin-content">
      <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;">
        <h1 style="font-size:1.4rem;font-weight:800;">🤖 Ollama AI Integratie</h1>
        <?php if ($available): ?>
          <span class="status-badge online">🟢 <?= !empty($viaWebUi) ? 'Open WebUI' : 'Ollama' ?> online</span>
        <?php else: ?>
          <span class="status-badge offline">🔴 <?= !empty($viaWebUi) ? 'Open WebUI' : 'Ollama' ?> offline</span>
        <?php endif; ?>
      </div>

      <?php if(isset($_GET['saved'])): ?>
        <div class="cf-alert cf-alert-success" style="margin-bottom:1.2rem;">✅ Instellingen opgeslagen!</div>
      <?php endif; ?>

      <!-- Beschikbare modellen -->
      <?php if (!empty($models)): ?>
      <div class="cf-card" style="margin-bottom:1rem;">
        <div class="cf-card-header">📦 Geladen Modellen (<?= count($models) ?>)</div>
        <div class="cf-card-body">
          <?php foreach($models as $m): ?>
            <span class="model-tag"><?= htmlspecialchars($m['name'] ?? '') ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Instellingen -->
      <div class="cf-card" style="margin-bottom:1rem;">
        <div class="cf-card-header">⚙️ Instellingen</div>
        <div class="cf-card-body">
          <form method="POST" action="/admin/ollama/save">
            <?= \CommunityFusion\Core\Security\CsrfProtection::field() ?>

            <div style="display:grid;grid-template-columns:2fr 1fr;gap:1rem;">
              <div class="cf-form-group">
                <label class="cf-label">Ollama Host URL</label>
                <input class="cf-input" type="url" name="host"
                       value="<?= htmlspecialchars($settings['host'] ?? 'http://localhost:11434',ENT_QUOTES) ?>"
                       placeholder="http://localhost:11434">
              </div>
              <div class="cf-form-group">
                <label class="cf-label">Timeout (sec.)</label>
                <input class="cf-input" type="number" name="timeout"
                       value="<?= (int)($settings['timeout'] ?? 30) ?>" min="5" max="300">
              </div>
            </div>

            <div class="cf-form-group">
              <label class="cf-label">Standaard Model</label>
              <input class="cf-input" type="text" name="default_model" id="ol-model" list="ol-models"
                     value="<?= htmlspecialchars($settings['default_model'] ?? 'llama3.2',ENT_QUOTES) ?>"
                     placeholder="llama3.2, mistral, deepseek-r1:8b, qwen2.5, ...">
              <datalist id="ol-models">
                <?php
                  $seen = [];
                  foreach (($models ?? []) as $m) { $n = (string) ($m['name'] ?? ''); if ($n !== '') { $seen[$n] = true; echo '<option value="' . htmlspecialchars($n, ENT_QUOTES) . '">'; } }
                  foreach (\CommunityFusion\Modules\Ollama\OllamaConfig::DEEPSEEK_SUGGESTIONS as $n) { if (!isset($seen[$n])) echo '<option value="' . htmlspecialchars($n, ENT_QUOTES) . '">'; }
                ?>
              </datalist>
              <p style="font-size:.75rem;color:var(--muted);margin-top:.3rem;">
                Model moet geïnstalleerd zijn via <code>ollama pull llama3.2</code>.
                <strong>DeepSeek:</strong> <code>ollama pull deepseek-r1:8b</code> en vul <code>deepseek-r1:8b</code> hier in
                (7B/8B past op 8 GB videogeheugen, 14B op 12–16 GB, 32B op 24 GB). Het "denkwerk" van DeepSeek-R1 wordt automatisch uit het antwoord gehaald.
              </p>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;">
              <div class="cf-form-group">
                <label class="cf-label">Naam van je community</label>
                <input class="cf-input" type="text" name="guild_name" maxlength="100"
                       value="<?= htmlspecialchars($settings['guild_name'] ?? '',ENT_QUOTES) ?>" placeholder="Slayer Alliance">
              </div>
              <div class="cf-form-group">
                <label class="cf-label">Context-venster (num_ctx)</label>
                <input class="cf-input" type="number" name="num_ctx" min="0" max="131072" step="512"
                       value="<?= (int)($settings['num_ctx'] ?? 0) ?>" placeholder="0 = standaard">
              </div>
              <div class="cf-form-group">
                <label class="cf-label">Model geladen houden</label>
                <input class="cf-input" type="text" name="keep_alive" maxlength="8"
                       value="<?= htmlspecialchars($settings['keep_alive'] ?? '',ENT_QUOTES) ?>" placeholder="bv. 24h of -1">
              </div>
            </div>

            <div class="cf-form-group">
              <label class="cf-label">Systeem Prompt (community context)</label>
              <textarea class="cf-textarea" name="system_prompt" rows="3"
                        placeholder="Je bent een behulpzame community assistent..."><?= htmlspecialchars($settings['system_prompt'] ?? '',ENT_QUOTES) ?></textarea>
            </div>

            <div style="border-top:1px solid var(--border);padding-top:1rem;margin-top:.5rem;">
              <p style="font-size:.82rem;font-weight:700;color:var(--muted);margin-bottom:.8rem;">
                🖥️ Open WebUI (optioneel — voor geavanceerde UI)
              </p>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="cf-form-group">
                  <label class="cf-label">Open WebUI URL</label>
                  <input class="cf-input" type="url" name="open_webui_url"
                         value="<?= htmlspecialchars($settings['open_webui_url'] ?? '',ENT_QUOTES) ?>"
                         placeholder="http://localhost:3000">
                </div>
                <div class="cf-form-group">
                  <label class="cf-label">Open WebUI API Key</label>
                  <input class="cf-input" type="password" name="open_webui_key" autocomplete="new-password"
                         placeholder="<?= !empty($keySet) ? '●●●● (ingesteld — leeg laten = ongewijzigd)' : 'sk-...' ?>">
                  <?php if (!empty($keySet)): ?>
                    <label style="font-size:.75rem;color:var(--muted);display:flex;gap:.4rem;align-items:center;margin-top:.3rem;">
                      <input type="checkbox" name="clear_open_webui_key" value="1" style="width:auto;"> Sleutel wissen
                    </label>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div style="display:flex;justify-content:flex-end;margin-top:1rem;">
              <button type="submit" class="cf-btn">💾 Opslaan</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Verbindingstest -->
      <div class="cf-card" style="margin-bottom:1rem;">
        <div class="cf-card-header">🔌 Verbinding testen</div>
        <div class="cf-card-body">
          <p style="font-size:.82rem;color:var(--text-dim);margin-bottom:.8rem;">
            Test eerst <em>opslaan</em>, dan hier. De test controleert de verbinding, de modellen en stelt het gekozen model een korte vraag.
          </p>
          <button type="button" class="cf-btn" id="ol-test">▶ Test met het gekozen model</button>
          <div id="ol-result" style="margin-top:.9rem;font-size:.85rem;line-height:1.7;"></div>
        </div>
      </div>
      <script>
      (function () {
        var btn = document.getElementById('ol-test'), out = document.getElementById('ol-result');
        function esc(t) { return String(t).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
        btn.addEventListener('click', function () {
          btn.disabled = true; out.textContent = 'Bezig… (een groot model kan even duren)';
          var fd = new FormData();
          fd.append('_csrf_token', document.querySelector('input[name=_csrf_token]').value);
          fd.append('model', document.getElementById('ol-model').value);
          fetch('/admin/ollama/test', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
              var h = '';
              (d.steps || []).forEach(function (s) {
                h += '<div>' + (s.ok ? '✅ ' : '❌ ') + esc(s.label) + (s.hint ? '<div style="margin-left:1.6rem;color:var(--muted);">' + esc(s.hint) + '</div>' : '') + '</div>';
              });
              if (d.ok) h += '<div style="margin-top:.6rem;padding:.6rem .8rem;border:1px solid var(--border);border-radius:8px;">💬 ' + esc(d.reply) + ' <span style="color:var(--muted);">(' + d.ms + ' ms)</span></div>';
              out.innerHTML = h || 'Geen resultaat.';
            })
            .catch(function () { out.textContent = 'De test zelf mislukte (sessie verlopen? Herlaad de pagina).'; })
            .finally(function () { btn.disabled = false; });
        });
      })();
      </script>

      <!-- Open WebUI info -->
      <div class="cf-card">
        <div class="cf-card-header">📖 Open WebUI Setup</div>
        <div class="cf-card-body">
          <div class="info-box">
            <p><strong>Open WebUI v0.9.2</strong> — ChatGPT-achtige UI bovenop Ollama.</p>
            <p style="margin-top:.5rem;">Docker installatie (GPU):</p>
            <pre style="background:rgba(0,0,0,.3);padding:.8rem;border-radius:6px;margin:.5rem 0;font-size:.78rem;overflow-x:auto;"><code>docker run -d \
  --gpus all \
  -p 3000:8080 \
  -v open-webui:/app/backend/data \
  --name open-webui \
  ghcr.io/open-webui/open-webui:v0.9.2-cuda</code></pre>
            <p>CPU-only:</p>
            <pre style="background:rgba(0,0,0,.3);padding:.8rem;border-radius:6px;margin:.5rem 0;font-size:.78rem;overflow-x:auto;"><code>docker run -d \
  -p 3000:8080 \
  --add-host=host.docker.internal:host-gateway \
  -v open-webui:/app/backend/data \
  --name open-webui \
  ghcr.io/open-webui/open-webui:v0.9.2</code></pre>
            <p style="margin-top:.5rem;">Na installatie: ga naar <code>http://localhost:3000</code>, maak een admin account aan, ga naar <strong>Settings → API Keys</strong> en kopieer de key hierboven.</p>
            <p style="margin-top:.5rem;color:var(--gold);">⚡ Architectuur: Docker image beschikbaar voor linux/amd64, linux/arm64. CUDA versie vereist NVIDIA GPU + nvidia-container-toolkit.</p>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>
<?php include CF_ROOT . '/src/Modules/Shared/views/admin_mobile.php'; ?>
</body>
</html>
