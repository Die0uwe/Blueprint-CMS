<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio;

/**
 * Unified diff genereren, parsen en STRIKT toepassen. Geen externe library.
 *
 * Strikt betekent: bij toepassen moet elke context- en verwijderregel exact
 * (byte voor byte) op de verwachte plek in de basistekst staan. Geen fuzz, geen
 * offset-gokken, geen stille correcties: bij de minste afwijking volgt een
 * DiffException en blijft de editor ongemoeid. Dat is de server-side poort
 * tussen "AI-voorstel" en "editorinhoud".
 *
 * Genereren gebruikt Myers' O(ND)-algoritme op regelniveau, met prefix/suffix-
 * trimming en een bovengrens op de editafstand; daarboven valt het terug op
 * "middenstuk volledig vervangen", wat nog steeds een geldige diff is.
 */
final class DiffService
{
    public const MAX_BYTES = 524288;
    public const MAX_LINES = 20000;
    private const CONTEXT = 3;
    private const MAX_EDIT_DISTANCE = 1500;
    private const NO_EOL = "\x00NOEOL";

    /**
     * Unified diff van $old naar $new. Lege string als er niets verschilt.
     */
    public function generate(string $old, string $new, string $oldName = 'a/editor', string $newName = 'b/editor'): string
    {
        $this->guardSize($old);
        $this->guardSize($new);

        if ($old === $new) {
            return '';
        }

        $a = $this->tokens($old);
        $b = $this->tokens($new);
        $ops = $this->diffLines($a, $b);

        $total = count($ops);
        $oldPos = [];
        $newPos = [];
        $o = 0;
        $n = 0;
        foreach ($ops as $i => [$op]) {
            $oldPos[$i] = $o;
            $newPos[$i] = $n;
            if ($op !== '+') {
                $o++;
            }
            if ($op !== '-') {
                $n++;
            }
        }

        $changes = [];
        foreach ($ops as $i => [$op]) {
            if ($op !== '=') {
                $changes[] = $i;
            }
        }

        $out = ['--- ' . $oldName, '+++ ' . $newName];
        $ci = 0;
        $changeCount = count($changes);
        while ($ci < $changeCount) {
            $first = $changes[$ci];
            $last = $first;
            while (isset($changes[$ci + 1]) && $changes[$ci + 1] - $last - 1 <= 2 * self::CONTEXT) {
                $ci++;
                $last = $changes[$ci];
            }
            $ci++;

            $from = max(0, $first - self::CONTEXT);
            $to = min($total - 1, $last + self::CONTEXT);

            $oldCount = 0;
            $newCount = 0;
            $body = [];
            for ($i = $from; $i <= $to; $i++) {
                [$op, $token] = $ops[$i];
                $noEol = str_ends_with($token, self::NO_EOL);
                $text = $noEol ? substr($token, 0, -strlen(self::NO_EOL)) : $token;
                $prefix = $op === '=' ? ' ' : $op;
                $body[] = $prefix . $text;
                if ($noEol) {
                    $body[] = '\\ No newline at end of file';
                }
                if ($op !== '+') {
                    $oldCount++;
                }
                if ($op !== '-') {
                    $newCount++;
                }
            }

            $oldStart = $oldCount === 0 ? $oldPos[$from] : $oldPos[$from] + 1;
            $newStart = $newCount === 0 ? $newPos[$from] : $newPos[$from] + 1;
            $out[] = sprintf('@@ -%d,%d +%d,%d @@', $oldStart, $oldCount, $newStart, $newCount);
            array_push($out, ...$body);
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * @return array{added: int, removed: int, hunks: int}
     * @throws DiffException
     */
    public function stats(string $diff): array
    {
        $added = 0;
        $removed = 0;
        $hunks = $this->parse($diff);
        foreach ($hunks as $hunk) {
            foreach ($hunk['lines'] as $line) {
                if ($line['op'] === '+') {
                    $added++;
                } elseif ($line['op'] === '-') {
                    $removed++;
                }
            }
        }
        return ['added' => $added, 'removed' => $removed, 'hunks' => count($hunks)];
    }

    /**
     * @return list<array{oldStart: int, oldCount: int, newStart: int, newCount: int, lines: list<array{op: string, text: string, noEol: bool}>}>
     * @throws DiffException
     */
    public function parse(string $diff): array
    {
        if (strlen($diff) > self::MAX_BYTES * 2) {
            throw new DiffException('Diff is te groot.');
        }
        if ($diff === '') {
            return [];
        }

        $lines = explode("\n", $diff);
        if (end($lines) === '') {
            array_pop($lines);
        }

        $hunks = [];
        /** @var array{int, int, int, int}|null $header */
        $header = null;
        /** @var list<array{op: string, text: string, noEol: bool}> $body */
        $body = [];

        foreach ($lines as $line) {
            if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $m) === 1) {
                if ($header !== null) {
                    $hunks[] = $this->makeHunk($header, $body);
                }
                $header = [
                    (int) $m[1],
                    $m[2] === '' ? 1 : (int) $m[2],
                    (int) $m[3],
                    ($m[4] ?? '') === '' ? 1 : (int) $m[4],
                ];
                $body = [];
                continue;
            }

            if ($header === null) {
                if (str_starts_with($line, '--- ') || str_starts_with($line, '+++ ') || str_starts_with($line, 'diff ') || str_starts_with($line, 'index ')) {
                    continue;
                }
                throw new DiffException('Onverwachte regel voor de eerste hunk.');
            }

            $marker = $line === '' ? '' : $line[0];
            $text = $line === '' ? '' : substr($line, 1);

            if ($marker === '\\') {
                $last = count($body) - 1;
                if ($last < 0) {
                    throw new DiffException('Ongeldige "geen newline"-markering.');
                }
                $body[$last] = ['op' => $body[$last]['op'], 'text' => $body[$last]['text'], 'noEol' => true];
                continue;
            }
            if ($marker === ' ') {
                $body[] = ['op' => '=', 'text' => $text, 'noEol' => false];
            } elseif ($marker === '-' || $marker === '+') {
                $body[] = ['op' => $marker, 'text' => $text, 'noEol' => false];
            } else {
                throw new DiffException('Ongeldige diff-regel.');
            }
        }

        if ($header !== null) {
            $hunks[] = $this->makeHunk($header, $body);
        }

        if ($hunks === []) {
            throw new DiffException('Geen hunks gevonden in de diff.');
        }
        return $hunks;
    }

