<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Template;

use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * MarkupRenderer — rendert gebruikersmarkup (HTML + Twig) veilig.
 *
 * - PHP-tags worden NOOIT uitgevoerd: ze worden vóór Twig omgezet naar zichtbare, ge-escapete tekst.
 * - Twig draait in een sandbox met een strikte whitelist (config/markup-block.php) en ziet
 *   alleen de scalars/arrays die de aanroeper meegeeft (geen auth, settings, zones, objecten).
 * - Grenzen op grootte, aantal lussen, de range-operator en rekentijd.
 */
final class MarkupRenderer
{
    /** @var array{tags:list<string>,filters:list<string>,functions:list<string>,max_bytes:int,max_for_tags:int,time_limit:int} */
    private array $config;
    private ?Environment $twig = null;

    /** @param array<string,mixed>|null $config Standaard: config/markup-block.php */
    public function __construct(?array $config = null)
    {
        $default = [
            'tags' => ['if', 'for', 'set'], 'filters' => ['escape', 'e'], 'functions' => [],
            'max_bytes' => 204800, 'max_for_tags' => 3, 'time_limit' => 3,
        ];
        if ($config === null) {
            $file = dirname(__DIR__, 3) . '/config/markup-block.php';   // relatief aan de code, niet aan CF_ROOT
            $config = is_file($file) ? (array)(require $file) : [];
        }
        $this->config = array_merge($default, $config);
    }

    public function maxBytes(): int
    {
        return (int)$this->config['max_bytes'];
    }

    /** Bevat de markup PHP-openingstags (<?php, <?= of <?)? */
    public function containsPhp(string $markup): bool
    {
        return (bool)preg_match('/<\?/', $markup);
    }

    /** Zet PHP-blokken om naar zichtbare, ge-escapete tekst (nooit uitgevoerd, nooit stil verdwenen). */
    public function neutralizePhp(string $markup): string
    {
        return (string)preg_replace_callback(
            '/<\?(?:.*?\?>|.*$)/s',
            static fn (array $m): string => htmlspecialchars($m[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $markup
        );
    }

    /**
     * @param array<string,scalar|array<mixed>|null> $context Alleen veilige waarden
     * @throws MarkupException
     */
    public function render(string $markup, array $context = []): string
    {
        $this->assertLimits($markup);
        $markup = $this->neutralizePhp($markup);

        $limit = (int)$this->config['time_limit'];
        $previous = (int)ini_get('max_execution_time');
        if ($limit > 0) {
            @set_time_limit($limit);
        }
        try {
            $twig = $this->twig();
            $twig->getLoader()->setTemplate('__markup__', $markup);   // @phpstan-ignore-line ArrayLoader
            return $twig->render('__markup__', $context);
        } catch (TwigError $e) {
            $line = $e->getTemplateLine();
            $msg = mb_substr(preg_replace('/\s+/', ' ', $e->getRawMessage()) ?? '', 0, 200);
            throw new MarkupException('Twig-fout' . ($line > 0 ? " op regel {$line}" : '') . ': ' . $msg, 0, $e);
        } finally {
            if ($limit > 0) {
                @set_time_limit($previous);
            }
        }
    }

    /** @throws MarkupException */
    private function assertLimits(string $markup): void
    {
        if (strlen($markup) > $this->maxBytes()) {
            throw new MarkupException('De inhoud is te groot (maximaal ' . intdiv($this->maxBytes(), 1024) . ' KB).');
        }
        if (preg_match_all('/\{%-?\s*for\b/', $markup) > (int)$this->config['max_for_tags']) {
            throw new MarkupException('Te veel {% for %}-lussen (maximaal ' . $this->config['max_for_tags'] . ').');
        }
        // De range-operator (1..9999999) bouwt een enorme array: niet toestaan binnen {{ }} en {% %}
        if (preg_match_all('/\{\{(.*?)\}\}|\{%(.*?)%\}/s', $markup, $m)) {
            foreach (array_merge($m[1], $m[2]) as $inner) {
                $bare = preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', "''", (string)$inner) ?? '';
                if (str_contains($bare, '..')) {
                    throw new MarkupException('De range-operator (..) is niet toegestaan.');
                }
            }
        }
    }

    private function twig(): Environment
    {
        if ($this->twig !== null) {
            return $this->twig;
        }
        $policy = new SecurityPolicy(
            array_values($this->config['tags']),
            array_values($this->config['filters']),
            [],                                   // geen methoden
            [],                                   // geen eigenschappen
            array_values($this->config['functions'])
        );
        $twig = new Environment(new ArrayLoader([]), [
            'autoescape'       => 'html',
            'cache'            => false,
            'strict_variables' => false,
            'debug'            => false,
        ]);
        $twig->addExtension(new SandboxExtension($policy, true));   // true = altijd gesandboxt
        return $this->twig = $twig;
    }
}
