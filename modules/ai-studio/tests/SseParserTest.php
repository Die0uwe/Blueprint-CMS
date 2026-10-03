<?php

// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Tests;

require_once __DIR__ . '/Support/bootstrap.php';

use CommunityFusion\Modules\AiStudio\Http\SseParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SseParserTest extends TestCase
{
    #[Test]
    public function testParsesEventsAcrossArbitraryChunkBoundaries(): void
    {
        $stream = "event: a\ndata: {\"x\":1}\n\ndata: second\n\n: ping\n\nevent: b\ndata: l1\ndata: l2\n\n";
        for ($size = 1; $size <= 9; $size++) {
            $p = new SseParser();
            $events = [];
            foreach (str_split($stream, $size) as $chunk) {
                array_push($events, ...$p->feed($chunk));
            }
            array_push($events, ...$p->finish());
            self::assertSame([
                ['event' => 'a', 'data' => '{"x":1}'],
                ['event' => 'message', 'data' => 'second'],
                ['event' => 'b', 'data' => "l1\nl2"],
            ], $events, "chunkgrootte $size");
        }
    }

    #[Test]
    public function testHandlesCrLfAndTrailingEventWithoutBlankLine(): void
    {
        $p = new SseParser();
        $events = $p->feed("data: one\r\n\r\ndata: two");
        self::assertSame([['event' => 'message', 'data' => 'one']], $events);
        self::assertSame([['event' => 'message', 'data' => 'two']], $p->finish());
    }

    #[Test]
    public function testIgnoresCommentsAndUnknownFields(): void
    {
        $p = new SseParser();
        self::assertSame([], $p->feed(": keepalive\n\nid: 5\nretry: 10\n\n"));
    }
}
