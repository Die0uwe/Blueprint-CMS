<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Ai;

use CommunityFusion\Core\Ai\ThinkFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThinkFilterTest extends TestCase
{
    #[Test]
    public function stripsCompleteThinkBlock(): void
    {
        $this->assertSame('Hallo!', ThinkFilter::strip("<think>\nIk denk na…\n</think>\n\nHallo!"));
        $this->assertSame('Geen denkwerk', ThinkFilter::strip('Geen denkwerk'));
        $this->assertSame('B', ThinkFilter::strip('<THINK>x</THINK>B'));
        $this->assertSame('B', ThinkFilter::strip("  <think>x</think>\n<think>y</think> B"));
    }

    #[Test]
    public function handlesMissingOpenTagAndUnclosedBlock(): void
    {
        $this->assertSame('Antwoord', ThinkFilter::strip("redeneren zonder open tag</think>Antwoord"));
        $this->assertSame('', ThinkFilter::strip('<think>nooit afgesloten'));
        $this->assertSame('1 < 2', ThinkFilter::strip('1 < 2'));
        $this->assertSame('Gebruik <think> om na te denken.', ThinkFilter::strip('Gebruik <think> om na te denken.'), 'midden in een antwoord blijft staan');
    }

    #[Test]
    public function streamingSurvivesTagsSplitOverChunks(): void
    {
        $text = "<think>diep nadenken</think>\n\nHet antwoord is 42 <b>vet</b>.";
        for ($size = 1; $size <= 9; $size++) {
            $f = new ThinkFilter();
            $out = '';
            foreach (str_split($text, $size) as $c) {
                $out .= $f->feed($c);
            }
            $out .= $f->flush();
            $this->assertSame('Het antwoord is 42 <b>vet</b>.', $out, "chunkgrootte $size");
        }
    }

    #[Test]
    public function halfTagAtEndIsKeptAsText(): void
    {
        $f = new ThinkFilter();
        $out = $f->feed('a <thi') . $f->flush();
        $this->assertSame('a <thi', $out);
    }
}
