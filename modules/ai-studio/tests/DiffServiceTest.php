<?php

// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Tests;

require_once __DIR__ . '/Support/bootstrap.php';

use CommunityFusion\Modules\AiStudio\DiffException;
use CommunityFusion\Modules\AiStudio\DiffService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DiffServiceTest extends TestCase
{
    private DiffService $diff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->diff = new DiffService();
    }

    /**
     * generate() gevolgd door apply() moet altijd exact de nieuwe tekst opleveren.
     */
    private function assertRoundTrip(string $old, string $new, string $message = ''): string
    {
        $patch = $this->diff->generate($old, $new);
        self::assertSame($new, $this->diff->apply($old, $patch), $message . ' | diff: ' . $patch);
        return $patch;
    }

    #[Test]
    public function testIdenticalTextGivesEmptyDiff(): void
    {
        self::assertSame('', $this->diff->generate("a\nb\n", "a\nb\n"));
        self::assertSame('', $this->diff->generate('', ''));
    }

    #[Test]
    public function testGeneratesValidUnifiedDiffFormat(): void
    {
        $patch = $this->diff->generate("one\ntwo\nthree\n", "one\n2\nthree\n");

        self::assertSame(
            "--- a/editor\n+++ b/editor\n@@ -1,3 +1,3 @@\n one\n-two\n+2\n three\n",
            $patch
        );
    }

    #[Test]
    public function testCustomFileNamesInHeader(): void
    {
        $patch = $this->diff->generate("a\n", "b\n", 'a/x.php', 'b/x.php');
        self::assertStringContainsString("--- a/x.php\n+++ b/x.php\n", $patch);
    }

    #[Test]
    public function testRoundTripVariety(): void
    {
        $cases = [
            'replace line' => ["a\nb\nc\n", "a\nX\nc\n"],
            'insert middle' => ["a\nc\n", "a\nb\nc\n"],
            'delete middle' => ["a\nb\nc\n", "a\nc\n"],
            'append' => ["a\n", "a\nb\nc\n"],
            'prepend' => ["b\n", "a\nb\n"],
            'from empty' => ['', "x\ny\n"],
            'to empty' => ["x\ny\n", ''],
            'no trailing newline both' => ["a\nb", "a\nc"],
            'add trailing newline' => ["a\nb", "a\nb\n"],
            'remove trailing newline' => ["a\nb\n", "a\nb"],
            'append to no-eol file' => ["a\nb", "a\nb\nc"],
            'unicode' => ["héllo\nwörld ✓\n", "héllo\nwereld ✓\n"],
            'blank lines' => ["a\n\n\nb\n", "a\n\nb\n\n"],
            'single line' => ["x\n", "y\n"],
            'only newline' => ["\n", "\n\n"],
            'crlf' => ["a\r\nb\r\n", "a\r\nB\r\n"],
        ];
        foreach ($cases as $name => [$old, $new]) {
            $this->assertRoundTrip($old, $new, $name);
        }
    }

    #[Test]
    public function testRoundTripManyHunksFarApart(): void
    {
        $old = [];
        for ($i = 1; $i <= 80; $i++) {
            $old[] = "line $i";
        }
        $new = $old;
        $new[2] = 'changed 3';
        $new[40] = 'changed 41';
        array_splice($new, 70, 0, ['inserted']);
        unset($new[78]);
        $new = array_values($new);

        $patch = $this->assertRoundTrip(implode("\n", $old) . "\n", implode("\n", $new) . "\n");
        self::assertGreaterThanOrEqual(3, substr_count($patch, '@@ -'), 'Verre wijzigingen horen in aparte hunks.');
    }

    #[Test]
    public function testNearbyChangesMergeIntoOneHunk(): void
    {
        $old = "1\n2\n3\n4\n5\n6\n7\n8\n9\n";
        $new = "1\nX\n3\n4\n5\n6\n7\nY\n9\n";
        $patch = $this->assertRoundTrip($old, $new);
        self::assertSame(1, substr_count($patch, '@@ -'));
    }

    #[Test]
    public function testRandomizedRoundTrips(): void
    {
        mt_srand(12345);
        $alphabet = ['a', 'b', 'c', 'd', '', 'e', 'f'];
        for ($n = 0; $n < 150; $n++) {
            $old = [];
            $new = [];
            $len = mt_rand(0, 25);
            for ($i = 0; $i < $len; $i++) {
                $old[] = $alphabet[mt_rand(0, 6)];
            }
            $new = $old;
            for ($k = mt_rand(0, 6); $k > 0; $k--) {
                $op = mt_rand(0, 2);
                $pos = $new === [] ? 0 : mt_rand(0, count($new) - 1);
                if ($op === 0) {
                    array_splice($new, $pos, 0, [$alphabet[mt_rand(0, 6)]]);
                } elseif ($op === 1 && $new !== []) {
                    array_splice($new, $pos, 1);
                } elseif ($new !== []) {
                    $new[$pos] = 'Z' . mt_rand(0, 9);
                }
            }
            $eolOld = mt_rand(0, 3) > 0 ? "\n" : '';
            $eolNew = mt_rand(0, 3) > 0 ? "\n" : '';
            $a = $old === [] ? '' : implode("\n", $old) . $eolOld;
            $b = $new === [] ? '' : implode("\n", $new) . $eolNew;
            $this->assertRoundTrip($a, $b, 'seed-case ' . $n);
        }
    }

    #[Test]
    public function testStatsCountAddedAndRemoved(): void
    {
        $patch = $this->diff->generate("a\nb\nc\n", "a\nX\nY\nc\n");
        self::assertSame(['added' => 2, 'removed' => 1, 'hunks' => 1], $this->diff->stats($patch));
    }

    #[Test]
    public function testApplyRefusesWhenContextLineDiffers(): void
    {
        $patch = $this->diff->generate("a\nb\nc\n", "a\nX\nc\n");

        $this->expectException(DiffException::class);
        $this->diff->apply("a\nb\nCHANGED\n", $patch);
    }

    #[Test]
    public function testApplyRefusesWhenRemovedLineDiffers(): void
    {
        $patch = $this->diff->generate("a\nb\nc\n", "a\nX\nc\n");

        $this->expectException(DiffException::class);
        $this->diff->apply("a\nsomething else\nc\n", $patch);
    }

    #[Test]
    public function testApplyRefusesShiftedContentWithoutFuzz(): void
    {
        $patch = $this->diff->generate("a\nb\nc\n", "a\nX\nc\n");

        // Zelfde regels, maar één regel naar beneden geschoven: strikt = weigeren.
        $this->expectException(DiffException::class);
        $this->diff->apply("zzz\na\nb\nc\n", $patch);
    }

    #[Test]
    public function testApplyRefusesWhenFileIsTooShort(): void
    {
        $patch = $this->diff->generate("a\nb\nc\nd\ne\n", "a\nb\nc\nd\nX\n");

        $this->expectException(DiffException::class);
        $this->diff->apply("a\nb\n", $patch);
    }

    #[Test]
    public function testApplyRefusesEolMismatch(): void
    {
        $patch = $this->diff->generate("a\nb\n", "a\nc\n");

        // Basis eindigt zonder newline terwijl de diff een slotnewline verwacht.
        $this->expectException(DiffException::class);
        $this->diff->apply("a\nb", $patch);
    }

    #[Test]
    public function testParseRejectsGarbage(): void
    {
        $bad = [
            "not a diff\n",
            "--- a\n+++ b\n@@ -1,1 +1,1 @@\n?weird\n",
            "--- a\n+++ b\n@@ -1,2 +1,1 @@\n-a\n",           // header klopt niet met inhoud
            "--- a\n+++ b\n@@ -1,1 +1,1 @@\n a\n-b\n",       // te veel regels
            "--- a\n+++ b\n\\ No newline at end of file\n",   // marker zonder regel
            "--- a\n+++ b\n",                                  // geen hunk
        ];
        foreach ($bad as $patch) {
            try {
                $this->diff->parse($patch);
                self::fail('Verwachtte DiffException voor: ' . $patch);
            } catch (DiffException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testApplyRefusesOverlappingOrReorderedHunks(): void
    {
        $patch = "--- a\n+++ b\n@@ -3,1 +3,1 @@\n-c\n+C\n@@ -1,1 +1,1 @@\n-a\n+A\n";

        $this->expectException(DiffException::class);
        $this->diff->apply("a\nb\nc\n", $patch);
    }

    #[Test]
    public function testApplyRefusesHunkBeyondEndOfFile(): void
    {
        $patch = "--- a\n+++ b\n@@ -50,1 +50,1 @@\n-x\n+y\n";

        $this->expectException(DiffException::class);
        $this->diff->apply("a\nb\n", $patch);
    }

    #[Test]
    public function testEmptyDiffLeavesBaseUntouched(): void
    {
        self::assertSame("a\n", $this->diff->apply("a\n", ''));
    }

    #[Test]
    public function testRejectsOversizedInput(): void
    {
        $big = str_repeat("x\n", (int) (DiffService::MAX_BYTES / 2) + 10);

        $this->expectException(DiffException::class);
        $this->diff->generate($big, 'a');
    }

    #[Test]
    public function testHugeEditDistanceFallsBackToReplaceAllButStaysValid(): void
    {
        $old = [];
        $new = [];
        for ($i = 0; $i < 2000; $i++) {
            $old[] = "old $i";
            $new[] = "new $i";
        }
        $a = implode("\n", $old) . "\n";
        $b = implode("\n", $new) . "\n";

        self::assertSame($b, $this->diff->apply($a, $this->diff->generate($a, $b)));
    }

    #[Test]
    public function testAlignProposalMatchesLineEndingsAndTrailingNewline(): void
    {
        self::assertSame("a\r\nb\r\n", $this->diff->alignProposal("x\r\ny\r\n", "a\nb\n"));
        self::assertSame("a\nb\n", $this->diff->alignProposal("x\ny\n", "a\r\nb\r\n"));
        self::assertSame('a', $this->diff->alignProposal('x', "a\n"), 'Basis zonder slotnewline -> voorstel ook niet.');
        self::assertSame("a\n", $this->diff->alignProposal('', "a\n"));
    }

    #[Test]
    public function testDiffTextNeverExecutesAnything(): void
    {
        // De diff is data: PHP-code of HTML in regels blijft letterlijke tekst.
        $old = "<?php echo 1;\n";
        $new = "<?php system('id'); ?><script>alert(1)</script>\n";
        $patch = $this->assertRoundTrip($old, $new);
        self::assertStringContainsString('+<?php system', $patch);
    }
}