    /**
     * @param array{int, int, int, int} $header oldStart, oldCount, newStart, newCount
     * @param list<array{op: string, text: string, noEol: bool}> $lines
     * @return array{oldStart: int, oldCount: int, newStart: int, newCount: int, lines: list<array{op: string, text: string, noEol: bool}>}
     */
    private function makeHunk(array $header, array $lines): array
    {
        $old = 0;
        $new = 0;
        foreach ($lines as $l) {
            if ($l['op'] !== '+') {
                $old++;
            }
            if ($l['op'] !== '-') {
                $new++;
            }
        }
        if ($old !== $header[1] || $new !== $header[3]) {
            throw new DiffException('Hunk-header komt niet overeen met de inhoud.');
        }
        return ['oldStart' => $header[0], 'oldCount' => $header[1], 'newStart' => $header[2], 'newCount' => $header[3], 'lines' => $lines];
    }

    /**
     * Pas een diff strikt toe. Gooit DiffException bij elke afwijking.
     *
     * @throws DiffException
     */
    public function apply(string $base, string $diff): string
    {
        $this->guardSize($base);
        $hunks = $this->parse($diff);
        if ($hunks === []) {
            return $base;
        }

        [$lines, $baseEol] = $this->split($base);
        $n = count($lines);
        $out = [];
        $cursor = 0;
        $newNoEol = false;

        foreach ($hunks as $hunk) {
            $pos = $hunk['oldCount'] === 0 ? $hunk['oldStart'] : $hunk['oldStart'] - 1;
            if ($pos < $cursor || $pos > $n) {
                throw new DiffException('Hunks overlappen of vallen buiten het bestand.');
            }
            for (; $cursor < $pos; $cursor++) {
                $out[] = $lines[$cursor];
            }

            foreach ($hunk['lines'] as $l) {
                if ($newNoEol) {
                    throw new DiffException('Regels na het einde van het bestand.');
                }
                if ($l['op'] === '+') {
                    $out[] = $l['text'];
                    $newNoEol = $l['noEol'];
                    continue;
                }

                if ($pos >= $n || $lines[$pos] !== $l['text']) {
                    throw new DiffException(sprintf('De editor wijkt af van het voorstel (regel %d).', $pos + 1));
                }
                $isLast = $pos === $n - 1;
                if ($l['noEol'] !== ($isLast && !$baseEol)) {
                    throw new DiffException('Einde-van-bestand komt niet overeen.');
                }
                $pos++;
                $cursor = $pos;
                if ($l['op'] === '=') {
                    $out[] = $l['text'];
                    $newNoEol = $l['noEol'];
                }
            }
        }

        $eol = true;
        if ($cursor < $n) {
            if ($newNoEol) {
                throw new DiffException('Regels na het einde van het bestand.');
            }
            for (; $cursor < $n; $cursor++) {
                $out[] = $lines[$cursor];
            }
            $eol = $baseEol;
        } elseif ($newNoEol) {
            $eol = false;
        }

        if (count($out) > self::MAX_LINES) {
            throw new DiffException('Resultaat is te groot.');
        }
        $result = implode("\n", $out) . ($eol && $out !== [] ? "\n" : '');
        $this->guardSize($result);
        return $result;
    }

