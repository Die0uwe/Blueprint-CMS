// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later
// Eigen code-editor: een <textarea> (waar de gebruiker echt in typt) met daarachter
// een highlight-laag. De tokenizer hieronder is zelfgeschreven; er is geen externe
// library. Highlight-spans worden met createElement + textContent gebouwd, nooit met
// innerHTML, dus bestandsinhoud kan nooit als markup worden uitgevoerd.

const KW_JS = new Set(('break case catch class const continue debugger default delete do else export extends finally for function if import in instanceof let new of return static super switch this throw try typeof var void while with yield async await null true false undefined').split(' '));
const KW_PHP = new Set(('abstract and array as break callable case catch class clone const continue declare default do echo else elseif empty enddeclare endfor endforeach endif endswitch endwhile extends final finally fn for foreach function global if implements include include_once instanceof insteadof interface isset list match namespace new or print private protected public readonly require require_once return static switch throw trait try unset use var while xor yield null true false self parent').split(' '));
const KW_TWIG = new Set(('if else elseif endif for endfor in not and or is set endset block endblock extends include import from as with only macro endmacro embed endembed apply endapply autoescape endautoescape verbatim endverbatim true false null').split(' '));

/** Eén token: [type, text]. Types: kw, str, num, com, tag, attr, punct, var, fn, plain. */

function scan(src, rules, keywords) {
  const out = [];
  let i = 0;
  let plain = '';
  const flush = () => { if (plain) { out.push(['plain', plain]); plain = ''; } };
  outer: while (i < src.length) {
    for (const [type, re] of rules) {
      re.lastIndex = i;
      const m = re.exec(src);
      if (m && m.index === i && m[0].length > 0) {
        flush();
        let t = type;
        if (type === 'word') t = keywords && keywords.has(m[0]) ? 'kw' : 'plain';
        out.push([t, m[0]]);
        i += m[0].length;
        continue outer;
      }
    }
    plain += src[i++];
  }
  flush();
  return out;
}

const sticky = (re) => new RegExp(re.source, 'y' + re.flags.replace('y', ''));

