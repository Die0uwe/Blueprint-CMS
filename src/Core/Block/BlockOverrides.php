<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Block;

use CommunityFusion\Core\Marketplace\SafeFs;
use CommunityFusion\Core\Template\MarkupException;
use CommunityFusion\Core\Template\MarkupRenderer;

/**
 * Sjabloon-overrides per blocktype: storage/block-overrides/{type}.twig.
 * Een override vervangt de HTML van alle blokken van dat type. Gerenderd in dezelfde Twig-sandbox
 * als gebruikersmarkup; de context bevat alleen `title` en `config` (alleen scalaire waarden).
 */
final class BlockOverrides
{
    public const MAX_BYTES = 65536;

    public function __construct(private readonly string $dir, private readonly MarkupRenderer $renderer) {}

    public static function validSlug(string $slug): bool
    {
        return (bool)preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug);
    }

    private function path(string $slug): string
    {
        if (!self::validSlug($slug)) {
            throw new \InvalidArgumentException('Ongeldige blocktype-naam.');
        }
        return rtrim($this->dir, '/\\') . '/' . $slug . '.twig';
    }

    public function has(string $slug): bool
    {
        return self::validSlug($slug) && is_file($this->path($slug));
    }

    public function get(string $slug): ?string
    {
        if (!$this->has($slug)) {
            return null;
        }
        $c = file_get_contents($this->path($slug));
        return $c === false ? null : $c;
    }

    /** @throws MarkupException als de markup niet door de sandbox komt */
    public function save(string $slug, string $markup): void
    {
        $path = $this->path($slug);
        if (strlen($markup) > self::MAX_BYTES) {
            throw new MarkupException('Het sjabloon is te groot (maximaal 64 KB).');
        }
        // Proefrender: fouten en verboden constructies moeten hier al naar boven komen
        $this->renderer->render($markup, ['title' => 'Proef', 'config' => []]);
        if (!is_dir($this->dir) && !mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Map voor overrides kon niet worden aangemaakt.');
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $markup, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Het sjabloon kon niet worden opgeslagen.');
        }
    }

    public function delete(string $slug): void
    {
        if ($this->has($slug)) {
            @unlink($this->path($slug));
        }
    }

    /** @param array<string,mixed> $config */
    public function render(string $slug, array $config, string $title): ?string
    {
        $markup = $this->get($slug);
        if ($markup === null) {
            return null;
        }
        $safe = [];
        foreach ($config as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $safe[(string)$k] = $v;
            }
        }
        return $this->renderer->render($markup, ['title' => $title, 'config' => $safe, 'today' => date('Y-m-d')]);
    }
}
