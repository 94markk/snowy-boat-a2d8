// Shared UI helpers: drag payloads, context menu, panel switching.
import { icon } from './icons';
import { h } from './utils';

export type DragPayload =
  | { type: 'media'; id: string }
  | { type: 'text'; preset: string }
  | { type: 'sticker'; emoji: string }
  | { type: 'effect'; id: string }
  | { type: 'sound'; id: string }
  | { type: 'transition'; id: string };

let payload: DragPayload | null = null;

export const dnd = {
  set(p: DragPayload | null) {
    payload = p;
    document.body.classList.toggle('is-dnd', !!p);
  },
  get(): DragPayload | null {
    return payload;
  },
};

export function makeDraggable(el: HTMLElement, p: DragPayload): void {
  el.draggable = true;
  el.addEventListener('dragstart', (e) => {
    dnd.set(p);
    e.dataTransfer?.setData('text/plain', JSON.stringify(p));
    if (e.dataTransfer) e.dataTransfer.effectAllowed = 'copy';
  });
  el.addEventListener('dragend', () => dnd.set(null));
}

export interface MenuItem {
  label: string;
  icon?: string;
  hint?: string;
  disabled?: boolean;
  danger?: boolean;
  run: () => void;
}

let menuEl: HTMLElement | null = null;

export function closeMenu(): void {
  menuEl?.remove();
  menuEl = null;
}

export function contextMenu(x: number, y: number, items: (MenuItem | 'sep')[]): void {
  closeMenu();
  const menu = h('div', { class: 'ctx-menu', role: 'menu' });
  for (const it of items) {
    if (it === 'sep') {
      menu.append(h('div', { class: 'ctx-sep', role: 'separator' }));
      continue;
    }
    const btn = h(
      'button',
      { class: `ctx-item${it.danger ? ' is-danger' : ''}`, role: 'menuitem', disabled: !!it.disabled },
      h('span', { class: 'ctx-ic', html: it.icon ? icon(it.icon, 16) : '' }),
      h('span', { class: 'ctx-label' }, it.label),
      it.hint ? h('kbd', {}, it.hint) : null,
    );
    btn.addEventListener('click', () => {
      closeMenu();
      it.run();
    });
    menu.append(btn);
  }
  document.body.append(menu);
  const r = menu.getBoundingClientRect();
  menu.style.left = `${Math.min(x, innerWidth - r.width - 8)}px`;
  menu.style.top = `${Math.min(y, innerHeight - r.height - 8)}px`;
  menuEl = menu;
}

addEventListener('pointerdown', (e) => {
  if (menuEl && !menuEl.contains(e.target as Node)) closeMenu();
});
addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeMenu();
});
addEventListener('blur', closeMenu);

/** Ask the library panel to switch tab. */
export function openLibraryTab(tab: string): void {
  dispatchEvent(new CustomEvent('library:tab', { detail: tab }));
}

/** Ask the inspector to switch tab. */
export function openInspectorTab(tab: string): void {
  dispatchEvent(new CustomEvent('inspector:tab', { detail: tab }));
}
