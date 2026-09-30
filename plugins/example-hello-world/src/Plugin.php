<?php
// ============================================================================
// Voorbeeldplugin — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Plugins\ExampleHelloWorld;

use CommunityFusion\Core\Plugin\PluginContext;
use CommunityFusion\Core\Plugin\PluginInterface;

/**
 * Laat zien wat een plugin mag:
 *  - een knop aan de editor-toolbar toevoegen (filter editor.toolbar.register: alleen platte tekst, geen JavaScript),
 *  - de uitvoer van pagina's/nieuws aanpassen (filter content.after_render),
 *  - instellingen lezen via $context->setting().
 * Een plugin wijzigt nooit bestanden in src/Core/ en mag alleen tabellen cf_plg_example_hello_world_* aanmaken.
 */
final class Plugin implements PluginInterface
{
    public function boot(PluginContext $context): void
    {
        $context->hooks->addFilter('editor.toolbar.register', static function (array $buttons): array {
            $buttons[] = ['id' => 'hallo', 'label' => '👋 Hallo', 'title' => 'Voeg [hallo] in', 'before' => '[hallo]', 'after' => ''];
            return $buttons;
        });

        $greeting = trim((string)$context->setting('greeting', 'Hallo wereld'));
        $greeting = $greeting === '' ? 'Hallo wereld' : $greeting;
        if ($context->setting('shout', false)) {
            $greeting = mb_strtoupper($greeting);
        }
        $safe = htmlspecialchars($greeting, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');   // altijd escapen

        $context->hooks->addFilter('content.after_render', static fn (string $html): string => str_replace('[hallo]', $safe, $html));
    }
}