const RULES_JS = [
  ['com', sticky(/\/\/[^\n]*|\/\*[\s\S]*?(?:\*\/|$)/)],
  ['str', sticky(/`(?:\\[\s\S]|[^`\\])*(?:`|$)|"(?:\\.|[^"\\\n])*(?:"|$)|'(?:\\.|[^'\\\n])*(?:'|$)/)],
  ['num', sticky(/\b(?:0x[\da-f]+|\d+(?:\.\d+)?(?:e[+-]?\d+)?)\b/i)],
  ['word', sticky(/[A-Za-z_$][\w$]*/)],
  ['punct', sticky(/[{}()[\];,.:?<>=!+\-*/%&|^~]+/)],
];

const RULES_CSS = [
  ['com', sticky(/\/\*[\s\S]*?(?:\*\/|$)/)],
  ['str', sticky(/"(?:\\.|[^"\\\n])*(?:"|$)|'(?:\\.|[^'\\\n])*(?:'|$)/)],
  ['num', sticky(/#[\da-f]{3,8}\b|-?\d*\.?\d+(?:px|em|rem|%|vh|vw|s|ms|deg|fr)?/i)],
  ['attr', sticky(/[a-z-]+(?=\s*:)/i)],
  ['var', sticky(/--[\w-]+|@[\w-]+/)],
  ['fn', sticky(/[\w-]+(?=\()/)],
  ['tag', sticky(/[.#]?[a-z_][\w-]*/i)],
  ['punct', sticky(/[{}()[\];,:>+~*=]+/)],
];

const RULES_PHP = [
  ['com', sticky(/\/\/[^\n]*|#[^\n]*|\/\*[\s\S]*?(?:\*\/|$)/)],
  ['str', sticky(/"(?:\\[\s\S]|[^"\\])*(?:"|$)|'(?:\\[\s\S]|[^'\\])*(?:'|$)/)],
  ['num', sticky(/\b(?:0x[\da-f]+|\d+(?:\.\d+)?)\b/i)],
  ['var', sticky(/\$[A-Za-z_]\w*/)],
  ['word', sticky(/[A-Za-z_\\][\w\\]*/)],
  ['punct', sticky(/[{}()[\];,.:?<>=!+\-*/%&|^~@]+/)],
];

const RULES_TWIG = [
  ['com', sticky(/\{#[\s\S]*?(?:#\}|$)/)],
  ['punct', sticky(/\{\{-?|-?\}\}|\{%-?|-?%\}/)],
  ['str', sticky(/"(?:\\.|[^"\\])*(?:"|$)|'(?:\\.|[^'\\])*(?:'|$)/)],
  ['num', sticky(/\b\d+(?:\.\d+)?\b/)],
  ['word', sticky(/[A-Za-z_]\w*/)],
  ['punct', sticky(/[()[\]{},.:?|=!<>+\-*/%~]+/)],
];

/** HTML met ingebedde PHP, Twig, <script> en <style>. */
function tokenizeHtml(src, allowPhp, allowTwig) {
  const out = [];
  let i = 0;
  const push = (t, s) => { if (s) out.push([t, s]); };
  const rest = () => src.slice(i);

  while (i < src.length) {
    const r = rest();
    let m;

    if (allowPhp && (m = /^<\?(?:php|=)?[\s\S]*?(?:\?>|$)/.exec(r))) {
      const text = m[0];
      const open = /^<\?(?:php|=)?/.exec(text)[0];
      const close = text.endsWith('?>') ? '?>' : '';
      push('punct', open);
      for (const t of scan(text.slice(open.length, text.length - close.length), RULES_PHP, KW_PHP)) out.push(t);
      push('punct', close);
      i += text.length;
    } else if (allowTwig && (m = /^(?:\{#[\s\S]*?(?:#\}|$)|\{\{[\s\S]*?(?:\}\}|$)|\{%[\s\S]*?(?:%\}|$))/.exec(r))) {
      for (const t of scan(m[0], RULES_TWIG, KW_TWIG)) out.push(t);
      i += m[0].length;
    } else if ((m = /^<!--[\s\S]*?(?:-->|$)/.exec(r))) {
      push('com', m[0]); i += m[0].length;
    } else if ((m = /^<!DOCTYPE[^>]*>?/i.exec(r))) {
      push('kw', m[0]); i += m[0].length;
    } else if ((m = /^<(script|style)\b[^>]*>/i.exec(r))) {
      i += tag(m[0], out);
      const name = m[1].toLowerCase();
      const end = src.toLowerCase().indexOf('</' + name, i);
      const body = src.slice(i, end === -1 ? src.length : end);
      const toks = name === 'script' ? scan(body, RULES_JS, KW_JS) : scan(body, RULES_CSS, null);
      for (const t of toks) out.push(t);
      i += body.length;
    } else if ((m = /^<\/?[A-Za-z][^>]*>?/.exec(r)) && !/^<[A-Za-z][^>]*?(?=<\?|\{\{|\{%)/.test(r)) {
      i += tag(m[0], out);
    } else if ((m = /^&[#\w]+;/.exec(r))) {
      push('num', m[0]); i += m[0].length;
    } else {
      const next = src.slice(i + 1).search(/[<&{]/);
      const len = next === -1 ? src.length - i : next + 1;
      push('plain', src.slice(i, i + len)); i += len;
    }
  }
  return out;
}

/** Tokeniseer één tag: <naam attr="waarde"> */
function tag(text, out) {
  const re = /(<\/?)([A-Za-z][\w:-]*)|("[^"]*"|'[^']*')|([A-Za-z_:@][\w:.-]*)(?==)|(\/?>)|(=)|(\s+)|([^\s<>="']+)/g;
  let m;
  while ((m = re.exec(text))) {
    if (m[1] !== undefined) { out.push(['punct', m[1]]); out.push(['tag', m[2]]); }
    else if (m[3] !== undefined) out.push(['str', m[3]]);
    else if (m[4] !== undefined) out.push(['attr', m[4]]);
    else if (m[5] !== undefined) out.push(['punct', m[5]]);
    else if (m[6] !== undefined) out.push(['punct', m[6]]);
    else out.push(['plain', m[0]]);
  }
  return text.length;
}

export function tokenize(src, language) {
  switch (language) {
    case 'js': return scan(src, RULES_JS, KW_JS);
    case 'css': return scan(src, RULES_CSS, null);
    case 'php': return /^\s*<\?/.test(src) || /\?>/.test(src) ? tokenizeHtml(src, true, false) : scan(src, RULES_PHP, KW_PHP);
    case 'twig': return tokenizeHtml(src, false, true);
    case 'html': return tokenizeHtml(src, true, true);
    default: return [['plain', src]];
  }
}

export class Editor {
  /** @param {HTMLElement} root */
  constructor(root, { language = 'html', onChange = null } = {}) {
    this.root = root;
    this.language = language;
    this.onChange = onChange;
    this.trapTab = true;
    this.pending = false;

    root.textContent = '';
    this.pre = document.createElement('pre');
    this.pre.className = 'ed-hl';
    this.pre.setAttribute('aria-hidden', 'true');
    this.code = document.createElement('code');
    this.pre.appendChild(this.code);

    this.area = document.createElement('textarea');
    this.area.className = 'ed-input';
    this.area.spellcheck = false;
    this.area.setAttribute('autocapitalize', 'off');
    this.area.setAttribute('autocomplete', 'off');
    this.area.setAttribute('wrap', 'off');
    this.area.setAttribute('aria-label', 'Code-editor');

    root.append(this.pre, this.area);

    this.area.addEventListener('input', () => { this.schedule(); if (this.onChange) this.onChange(this.value); });
    this.area.addEventListener('scroll', () => this.syncScroll());
    this.area.addEventListener('keydown', (e) => this.keydown(e));
    this.area.addEventListener('blur', () => { this.trapTab = true; });
    this.render();
  }

  get value() { return this.area.value; }

  set value(text) {
    this.area.value = text;
    this.render();
    if (this.onChange) this.onChange(text);
  }

  setLanguage(language) {
    this.language = language;
    this.root.dataset.language = language;
    this.render();
  }

  focus() { this.area.focus(); }

  schedule() {
    if (this.pending) return;
    this.pending = true;
    requestAnimationFrame(() => { this.pending = false; this.render(); });
  }

  render() {
    const frag = document.createDocumentFragment();
    for (const [type, text] of tokenize(this.area.value, this.language)) {
      if (type === 'plain') {
        frag.appendChild(document.createTextNode(text));
      } else {
        const span = document.createElement('span');
        span.className = 'tk-' + type;
        span.textContent = text;
        frag.appendChild(span);
      }
    }
    // Een afsluitende newline zou in <pre> geen hoogte geven; een extra teken houdt de lagen gelijk.
    frag.appendChild(document.createTextNode('\n'));
    this.code.replaceChildren(frag);
    this.syncScroll();
  }

  syncScroll() {
    this.pre.scrollTop = this.area.scrollTop;
    this.pre.scrollLeft = this.area.scrollLeft;
  }

  keydown(e) {
    // Toegankelijkheid: Esc zet de Tab-val uit zodat je met het toetsenbord de editor kunt verlaten.
    if (e.key === 'Escape') { this.trapTab = false; return; }
    if (e.key === 'Tab' && this.trapTab && !e.ctrlKey && !e.metaKey && !e.altKey) {
      e.preventDefault();
      const a = this.area;
      const start = a.selectionStart;
      const end = a.selectionEnd;
      a.setRangeText('  ', start, end, 'end');
      this.schedule();
      if (this.onChange) this.onChange(a.value);
    }
  }
}
