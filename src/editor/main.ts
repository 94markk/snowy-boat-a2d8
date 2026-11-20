// Editor bootstrap: wires every module to the markup in src/pages/index.astro.
import { runCommand } from './commands';
import { Exporter } from './exporter';
import { Inspector } from './inspector';
import { Library } from './library';
import { importFiles } from './media';
import { Preview } from './preview';
import { ProjectsDialog, bootProject, initAutosave } from './projects';
import { Recorder } from './recorder';
import { initShortcuts } from './shortcuts';
import { store } from './store';
import { Timeline } from './timeline';
import { $, $$, clamp } from './utils';

function initTopBar(): void {
  const name = $<HTMLInputElement>('#project-name');
  const sync = () => {
    if (document.activeElement !== name) name.value = store.project.name;
    document.title = `${store.project.name} — Cutline`;
  };
  store.on((r) => (r === 'project' || r === 'load') && sync());
  name.addEventListener('change', () => store.commit((p) => (p.name = name.value.trim() || 'Untitled project')));
  name.addEventListener('keydown', (e) => e.key === 'Enter' && name.blur());
  sync();
  $('#topbar').addEventListener('click', (e) => {
    const b = (e.target as HTMLElement).closest<HTMLElement>('[data-cmd]');
    if (b) runCommand(b.dataset.cmd!);
  });
  const undo = $<HTMLButtonElement>('#topbar [data-cmd="undo"]');
  const redo = $<HTMLButtonElement>('#topbar [data-cmd="redo"]');
  store.on(() => {
    undo.disabled = !store.canUndo;
    redo.disabled = !store.canRedo;
  });
  $('#btn-shortcuts').addEventListener('click', () => $<HTMLDialogElement>('#shortcuts-dialog').showModal());
  $$<HTMLElement>('[data-close-dialog]').forEach((b) => b.addEventListener('click', () => b.closest('dialog')?.close()));
}

function initFileDrop(): void {
  const overlay = $('#drop-overlay');
  let depth = 0;
  const hasFiles = (e: DragEvent) => !!e.dataTransfer && [...e.dataTransfer.types].includes('Files');
  addEventListener('dragenter', (e) => {
    if (!hasFiles(e)) return;
    depth++;
    overlay.hidden = false;
  });
  addEventListener('dragleave', (e) => {
    if (!hasFiles(e)) return;
    depth = Math.max(0, depth - 1);
    if (!depth) overlay.hidden = true;
  });
  addEventListener('dragover', (e) => {
    if (hasFiles(e)) e.preventDefault();
  });
  addEventListener('drop', (e) => {
    if (!hasFiles(e)) return;
    e.preventDefault();
    depth = 0;
    overlay.hidden = true;
    void importFiles(e.dataTransfer!.files);
  });
}

function initResizer(): void {
  const handle = $('#tl-resizer');
  const app = $('#app');
  const saved = Number(localStorageGet('cutline:tl-h'));
  if (saved) app.style.setProperty('--tl-h', `${saved}px`);
  handle.addEventListener('pointerdown', (e) => {
    e.preventDefault();
    const startY = e.clientY;
    const startH = $('#timeline').getBoundingClientRect().height;
    const move = (ev: PointerEvent) => {
      const hgt = clamp(startH - (ev.clientY - startY), 160, innerHeight - 260);
      app.style.setProperty('--tl-h', `${hgt}px`);
    };
    const up = () => {
      removeEventListener('pointermove', move);
      removeEventListener('pointerup', up);
      const v = parseInt(app.style.getPropertyValue('--tl-h'));
      if (v) localStorageSet('cutline:tl-h', String(v));
    };
    addEventListener('pointermove', move);
    addEventListener('pointerup', up);
  });
}

function localStorageGet(k: string): string | null {
  try {
    return localStorage.getItem(k);
  } catch {
    return null;
  }
}

function localStorageSet(k: string, v: string): void {
  try {
    localStorage.setItem(k, v);
  } catch {
    /* ignore */
  }
}

async function boot(): Promise<void> {
  new Preview();
  new Timeline();
  new Inspector();
  new Library();
  new Recorder();
  new Exporter();
  new ProjectsDialog();
  initTopBar();
  initShortcuts();
  initFileDrop();
  initResizer();
  await bootProject();
  initAutosave();
  document.body.classList.add('is-ready');
  // Debug handle for development builds only.
  if (import.meta.env.DEV) Object.assign(window, { __cutline: { store } });
}

void boot();
