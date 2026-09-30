<form method="POST">
  <p style="margin-bottom:.5rem;color:var(--muted);font-size:.9rem;">
    Kernmodules zijn altijd actief — ze draaien via vaste routes en kunnen niet uitgeschakeld
    worden. Optionele modules kun je hieronder aan- of uitzetten; je kunt dit later ook nog
    wijzigen via de Marketplace in het admin-paneel.
  </p>

  <h3 style="margin:1.5rem 0 .5rem;font-size:.95rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;">
    Kernmodules — altijd actief
  </h3>
  <div class="modules-grid">
    <?php
    // Kernmodules zijn hardcoded in Router::registerCoreRoutes() — geen
    // cf_modules-vlag bepaalt of ze actief zijn, dus ze krijgen hier bewust
    // GEEN (uitgeschakelde) checkbox: dat zou een schakelaar tonen die niets
    // doet. Lijst gesynchroniseerd met src/Modules/ + Router.php.
    $coreModules = [
      ['icon'=>'👤', 'name'=>'Gebruikers',  'desc'=>'Login, registratie, profielen, RBAC'],
      ['icon'=>'📰', 'name'=>'Nieuws',      'desc'=>'Artikelen, categorieën'],
      ['icon'=>'📄', 'name'=>"Pagina's",    'desc'=>"CMS pagina's + menu"],
      ['icon'=>'⚙️', 'name'=>'Instellingen', 'desc'=>'Admin configuratie'],
      ['icon'=>'💬', 'name'=>'Forum',       'desc'=>'Borden, topics, reacties'],
      ['icon'=>'✍️', 'name'=>'Blog',        'desc'=>'Persoonlijke blogs per lid'],
      ['icon'=>'📦', 'name'=>'Downloads',   'desc'=>'Bestandsbeheer'],
      ['icon'=>'✉️', 'name'=>'Contact',     'desc'=>'Contactformulier + inbox'],
    ];
    foreach ($coreModules as $m):
    ?>
      <label class="module-card">
        <input type="checkbox" checked disabled>
        <div class="module-icon"><?= $m['icon'] ?></div>
        <div>
          <div class="module-name"><?= htmlspecialchars($m['name']) ?></div>
          <div class="module-desc"><?= htmlspecialchars($m['desc']) ?></div>
          <div class="module-core">Core ●</div>
        </div>
      </label>
    <?php endforeach; ?>
  </div>

  <h3 style="margin:2rem 0 .5rem;font-size:.95rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;">
    Optionele modules
  </h3>
  <div class="modules-grid">
    <?php
    // Dynamisch opgebouwd uit modules/*/module.json i.p.v. een handmatig
    // bijgehouden lijst — voorkomt dat deze pagina uit de pas loopt met wat
    // er daadwerkelijk in modules/ staat (zoals voorheen: een 'guild'-entry
    // die niet overeenkwam met de echte map guild-management/, en een
    // 'youtube'-entry die helemaal niet bestond).
    $icons = [
        'discord'          => '🎮',
        'twitch'           => '📺',
        'google'           => '🔑',
        'github'           => '🐙',
        'battlenet'        => '🌀',
        'youtube'          => '▶️',
        'kick'             => '🟢',
        'guild-management' => '⚔️',
        'minecraft'        => '🟫',
        'fivem'            => '🚓',
        'ollama'           => '🤖',
        'warcraft'         => '🐉',
    ];
    $optionalModules = [];
    foreach (glob(CF_ROOT . '/modules/*/module.json') ?: [] as $manifestPath) {
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!is_array($manifest) || empty($manifest['slug'])) continue;
        $optionalModules[] = [
            'slug' => $manifest['slug'],
            'icon' => $icons[$manifest['slug']] ?? '🧩',
            'name' => $manifest['name'] ?? $manifest['slug'],
            'desc' => $manifest['description'] ?? '',
        ];
    }
    usort($optionalModules, fn($a, $b) => strcmp($a['name'], $b['name']));

    foreach ($optionalModules as $m):
    ?>
      <label class="module-card">
        <input type="checkbox" name="modules[]" value="<?= htmlspecialchars($m['slug']) ?>">
        <div class="module-icon"><?= $m['icon'] ?></div>
        <div>
          <div class="module-name"><?= htmlspecialchars($m['name']) ?></div>
          <div class="module-desc"><?= htmlspecialchars($m['desc']) ?></div>
        </div>
      </label>
    <?php endforeach; ?>
    <?php if (empty($optionalModules)): ?>
      <p style="color:var(--muted);">Geen optionele modules gevonden in <code>modules/</code>.</p>
    <?php endif; ?>
  </div>

  <div class="btn-row" style="margin-top:2rem;">
    <button class="btn" type="submit">🚀 Installatie Voltooien</button>
  </div>
</form>
