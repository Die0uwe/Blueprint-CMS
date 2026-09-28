<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Hook;

use CommunityFusion\Core\Hook\HookManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HookManagerTest extends TestCase
{
    private HookManager $hooks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hooks = new HookManager();
    }

    #[Test]
    public function doActionRunsAllRegisteredCallbacksInOrder(): void
    {
        $order = [];
        $this->hooks->addAction('user.login', function () use (&$order): void { $order[] = 'a'; }, 20);
        $this->hooks->addAction('user.login', function () use (&$order): void { $order[] = 'b'; }, 10);

        $this->hooks->doAction('user.login');

        self::assertSame(['b', 'a'], $order, 'Lagere priority moet eerst draaien (WordPress-conventie).');
    }

    #[Test]
    public function doActionPassesArgumentsThrough(): void
    {
        $received = null;
        $this->hooks->addAction('news.published', function (int $id, string $slug) use (&$received): void {
            $received = [$id, $slug];
        });

        $this->hooks->doAction('news.published', 42, 'sprint-9-launch');

        self::assertSame([42, 'sprint-9-launch'], $received);
    }

    #[Test]
    public function doActionOnUnknownHookIsANoop(): void
    {
        $this->expectNotToPerformAssertions();
        $this->hooks->doAction('hook.die.niet.bestaat');
    }

    #[Test]
    public function hasActionReflectsRegistrationState(): void
    {
        self::assertFalse($this->hooks->hasAction('user.login'));
        $this->hooks->addAction('user.login', fn () => null);
        self::assertTrue($this->hooks->hasAction('user.login'));
    }

    #[Test]
    public function removeActionStopsItFromRunning(): void
    {
        $calls = 0;
        $callback = function () use (&$calls): void { $calls++; };

        $this->hooks->addAction('cache.clear', $callback, 10);
        $this->hooks->removeAction('cache.clear', $callback, 10);
        $this->hooks->doAction('cache.clear');

        self::assertSame(0, $calls);
    }

    #[Test]
    public function applyFiltersTransformsTheValueThroughEachCallback(): void
    {
        $this->hooks->addFilter('news.title', fn (string $v) => strtoupper($v), 10);
        $this->hooks->addFilter('news.title', fn (string $v) => "[{$v}]", 20);

        $result = $this->hooks->applyFilters('news.title', 'sprint 9');

        self::assertSame('[SPRINT 9]', $result);
    }

    #[Test]
    public function applyFiltersReturnsTheOriginalValueWhenNoFilterIsRegistered(): void
    {
        self::assertSame('ongewijzigd', $this->hooks->applyFilters('geen.filter', 'ongewijzigd'));
    }

    #[Test]
    public function applyFiltersPassesExtraArgumentsToEachCallback(): void
    {
        $result = null;
        $this->hooks->addFilter('roster.rank_label', function (string $label, int $rankIndex) use (&$result) {
            $result = $rankIndex;
            return $label;
        });

        $this->hooks->applyFilters('roster.rank_label', 'Officer', 1);

        self::assertSame(1, $result);
    }

    #[Test]
    public function removeFilterStopsItFromTransformingTheValue(): void
    {
        $callback = fn (string $v) => strtoupper($v);
        $this->hooks->addFilter('news.title', $callback, 10);
        $this->hooks->removeFilter('news.title', $callback, 10);

        self::assertSame('blijft laag', $this->hooks->applyFilters('news.title', 'blijft laag'));
    }
}
