// Draai met: node --test tests/js/
import test from 'node:test';
import assert from 'node:assert/strict';
import { tokenize } from '../../public/assets/js/admin/tokenizer.js';

const join = (toks) => toks.map((t) => t[1]).join('');
const has = (toks, cls, text) => toks.some(([c, t]) => c === cls && t === text);

test('de tokens samen zijn altijd exact de invoer (ook bij rommel)', () => {
  const samples = [
    '', 'gewone tekst', '<p>hoi</p>', '<a href="x">l</a>', '<div class="a {{ b }}">', '{{ naam|upper }}', '{% if a %}b{% endif %}',
    '{# commentaar #}', '<?php echo $x; ?>', '<?= $x ?>', '<!DOCTYPE html>', '<!-- c -->', '<<<>>>{{{{', '{{ "open', '<a href="open',
    '<?php // kort ?> na', '<?php /* open', '{% if "}}" %}', "<p title='a\"b'>", '< 3 en > 5', '{', '{%', '<?', '<a', '<a b=',
  ];
  for (const s of samples) assert.equal(join(tokenize(s)), s, JSON.stringify(s));
});

test('willekeurige invoer verliest niets en crasht niet (fuzz)', () => {
  const alphabet = ['<', '>', '{', '}', '%', '#', '?', '"', "'", '=', ' ', '\n', 'a', 'p', '$', '|', '/', '-', '!', '&'];
  let seed = 1234567;
  const rnd = () => (seed = (seed * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff;
  for (let n = 0; n < 4000; n++) {
    let s = '';
    const len = Math.floor(rnd() * 60);
    for (let k = 0; k < len; k++) s += alphabet[Math.floor(rnd() * alphabet.length)];
    assert.equal(join(tokenize(s)), s, JSON.stringify(s));
  }
});

test('HTML: tags, attributen, strings, commentaar en doctype', () => {
  const t = tokenize('<!DOCTYPE html><!-- c --><a href="x" id=y>t</a>');
  assert.ok(has(t, 'tok-doctype', '<!DOCTYPE html>'));
  assert.ok(has(t, 'tok-comment', '<!-- c -->'));
  assert.ok(has(t, 'tok-attr', 'href'));
  assert.ok(has(t, 'tok-string', '"x"'));
  assert.ok(has(t, 'tok-string', 'y'));
  assert.ok(t.some(([c, s]) => c === 'tok-tag' && s.startsWith('<a')));
});

test('Twig: delimiters, tags, filters, variabelen en commentaar', () => {
  const t = tokenize('{{ naam|upper }}{% if x %}{# n #}');
  assert.ok(has(t, 'tok-twig-delim', '{{') || t.some(([c, s]) => c === 'tok-twig-delim' && s.includes('{{')));
  assert.ok(has(t, 'tok-twig-filter', '|upper'));
  assert.ok(has(t, 'tok-twig-tag', 'if'));
  assert.ok(has(t, 'tok-twig-comment', '{# n #}'));
  assert.ok(t.some(([c, s]) => c === 'tok-twig-var' && s === 'naam'));
});

test('Twig binnen een attribuutwaarde wordt apart gekleurd', () => {
  const t = tokenize('<div class="a {{ b }} c">');
  assert.ok(t.some(([c, s]) => c === 'tok-twig-var' && s === 'b'));
  assert.ok(t.some(([c, s]) => c === 'tok-string' && s.includes('"a ')));
});

test('PHP: delimiters, keywords, variabelen, strings, commentaar en functies', () => {
  const t = tokenize('<?php if ($a) { echo strlen("x"); } // klaar\n?>');
  assert.ok(has(t, 'tok-php-delim', '<?php'));
  assert.ok(has(t, 'tok-php-delim', '?>'));
  assert.ok(has(t, 'tok-keyword', 'if'));
  assert.ok(has(t, 'tok-keyword', 'echo'));
  assert.ok(has(t, 'tok-php-var', '$a'));
  assert.ok(has(t, 'tok-function', 'strlen'));
  assert.ok(has(t, 'tok-string', '"x"'));
  assert.ok(t.some(([c, s]) => c === 'tok-comment' && s.startsWith('// klaar')));
});

test('?> binnen een string of na een regelcommentaar sluit het PHP-blok correct', () => {
  const a = tokenize('<?php echo "?>"; ?>na');
  assert.equal(a.filter(([c]) => c === 'tok-php-delim').length, 2);
  const b = tokenize('<?php // x ?>na');
  assert.equal(b[b.length - 1][1], 'na');
});

test('gewone tekst krijgt geen klasse en < of { in tekst blijft tekst', () => {
  const t = tokenize('3 < 5 en { haakje');
  assert.equal(t.length, 1);
  assert.equal(t[0][0], '');
});

test('prestatie: 200 KB blijft snel', () => {
  const big = '<p class="a">{{ x|e }}</p>\n<?php echo $y; ?>\n'.repeat(4000);
  const t0 = performance.now();
  const toks = tokenize(big);
  assert.ok(performance.now() - t0 < 1500, 'te traag');
  assert.equal(join(toks), big);
});
