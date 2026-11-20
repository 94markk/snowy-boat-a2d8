// Left-hand asset library: media bin, audio, text, stickers, effects,
// transitions and filters. Static grids are rendered in Astro markup;
// this module wires them up and renders the dynamic media bin.
import type { MediaItem } from './types';
import * as A from './actions';
import { icon } from './icons';
import { clipEnd, isVisual, prevAdjacent } from './model';
import { importFiles, pickFiles } from './media';
import { store } from './store';
import { builtinSound } from './sounds';
import { contextMenu, makeDraggable, type DragPayload } from './ui';
import { $, $$, fmtBytes, h, shortTime, toast } from './utils';

export class Library {
  private grid = $('#media-grid');
  private empty = $('#media-empty');
  private audioList = $('#audio-list');
  private filter: 'all' | 'video' | 'image' | 'audio' = 'all';

  constructor() {
    $$<HTMLButtonElement>('#library [role="tab"]').forEach((b) => b.addEventListener('click', () => this.show(b.dataset.tab!)));
    addEventListener('library:tab', (e) => this.show((e as CustomEvent<string>).detail));
    $('#btn-import').addEventListener('click', async () => importFiles(await pickFiles()));
    this.empty.addEventListener('click', async () => importFiles(await pickFiles()));
    $$<HTMLButtonElement>('#media-filter [data-filter]').forEach((b) =>
      b.addEventListener('click', () => {
        this.filter = b.dataset.filter as typeof this.filter;
        $$('#media-filter [data-filter]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
        this.renderMedia();
      }),
    );
    store.on((r) => {
      if (r === 'media' || r === 'load' || r === 'project') this.renderMedia();
    });

    // Static items rendered in markup
    const lib = $('#library');
    lib.addEventListener('click', (e) => {
      const el = (e.target as HTMLElement).closest<HTMLElement>('[data-add]');
      if (el) void this.activate(el.dataset.add!, el.dataset.id!);
    });
    for (const el of $$<HTMLElement>('#library [data-add]')) {
      const kind = el.dataset.add!;
      const id = el.dataset.id!;
      const payload: DragPayload | null =
        kind === 'text' ? { type: 'text', preset: id }
        : kind === 'sticker' ? { type: 'sticker', emoji: id }
        : kind === 'effect' ? { type: 'effect', id }
        : kind === 'sound' ? { type: 'sound', id }
        : kind === 'transition' ? { type: 'transition', id }
        : null;
      if (payload) makeDraggable(el, payload);
    }
    this.renderMedia();
  }

  show(tab: string): void {
    $$<HTMLButtonElement>('#library [role="tab"]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.tab === tab)));
    $$<HTMLElement>('#library [data-panel]').forEach((p) => (p.hidden = p.dataset.panel !== tab));
  }

  private async activate(kind: string, id: string): Promise<void> {
    switch (kind) {
      case 'text':
        A.addText(id);
        break;
      case 'sticker':
        A.addSticker(id);
        break;
      case 'effect':
        A.addEffect(id);
        break;
      case 'sound': {
        const m = await builtinSound(id);
        if (m) A.addMedia(m, { time: store.time });
        break;
      }
      case 'transition':
        this.applyTransition(id);
        break;
      case 'filter': {
        const ids = store.selectedClips.filter((c) => c.type === 'video' || c.type === 'image').map((c) => c.id);
        if (!ids.length) {
          toast('Select a video or image clip first');
          return;
        }
        A.updateClips(ids, (c) => (c.filter = { id, intensity: c.filter.intensity || 1 }));
        break;
      }
    }
  }

  private applyTransition(type: string): void {
    const s = store.selection;
    if (s.kind === 'transition') {
      A.setTransition(s.id, type);
      return;
    }
    const c = store.selectedClip;
    if (!c || !isVisual(c) || c.type === 'text' || c.type === 'sticker') {
      toast('Select a video or image clip, or click a cut (+) on the timeline');
      return;
    }
    const p = store.project;
    let target = prevAdjacent(p, c) ? c : undefined;
    if (!target) target = p.clips.find((o) => o.trackId === c.trackId && Math.abs(o.start - clipEnd(c)) < 1e-3);
    if (!target) {
      toast('Transitions need two clips touching on the same track');
      return;
    }
    A.setTransition(target.id, type);
    store.select({ kind: 'transition', id: target.id });
  }

  private renderMedia(): void {
    const used = new Set(store.project.clips.map((c) => c.mediaId));
    const all = [...store.media.values()].filter((m) => !m.builtin || used.has(m.id));
    const items = all.filter((m) => this.filter === 'all' || m.kind === this.filter);
    this.empty.hidden = all.length > 0;
    this.grid.hidden = all.length === 0;
    this.grid.replaceChildren(...items.map((m) => this.card(m, used.has(m.id))));
    const audio = [...store.media.values()].filter((m) => m.kind === 'audio' && !m.builtin);
    this.audioList.replaceChildren(
      ...(audio.length
        ? audio.map((m) => this.audioRow(m))
        : [h('li', { class: 'lib-hint' }, 'Imported music and voiceovers appear here.')]),
    );
  }

  private card(m: MediaItem, used: boolean): HTMLElement {
    const thumb = h('div', { class: `media-thumb media-thumb--${m.kind}` });
    if (m.thumbs.length) thumb.style.backgroundImage = `url("${m.thumbs[0]}")`;
    else thumb.innerHTML = icon('music', 28);
    if (m.kind !== 'image') thumb.append(h('span', { class: 'media-dur' }, shortTime(m.duration)));
    if (used) thumb.append(h('span', { class: 'media-used', title: 'Used in timeline', html: icon('check', 12) }));
    const add = h('button', { type: 'button', class: 'media-add', title: 'Add to timeline', 'aria-label': `Add ${m.name} to timeline`, html: icon('plus', 16) });
    add.addEventListener('click', (e) => {
      e.stopPropagation();
      A.addMedia(m, m.kind === 'audio' ? { time: store.time } : {});
    });
    thumb.append(add);
    if (m.kind === 'video' && m.thumbs.length > 1) {
      thumb.addEventListener('pointermove', (e) => {
        const r = thumb.getBoundingClientRect();
        const i = Math.min(m.thumbs.length - 1, Math.floor(((e.clientX - r.left) / r.width) * m.thumbs.length));
        thumb.style.backgroundImage = `url("${m.thumbs[Math.max(0, i)]}")`;
      });
      thumb.addEventListener('pointerleave', () => (thumb.style.backgroundImage = `url("${m.thumbs[0]}")`));
    }
    const card = h(
      'div',
      { class: 'media-card', 'data-media': m.id, title: `${m.name}\n${m.kind}${m.width ? ` · ${m.width}×${m.height}` : ''} · ${fmtBytes(m.size)}` },
      thumb,
      h('div', { class: 'media-name' }, m.name),
    );
    makeDraggable(card, { type: 'media', id: m.id });
    card.addEventListener('dblclick', () => A.addMedia(m, m.kind === 'audio' ? { time: store.time } : {}));
    card.addEventListener('contextmenu', (e) => {
      e.preventDefault();
      contextMenu(e.clientX, e.clientY, [
        { label: 'Add to timeline', icon: 'plus', run: () => A.addMedia(m, m.kind === 'audio' ? { time: store.time } : {}) },
        { label: 'Add at playhead', icon: 'flag', run: () => A.addMedia(m, { time: store.time }) },
        'sep',
        {
          label: used ? 'Delete (also removes its clips)' : 'Delete',
          icon: 'trash',
          danger: true,
          run: () => {
            if (!used || confirm(`Remove "${m.name}" and every clip that uses it?`)) A.deleteMedia(m.id);
          },
        },
      ]);
    });
    return card;
  }

  private audioRow(m: MediaItem): HTMLElement {
    const li = h(
      'li',
      { class: 'sound-row' },
      h('span', { class: 'sound-ic', html: icon('music', 16) }),
      h('span', { class: 'sound-name' }, m.name),
      h('span', { class: 'sound-dur' }, shortTime(m.duration)),
    );
    const add = h('button', { type: 'button', class: 'icon-btn', title: 'Add at playhead', html: icon('plus', 16) });
    add.addEventListener('click', () => A.addMedia(m, { time: store.time }));
    li.append(add);
    makeDraggable(li, { type: 'media', id: m.id });
    return li;
  }
}
