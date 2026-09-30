// tokenizer.js — minimale, zelfgebouwde tokenizer voor HTML + PHP + Twig (geen externe library).
// tokenize(tekst) → [[klasse, tekst], ...]; klasse '' = gewone tekst. De teksten samen zijn altijd
// exact de invoer. Wordt alleen gebruikt om <span class="tok-…"> te bouwen via textContent.

const PHP_KEYWORDS = new Set(('abstract and array as break callable case catch class clone const continue declare default do echo else elseif ' +
  'empty enddeclare endfor endforeach endif endswitch endwhile extends final finally fn for foreach function global goto if implements ' +
  'include include_once instanceof insteadof interface isset list match namespace new or print private protected public readonly require ' +
  'require_once return static switch throw trait try unset use var while xor yield true false null').split(' '));
const TWIG_KEYWORDS = new Set(('and or not in is if else elseif endif for endfor set endset as with only true false null none matches starts ends ' +
  'same divisible by defined empty even odd iterable block endblock extends include import from macro endmacro apply endapply autoescape ' +
  'endautoescape sandbox endsandbox verbatim endverbatim').split(' '));

const rx = (source) => new RegExp(source, 'y');
const R = {
  ws: rx('\\s+'),
  str: rx('\'(?:[^\'\\\\]|\\\\[\\s\\S])*\'?|"(?:[^"\\\\]|\\\\[\\s\\S])*"?'),
  num: rx('\\d+(?:\\.\\d+)?'),
  ident: rx('[A-Za-z_][\\w-]*'),
  filter: rx('\\|\\s*[A-Za-z_]\\w*'),
  phpVar: rx('\\$[A-Za-z_]\\w*'),
  phpIdent: rx('[A-Za-z_\\\\][\\w\\\\]*'),
  lineComment: rx('(?:\\/\\/|#)[^\\n]*?(?=\\?>|\\n|$)'),
  blockComment: rx('\\/\\*[\\s\\S]*?(?:\\*\\/|$)'),
  tagName: rx('<\\/?[A-Za-z][\\w:-]*'),
  attrName: rx('[^\\s=\\/>"\'{<]+'),
  bare: rx('[^\\s>"\'{<]+'),
};

function at(re, s, i) { re.lastIndex = i; const m = re.exec(s); return m ? m[0] : null; }

/** Zoek de sluiting van een Twig-blok; quotes worden overgeslagen. Geeft index van de sluiting of -1. */
function findClose(s, from, close) {
  let q = null;
  for (let i = from; i < s.length; i++) {
    const c = s[i];
    if (q) { if (c === '\\') i++; else if (c === q) q = null; continue; }
    if (c === '"' || c === "'") { q = c; continue; }
    if (s.startsWith(close, i)) return i;
  }
  return -1;
}

function simpleBlock(s, i, close, cls, out) {
  const end = s.indexOf(close, i + 2);
  const stop = end < 0 ? s.length : end + close.length;
  out.push([cls, s.slice(i, stop)]);
  return stop;
}

function twigInner(inner, isTag, out) {
  let i = 0, first = isTag, m;
  while (i < inner.length) {
    if ((m = at(R.ws, inner, i))) { out.push(['', m]); i += m.length; continue; }
    if ((m = at(R.str, inner, i))) { out.push(['tok-string', m]); i += m.length; continue; }
    if ((m = at(R.num, inner, i))) { out.push(['tok-number', m]); i += m.length; first = false; continue; }
    if ((m = at(R.filter, inner, i))) { out.push(['tok-twig-filter', m]); i += m.length; first = false; continue; }
    if ((m = at(R.ident, inner, i))) {
      const word = m.toLowerCase();
      out.push([first ? 'tok-twig-tag' : (TWIG_KEYWORDS.has(word) ? 'tok-keyword' : 'tok-twig-var'), m]);
      i += m.length; first = false; continue;
    }
    out.push(['', inner[i]]); i++; first = false;
  }
}

function twig(s, i, close, isTag, out) {
  out.push(['tok-twig-delim', s.slice(i, i + 2)]);
  const end = findClose(s, i + 2, close);
  const stop = end < 0 ? s.length : end;
  twigInner(s.slice(i + 2, stop), isTag, out);
  if (end < 0) return s.length;
  out.push(['tok-twig-delim', close]);
  return end + close.length;
}

