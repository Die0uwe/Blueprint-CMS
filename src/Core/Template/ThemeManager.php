<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Template;

/**
 * ThemeManager — beheert het actieve thema en rendert Twig-templates.
 * Laadt thema-configuratie uit theme.json en biedt een render() methode.
 */
final class ThemeManager
{
    private \Twig\Environment $twig;
    private array $themeConfig = [];
    private string $activeTheme;

    public function __construct(
        private readonly string $themesPath,
        string $theme = 'default',
    ) {
        $this->activeTheme = $theme;
        $this->boot();
    }

    private function boot(): void
    {
        $themePath = $this->themesPath . '/' . $this->activeTheme;

        if (!is_dir($themePath)) {
            $themePath = $this->themesPath . '/default';
        }

        // Laad theme.json
        $jsonPath = $themePath . '/theme.json';
        if (file_exists($jsonPath)) {
            $this->themeConfig = json_decode(file_get_contents($jsonPath), true) ?? [];
        }

        // Twig setup
        $loader     = new \Twig\Loader\FilesystemLoader($themePath . '/templates');
        $this->twig = new \Twig\Environment($loader, [
            'cache'       => CF_ROOT . '/storage/cache/twig',
            'auto_reload' => true,
            'debug'       => ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
        ]);

        // Globale variabelen beschikbaar in alle templates
        $this->twig->addGlobal('theme', $this->themeConfig);
        $this->twig->addGlobal('cms_version', CF_VERSION ?? '1.0.0');

        // Custom Twig functies
        $this->registerFunctions();
    }

    /**
     * Render een Twig-template naar HTML.
     *
     * @param string $template Relatief pad: 'news/index.twig'
     * @param array  $vars     Template variabelen
     */
    public function render(string $template, array $vars = []): string
    {
        return $this->twig->render($template, $vars);
    }

    /**
     * Render een blok via zijn PHP render()-methode en geef HTML terug.
     */
    public function renderBlock(array $blockRow, array $context = []): string
    {
        // Block rendering wordt door BlockManager afgehandeld
        return '';
    }

    public function getConfig(): array
    {
        return $this->themeConfig;
    }

    public function getActiveTheme(): string
    {
        return $this->activeTheme;
    }

    public function getTwig(): \Twig\Environment
    {
        return $this->twig;
    }

    public function setBlockRegistry(\CommunityFusion\Core\Block\BlockRegistry $registry): void
    {
        $this->twig->addFunction(new \Twig\TwigFunction('render_block', function(array $blockRow) use ($registry): string {
            return $registry->renderBlock($blockRow);
        }));
        // Veilige placeholder tot Application::boot() dit meteen daarna overschrijft
        // met de échte zone-inhoud (addGlobal('zones', [...])) — dit was tot
        // v1.16.0 de ENIGE plek waar `zones` gezet werd, permanent leeg, waardoor
        // geen enkel geplaatst block ooit op de site verscheen. Zie de
        // syncTypesToDatabase()-fix + dit commentaar in Application::boot().
        $this->twig->addGlobal('zones', []);
    }

    /**
     * Registreer een globale Twig-variabele, beschikbaar in elke template
     * zonder dat elke controller hem los hoeft mee te geven.
     *
     * Gebruikt door Application::boot() om `auth`, `settings` en
     * `menu_pages` te injecteren — layout.twig verwijst hier al sinds
     * Sprint 3 naar (`auth.check()`, `settings.site_name`, `menu_pages`),
     * maar geen enkele controller gaf ze door: Twig faalt niet hard op een
     * undefined global (non-strict mode), dus dit bleef onopgemerkt —
     * de header toonde altijd "Inloggen", nooit "Admin"/"Uitloggen", en
     * settings.* en het menu waren overal leeg.
     */
    public function addGlobal(string $name, mixed $value): void
    {
        $this->twig->addGlobal($name, $value);
    }

    /**
     * Registreert de `trans()`-functie en `|trans`-filter (S13 —
     * Multi-language/i18n), beide gedelegeerd naar dezelfde
     * `Core\I18n\Translator`-instantie die Application::boot() per-request
     * al heeft opgelost (bezoekerstaal, zie Translator's docblok voor de
     * resolutievolgorde). Twee syntaxen voor hetzelfde, naar smaak van de
     * template: `{{ trans('nav.news') }}` of `{{ 'nav.news'|trans }}`, beide
     * met optionele `:placeholder`-vervangingen als tweede argument.
     */
    public function setTranslator(\CommunityFusion\Core\I18n\Translator $translator): void
    {
        $this->twig->addFunction(new \Twig\TwigFunction('trans', function(string $key, array $replace = []) use ($translator): string {
            return $translator->trans($key, $replace);
        }));
        $this->twig->addFilter(new \Twig\TwigFilter('trans', function(string $key, array $replace = []) use ($translator): string {
            return $translator->trans($key, $replace);
        }));
    }

    private function registerFunctions(): void
    {
        // {{ asset('css/style.css') }} → /assets/css/style.css
        $this->twig->addFunction(new \Twig\TwigFunction('asset', function(string $path): string {
            return '/assets/' . ltrim($path, '/');
        }));

        // {{ url('/nieuws') }} → https://site.nl/nieuws
        $this->twig->addFunction(new \Twig\TwigFunction('url', function(string $path): string {
            $base = rtrim($_ENV['APP_URL'] ?? '', '/');
            return $base . '/' . ltrim($path, '/');
        }));

        // {{ csrf_field() | raw }}
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', function(): string {
            return \CommunityFusion\Core\Security\CsrfProtection::field();
        }));

        // {{ post.content|nl2br }} — HOOG-bevinding uit de totale-codebase-
        // audit (v1.25.5+): blog.show.twig gebruikte |raw op ledencontent
        // (elk lid mag een blog-post maken, alleen $auth-middleware, geen
        // permissiecheck — zie src/Core/Router.php) i.p.v. het admin/
        // moderator-only content van News/Pages, waar |raw wél terecht is
        // (news.create/pages.manage — vertrouwde rollen, bewuste rich-HTML-
        // keuze). Resultaat: stored XSS — elk geregistreerd lid kon
        // <script> in zijn blogpost zetten en die voerde onversleuteld uit
        // voor iedere bezoeker (incl. beheerders) die de post bekeek. Deze
        // filter doet zelf de escaping (htmlspecialchars) vóór de
        // nl2br-conversie en is dus veilig ongeacht chain-positie — in
        // tegenstelling tot Twig's eigen `escape|nl2br`-keten waarbij de
        // volgorde er wél toe doet.
        $this->twig->addFilter(new \Twig\TwigFilter('nl2br', function (?string $value): string {
            $escaped = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            return nl2br($escaped);
        }, ['is_safe' => ['html']]));
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: ThemeManager.php | Role: Core | Version: 1.0.0               ║
// ║  Created: 2026-06-06 | Status: New                                  ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ        ║
// ╚══════════════════════════════════════════════════════════════════════╝
