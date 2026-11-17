// Span-preserving CSS editor: drops selectors that require a dead class/id token, removes rules left
// without selectors and group at-rules (@media/@supports/...) left empty. Everything else is kept
// byte-for-byte (comments, formatting, @keyframes, @font-face). Works on minified bundles and on
// readable sources alike.
const GROUP = /^@(media|supports|container|layer|document|scope)\b/i;
const FUNC = /:(?:not|is|where|has|matches|-webkit-any|-moz-any|host|host-context|nth-child|nth-last-child|nth-of-type|nth-last-of-type|lang|dir)\(/iy;

function skipString(s, i) { const q = s[i]; i++; while (i < s.length && s[i] !== q) { if (s[i] === '\\') i++; i++; } return i + 1; }
function skipComment(s, i) { const j = s.indexOf('*/', i + 2); return j < 0 ? s.length : j + 2; }
// index of the next top-level char in `stops` (outside strings, comments, () and [])
function scanTo(s, i, stops) {
  let d = 0;
  while (i < s.length) {
    const c = s[i];
    if (c === '"' || c === "'") { i = skipString(s, i); continue; }
    if (c === '/' && s[i + 1] === '*') { i = skipComment(s, i); continue; }
    if (c === '(' || c === '[') d++;
    else if (c === ')' || c === ']') d--;
    else if (d === 0 && stops.includes(c)) return i;
    i++;
  }
  return s.length;
}
function matchBrace(s, i) { // s[i] === '{'
  let d = 0;
  while (i < s.length) {
    const c = s[i];
    if (c === '"' || c === "'") { i = skipString(s, i); continue; }
    if (c === '/' && s[i + 1] === '*') { i = skipComment(s, i); continue; }
    if (c === '{') d++;
    else if (c === '}') { d--; if (d === 0) return i; }
    i++;
  }
  throw new Error('unbalanced braces');
}
function splitTop(s) { const out = []; let i = 0, last = 0; while (i < s.length) { const j = scanTo(s, i, ','); out.push(s.slice(last, j)); i = last = j + 1; } return out; }
function stripFunc(sel) {
  let out = '', i = 0;
  while (i < sel.length) {
    FUNC.lastIndex = i; const m = FUNC.exec(sel);
    if (m) { let d = 0, j = i + m[0].length - 1; for (; j < sel.length; j++) { if (sel[j] === '(') d++; else if (sel[j] === ')') { d--; if (d === 0) break; } } i = j + 1; continue; }
    out += sel[i]; i++;
  }
  return out.replace(/\[[^\]]*\]/g, '').replace(/\/\*[\s\S]*?\*\//g, '');
}
export function selectorIsDead(sel, dead) {
  const s = stripFunc(sel);
  const re = /[.#](-?[A-Za-z_][\w-]*)/g; let m;
  while ((m = re.exec(s))) if (dead.has(m[1])) return true;
  return false;
}
export function editCss(css, dead, log = []) {
  function block(s) {
    let out = '', i = 0;
    while (i < s.length) {
      // leading whitespace / comments are copied as-is
      const ws = /^(?:\s|\/\*[\s\S]*?\*\/)*/.exec(s.slice(i))[0];
      // whitespace that only precedes a rule we drop goes with it (readable sources stay tidy)
      const lead = /\s*$/.exec(ws)[0]; const keepWs = ws.slice(0, ws.length - lead.length);
      out += ws; i += ws.length; const dropLead = () => { out = out.slice(0, out.length - lead.length); };
      if (i >= s.length) break;
      if (s[i] === '}' ) throw new Error('stray }');
      const p = scanTo(s, i, '{;}');
      if (p >= s.length || s[p] === ';' || s[p] === '}') { out += s.slice(i, Math.min(p + 1, s.length)); i = p + 1; continue; }
      const prelude = s.slice(i, p); const e = matchBrace(s, p); const body = s.slice(p + 1, e);
      const pre = prelude.trim();
      if (pre.startsWith('@')) {
        if (GROUP.test(pre)) {
          const inner = block(body);
          if (inner.replace(/\/\*[\s\S]*?\*\//g, '').trim() === '') { log.push('drop-empty ' + pre); dropLead(); }
          else out += prelude + '{' + inner + '}';
        } else out += s.slice(i, e + 1);
      } else {
        const sels = splitTop(prelude);
        const keep = sels.filter((x) => !selectorIsDead(x, dead));
        if (keep.length === sels.length) out += s.slice(i, e + 1);
        else if (keep.length === 0) { log.push('drop-rule ' + pre.slice(0, 120)); dropLead(); }
        else {
          log.push('trim-selectors ' + sels.filter((x) => selectorIsDead(x, dead)).map((x) => x.trim()).join(' | ').slice(0, 160));
          // keep the original spelling of the kept selectors; minified input joins with ',' and so do we
          const lead = /^\s*/.exec(prelude)[0];
          const tail = /\s*$/.exec(prelude)[0];
          out += lead + keep.map((x, k) => (k === 0 ? x.replace(/^\s+/, '') : x)).join(',').replace(/\s*$/, '') + tail + s.slice(p, e + 1);
        }
      }
      i = e + 1;
    }
    return out;
  }
  return block(css);
}
