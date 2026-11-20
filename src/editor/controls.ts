// Small form-control kit for the inspector. Each control returns an element
// plus an `update` callback that re-reads the model without rebuilding DOM.
import { icon } from './icons';
import { h } from './utils';

export interface Ctl {
  el: HTMLElement;
  update: () => void;
}

export function group(title: string, ctls: (Ctl | null)[], extra?: HTMLElement): Ctl {
  const list = ctls.filter((c): c is Ctl => !!c);
  const el = h('section', { class: 'insp-group' }, h('header', { class: 'insp-group-head' }, h('h3', {}, title), extra ?? null), ...list.map((c) => c.el));
  return { el, update: () => list.forEach((c) => c.update()) };
}

export function slider(o: {
  label: string;
  min: number;
  max: number;
  step?: number;
  get: () => number;
  set: (v: number) => void;
  fmt?: (v: number) => string;
  /** Display multiplier for the number box (e.g. 100 for percentages). */
  mul?: number;
  unit?: string;
  extra?: HTMLElement;
}): Ctl {
  const mul = o.mul ?? 1;
  const range = h('input', { type: 'range', min: o.min, max: o.max, step: o.step ?? 0.01, 'aria-label': o.label });
  const num = h('input', { type: 'number', class: 'num', step: (o.step ?? 0.01) * mul, 'aria-label': `${o.label} value` });
  const el = h(
    'div',
    { class: 'ctl ctl-slider' },
    h('label', { class: 'ctl-label' }, o.label, o.extra ?? null),
    h('div', { class: 'ctl-row' }, range, h('span', { class: 'num-wrap' }, num, o.unit ? h('span', { class: 'unit' }, o.unit) : null)),
  );
  const paint = () => {
    const pct = ((+range.value - o.min) / (o.max - o.min)) * 100;
    range.style.setProperty('--pct', `${pct}%`);
  };
  range.addEventListener('input', () => {
    o.set(+range.value);
    num.value = fmtNum(+range.value * mul);
    paint();
  });
  num.addEventListener('change', () => {
    const v = Math.min(o.max, Math.max(o.min, +num.value / mul));
    if (Number.isFinite(v)) o.set(v);
  });
  range.addEventListener('dblclick', () => {
    // double-click resets to the neutral value
    const neutral = o.min < 0 && o.max > 0 ? 0 : o.min <= 1 && o.max >= 1 && o.label.match(/scale|speed/i) ? 1 : NaN;
    if (Number.isFinite(neutral)) o.set(neutral);
  });
  const update = () => {
    const v = o.get();
    range.value = String(v);
    if (document.activeElement !== num) num.value = fmtNum(v * mul);
    paint();
  };
  update();
  return { el, update };
}

const fmtNum = (v: number) => String(Math.round(v * 100) / 100);

export function select(o: { label: string; options: { id: string; label: string }[]; get: () => string; set: (v: string) => void }): Ctl {
  const sel = h('select', { 'aria-label': o.label });
  for (const opt of o.options) sel.append(h('option', { value: opt.id }, opt.label));
  sel.addEventListener('change', () => o.set(sel.value));
  const el = h('div', { class: 'ctl ctl-inline' }, h('label', { class: 'ctl-label' }, o.label), sel);
  const update = () => {
    sel.value = o.get();
  };
  update();
  return { el, update };
}

export function color(o: { label: string; get: () => string; set: (v: string) => void }): Ctl {
  const inp = h('input', { type: 'color', 'aria-label': o.label });
  const hex = h('span', { class: 'hex' });
  inp.addEventListener('input', () => {
    o.set(inp.value);
    hex.textContent = inp.value.toUpperCase();
  });
  const el = h('div', { class: 'ctl ctl-inline' }, h('label', { class: 'ctl-label' }, o.label), h('span', { class: 'color-wrap' }, inp, hex));
  const update = () => {
    inp.value = o.get();
    hex.textContent = o.get().toUpperCase();
  };
  update();
  return { el, update };
}

export function toggle(o: { label: string; get: () => boolean; set: (v: boolean) => void; hint?: string }): Ctl {
  const inp = h('input', { type: 'checkbox', role: 'switch', 'aria-label': o.label });
  inp.addEventListener('change', () => o.set(inp.checked));
  const el = h(
    'label',
    { class: 'ctl ctl-inline ctl-toggle' },
    h('span', { class: 'ctl-label' }, o.label, o.hint ? h('small', {}, o.hint) : null),
    h('span', { class: 'switch' }, inp, h('span', { class: 'switch-track' })),
  );
  const update = () => {
    inp.checked = o.get();
  };
  update();
  return { el, update };
}

export function seg(o: { label?: string; options: { id: string; label: string; icon?: string }[]; get: () => string; set: (v: string) => void }): Ctl {
  const wrap = h('div', { class: 'seg', role: 'radiogroup', 'aria-label': o.label ?? '' });
  const btns = o.options.map((opt) => {
    const b = h('button', {
      type: 'button',
      role: 'radio',
      class: 'seg-btn',
      title: opt.label,
      html: opt.icon ? icon(opt.icon, 16) : '',
    });
    if (!opt.icon) b.textContent = opt.label;
    b.addEventListener('click', () => o.set(opt.id));
    wrap.append(b);
    return [opt.id, b] as const;
  });
  const el = o.label ? h('div', { class: 'ctl ctl-inline' }, h('span', { class: 'ctl-label' }, o.label), wrap) : h('div', { class: 'ctl' }, wrap);
  const update = () => {
    const v = o.get();
    for (const [id, b] of btns) b.setAttribute('aria-checked', String(id === v));
  };
  update();
  return { el, update };
}

export function tiles(o: {
  items: { id: string; label: string; style?: string; icon?: string; preview?: string }[];
  get: () => string;
  set: (v: string) => void;
  cls?: string;
}): Ctl {
  const wrap = h('div', { class: `tiles ${o.cls ?? ''}` });
  const btns = o.items.map((it) => {
    const b = h(
      'button',
      { type: 'button', class: 'tile', title: it.label },
      h('span', { class: 'tile-thumb', style: it.style ?? '', html: it.icon ? icon(it.icon, 20) : it.preview ?? '' }),
      h('span', { class: 'tile-label' }, it.label),
    );
    b.addEventListener('click', () => o.set(it.id));
    wrap.append(b);
    return [it.id, b] as const;
  });
  const update = () => {
    const v = o.get();
    for (const [id, b] of btns) b.classList.toggle('is-active', id === v);
  };
  update();
  return { el: wrap, update };
}

export function button(label: string, iconName: string, onClick: () => void, cls = ''): HTMLButtonElement {
  const b = h('button', { type: 'button', class: `btn ${cls}`, html: `${icon(iconName, 16)}<span>${label}</span>` });
  b.addEventListener('click', onClick);
  return b;
}

export function row(...children: HTMLElement[]): Ctl {
  return { el: h('div', { class: 'ctl ctl-buttons' }, ...children), update: () => undefined };
}

export function textarea(o: { label: string; get: () => string; set: (v: string) => void }): Ctl {
  const ta = h('textarea', { rows: 3, 'aria-label': o.label, spellcheck: 'true', id: 'text-content' });
  ta.addEventListener('input', () => o.set(ta.value));
  const el = h('div', { class: 'ctl' }, ta);
  const update = () => {
    if (document.activeElement !== ta) ta.value = o.get();
  };
  update();
  return { el, update };
}

export function note(text: string): Ctl {
  return { el: h('p', { class: 'insp-note' }, text), update: () => undefined };
}

export function custom(el: HTMLElement, update: () => void = () => undefined): Ctl {
  return { el, update };
}
