// Project persistence (autosave) and the project manager dialog.
import type { Project } from './types';
import * as db from './db';
import { engine } from './engine';
import { icon } from './icons';
import { newProject } from './model';
import { store } from './store';
import { $, debounce, h, shortTime, toast } from './utils';

const statusEl = () => document.getElementById('save-status');

function setStatus(text: string, state: 'saving' | 'saved' | 'error'): void {
  const el = statusEl();
  if (!el) return;
  el.textContent = text;
  el.dataset.state = state;
}

let pending = false;

async function saveNow(): Promise<void> {
  pending = false;
  const p = store.project;
  const first = p.clips
    .filter((c) => c.mediaId)
    .sort((a, b) => a.start - b.start)
    .map((c) => store.media.get(c.mediaId!))
    .find((m) => m?.thumbs.length);
  try {
    await db.saveProject(p, { thumb: first?.thumbs[0], durationSec: store.duration });
    setStatus('Saved', 'saved');
  } catch {
    setStatus('Not saved', 'error');
  }
}

const saveSoon = debounce(() => void saveNow(), 700);

export function initAutosave(): void {
  store.on((r) => {
    if (r === 'project' || r === 'media') {
      pending = true;
      setStatus('Saving…', 'saving');
      saveSoon();
    }
  });
  addEventListener('beforeunload', () => {
    if (pending) void saveNow();
  });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden && pending) void saveNow();
  });
}

export async function openProject(id: string): Promise<boolean> {
  const rec = await db.loadProject(id);
  if (!rec) return false;
  const { thumb: _t, durationSec: _d, ...project } = rec;
  const media = await db.loadMedia(id);
  engine.reset();
  store.load(project as Project, media);
  setStatus('Saved', 'saved');
  return true;
}

export async function createProject(): Promise<void> {
  const n = (await db.listProjects()).length + 1;
  const p = newProject(`Project ${n}`);
  engine.reset();
  store.load(p, []);
  await db.saveProject(p);
  setStatus('Saved', 'saved');
}

export async function bootProject(): Promise<void> {
  const last = db.lastProjectId();
  try {
    if (last && (await openProject(last))) return;
    const list = await db.listProjects();
    if (list[0] && (await openProject(list[0].id))) return;
  } catch {
    /* IndexedDB unavailable: fall through to a fresh in-memory project */
  }
  try {
    await createProject();
  } catch {
    store.load(newProject(), []);
    setStatus('Storage unavailable', 'error');
  }
}

export class ProjectsDialog {
  private dlg = $<HTMLDialogElement>('#projects-dialog');
  private list = $('#project-list');

  constructor() {
    $('#btn-projects').addEventListener('click', () => void this.open());
    $('#btn-new-project').addEventListener('click', async () => {
      await saveNow();
      await createProject();
      this.dlg.close();
      toast('New project created', 'success');
    });
    $('#projects-close').addEventListener('click', () => this.dlg.close());
  }

  async open(): Promise<void> {
    await saveNow();
    await this.render();
    this.dlg.showModal();
  }

  private async render(): Promise<void> {
    const projects = await db.listProjects();
    this.list.replaceChildren(
      ...projects.map((p) => {
        const current = p.id === store.project.id;
        const thumb = h('div', { class: 'proj-thumb', style: p.thumb ? `background-image:url("${p.thumb}")` : '' });
        if (!p.thumb) thumb.innerHTML = icon('film', 22);
        const open = h('button', { type: 'button', class: 'btn', disabled: current }, current ? 'Open now' : 'Open');
        open.addEventListener('click', async () => {
          await saveNow();
          await openProject(p.id);
          this.dlg.close();
        });
        const del = h('button', { type: 'button', class: 'icon-btn is-danger', title: 'Delete project', html: icon('trash', 16) });
        del.addEventListener('click', async () => {
          if (!confirm(`Delete "${p.name}" and its media? This cannot be undone.`)) return;
          await db.deleteProject(p.id);
          if (current) await bootProject();
          await this.render();
        });
        return h(
          'li',
          { class: `proj${current ? ' is-current' : ''}` },
          thumb,
          h(
            'div',
            { class: 'proj-meta' },
            h('strong', {}, p.name),
            h('small', {}, `${p.aspect} · ${shortTime(p.durationSec ?? 0)} · edited ${new Date(p.updated).toLocaleString()}`),
          ),
          open,
          del,
        );
      }),
    );
  }
}