function php(s, i, out) {
  const open = /^<\?(?:php|=)?/i.exec(s.slice(i, i + 5))[0];
  out.push(['tok-php-delim', open]);
  i += open.length;
  let m;
  while (i < s.length) {
    if (s.startsWith('?>', i)) { out.push(['tok-php-delim', '?>']); return i + 2; }
    if ((m = at(R.ws, s, i))) { out.push(['', m]); i += m.length; continue; }
    if ((m = at(R.lineComment, s, i)) || (m = at(R.blockComment, s, i))) { out.push(['tok-comment', m]); i += m.length; continue; }
    if ((m = at(R.str, s, i))) { out.push(['tok-string', m]); i += m.length; continue; }
    if ((m = at(R.phpVar, s, i))) { out.push(['tok-php-var', m]); i += m.length; continue; }
    if ((m = at(R.num, s, i))) { out.push(['tok-number', m]); i += m.length; continue; }
    if ((m = at(R.phpIdent, s, i))) {
      const isFn = /^\s*\(/.test(s.slice(i + m.length, i + m.length + 40));
      out.push([PHP_KEYWORDS.has(m.toLowerCase()) ? 'tok-keyword' : (isFn ? 'tok-function' : ''), m]);
      i += m.length; continue;
    }
    out.push(['', s[i]]); i++;
  }
  return s.length;
}

/** Sjabloonfragmenten ({{ }}, {% %}, {# #}, <?php ?>) binnen een tag of attribuutwaarde. Geeft nieuwe index of -1. */
function embedded(s, i, out) {
  if (s.startsWith('{#', i)) return simpleBlock(s, i, '#}', 'tok-twig-comment', out);
  if (s.startsWith('{{', i)) return twig(s, i, '}}', false, out);
  if (s.startsWith('{%', i)) return twig(s, i, '%}', true, out);
  if (s.startsWith('<?', i)) return php(s, i, out);
  return -1;
}

function quoted(s, i, out) {
  const q = s[i];
  let start = i, j = i + 1;
  while (j < s.length) {
    if (s[j] === q) { out.push(['tok-string', s.slice(start, j + 1)]); return j + 1; }
    const save = out.length;
    if (s[j] === '{' || s[j] === '<') {
      if (j > start) out.push(['tok-string', s.slice(start, j)]);
      const k = embedded(s, j, out);
      if (k >= 0) { j = k; start = j; continue; }
      out.length = save;
    }
    j++;
  }
  out.push(['tok-string', s.slice(start)]);
  return s.length;
}

function tag(s, i, out) {
  let m = at(R.tagName, s, i);
  out.push(['tok-tag', m]); i += m.length;
  while (i < s.length) {
    if ((m = at(R.ws, s, i))) { out.push(['', m]); i += m.length; continue; }
    if (s.startsWith('>', i)) { out.push(['tok-tag', '>']); return i + 1; }
    if (s.startsWith('/>', i)) { out.push(['tok-tag', '/>']); return i + 2; }
    const k = embedded(s, i, out);
    if (k >= 0) { i = k; continue; }
    if (s[i] === '"' || s[i] === "'") { i = quoted(s, i, out); continue; }
    if (s[i] === '=') {
      out.push(['', '=']); i++;
      if (s[i] === '"' || s[i] === "'") i = quoted(s, i, out);
      else if ((m = at(R.bare, s, i))) { out.push(['tok-string', m]); i += m.length; }
      continue;
    }
    if ((m = at(R.attrName, s, i))) { out.push(['tok-attr', m]); i += m.length; continue; }
    out.push(['', s[i]]); i++;
  }
  return s.length;
}

export function tokenize(src) {
  const out = [];
  let i = 0, textStart = 0;
  const flush = (end) => { if (end > textStart) out.push(['', src.slice(textStart, end)]); };
  while (i < src.length) {
    const c = src[i];
    let next = -1;
    if (c === '{') next = embedded(src, i, []) < 0 ? -1 : 0;   // snelle test; echte verwerking hieronder
    if (c === '{' && next === 0) { flush(i); i = embedded(src, i, out); textStart = i; continue; }
    if (c === '<') {
      if (src.startsWith('<?', i)) { flush(i); i = php(src, i, out); textStart = i; continue; }
      if (src.startsWith('<!--', i)) { flush(i); i = simpleBlock(src, i, '-->', 'tok-comment', out); textStart = i; continue; }
      if (/^<!doctype/i.test(src.slice(i, i + 9))) {
        flush(i);
        const end = src.indexOf('>', i);
        const stop = end < 0 ? src.length : end + 1;
        out.push(['tok-doctype', src.slice(i, stop)]); i = stop; textStart = i; continue;
      }
      if (/^<\/?[A-Za-z]/.test(src.slice(i, i + 3))) { flush(i); i = tag(src, i, out); textStart = i; continue; }
    }
    i++;
  }
  flush(src.length);
  // aangrenzende tokens met dezelfde klasse samenvoegen (minder DOM-nodes)
  const merged = [];
  for (const t of out) {
    const last = merged[merged.length - 1];
    if (last && last[0] === t[0]) last[1] += t[1]; else merged.push([t[0], t[1]]);
  }
  return merged;
}