    /**
     * Pas een voorstel aan op de stijl van de basistekst: dezelfde regeleinden
     * (CRLF-bestand + LF-voorstel zou anders elke regel als gewijzigd tonen) en
     * dezelfde slotnewline (een model schrijft een bestand zelden zonder).
     */
    public function alignProposal(string $base, string $proposal): string
    {
        $baseCrlf = str_contains($base, "\r\n");
        if ($baseCrlf && !str_contains($proposal, "\r")) {
            $proposal = str_replace("\n", "\r\n", $proposal);
        } elseif (!$baseCrlf && !str_contains($base, "\r") && str_contains($proposal, "\r\n")) {
            $proposal = str_replace("\r\n", "\n", $proposal);
        }

        if ($base !== '' && !str_ends_with($base, "\n")) {
            $proposal = rtrim($proposal, "\r\n");
        }
        return $proposal;
    }

    // ─── intern ──────────────────────────────────────────────────────────

    private function guardSize(string $text): void
    {
        if (strlen($text) > self::MAX_BYTES) {
            throw new DiffException('Tekst is te groot voor een diff.');
        }
        if (substr_count($text, "\n") > self::MAX_LINES) {
            throw new DiffException('Te veel regels voor een diff.');
        }
    }

    /**
     * @return array{0: list<string>, 1: bool} regels (zonder \n) en of de tekst op \n eindigt
     */
    private function split(string $text): array
    {
        if ($text === '') {
            return [[], true];
        }
        $lines = explode("\n", $text);
        $eol = false;
        if (end($lines) === '') {
            array_pop($lines);
            $eol = true;
        }
        return [array_values($lines), $eol];
    }

    /**
     * Regels als vergelijkbare tokens; de laatste regel zonder newline krijgt
     * een sentinel zodat "met" en "zonder" newline als verschillend gelden.
     *
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        [$lines, $eol] = $this->split($text);
        if (!$eol && $lines !== []) {
            $last = count($lines) - 1;
            $lines[$last] .= self::NO_EOL;
        }
        return $lines;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0: string, 1: string}> bewerkingen ('=', '-', '+') met token
     */
    private function diffLines(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        $prefix = 0;
        while ($prefix < $n && $prefix < $m && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < $n - $prefix && $suffix < $m - $prefix && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]) {
            $suffix++;
        }

        $midA = array_slice($a, $prefix, $n - $prefix - $suffix);
        $midB = array_slice($b, $prefix, $m - $prefix - $suffix);

        $ops = [];
        for ($i = 0; $i < $prefix; $i++) {
            $ops[] = ['=', $a[$i]];
        }
        foreach ($this->myers($midA, $midB) as $op) {
            $ops[] = $op;
        }
        for ($i = $n - $suffix; $i < $n; $i++) {
            $ops[] = ['=', $a[$i]];
        }
        return $ops;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0: string, 1: string}>
     */
    private function myers(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 || $m === 0) {
            return $this->replaceAll($a, $b);
        }

        $max = min($n + $m, self::MAX_EDIT_DISTANCE);
        /** @var array<int, int> $v */
        $v = [1 => 0];
        /** @var list<array<int, int>> $trace */
        $trace = [];

        for ($d = 0; $d <= $max; $d++) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                if ($k === -$d || ($k !== $d && $v[$k - 1] < $v[$k + 1])) {
                    $x = $v[$k + 1];
                } else {
                    $x = $v[$k - 1] + 1;
                }
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    return $this->backtrack($trace, $a, $b, $d);
                }
            }
        }

        return $this->replaceAll($a, $b); // te veel verschil: vervang het middenstuk volledig
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0: string, 1: string}>
     */
    private function replaceAll(array $a, array $b): array
    {
        $ops = [];
        foreach ($a as $line) {
            $ops[] = ['-', $line];
        }
        foreach ($b as $line) {
            $ops[] = ['+', $line];
        }
        return $ops;
    }

    /**
     * @param list<array<int, int>> $trace
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0: string, 1: string}>
     */
    private function backtrack(array $trace, array $a, array $b, int $depth): array
    {
        $x = count($a);
        $y = count($b);
        $ops = [];

        for ($d = $depth; $d > 0; $d--) {
            $v = $trace[$d];
            $k = $x - $y;
            if ($k === -$d || ($k !== $d && $v[$k - 1] < $v[$k + 1])) {
                $prevK = $k + 1;
            } else {
                $prevK = $k - 1;
            }
            $prevX = $v[$prevK];
            $prevY = $prevX - $prevK;

            while ($x > $prevX && $y > $prevY) {
                $ops[] = ['=', $a[$x - 1]];
                $x--;
                $y--;
            }
            if ($x === $prevX) {
                $ops[] = ['+', $b[$y - 1]];
            } else {
                $ops[] = ['-', $a[$x - 1]];
            }
            $x = $prevX;
            $y = $prevY;
        }
        while ($x > 0 && $y > 0) {
            $ops[] = ['=', $a[$x - 1]];
            $x--;
            $y--;
        }

        return array_reverse($ops);
    }
}
