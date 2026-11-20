// Global keyboard shortcuts.
import { runCommand } from './commands';
import { engine } from './engine';
import { store } from './store';
import { isTypingTarget } from './utils';

export function initShortcuts(): void {
  addEventListener('keydown', (e) => {
    if (isTypingTarget(e.target) || document.querySelector('dialog[open]')) return;
    const mod = e.ctrlKey || e.metaKey;
    const k = e.key.toLowerCase();
    // Let focused sliders, checkboxes and buttons keep their own keyboard behaviour.
    if (e.target instanceof HTMLInputElement && k.startsWith('arrow')) return;
    if (e.target instanceof HTMLButtonElement && (k === ' ' || k === 'enter')) {
      if (k === 'enter') return;
      e.target.blur();
    }
    let cmd: string | null = null;
    if (mod && k === 'z') cmd = e.shiftKey ? 'redo' : 'undo';
    else if (mod && k === 'y') cmd = 'redo';
    else if (mod && k === 'b') cmd = 'split';
    else if (mod && k === 'd') cmd = 'duplicate';
    else if (mod && k === 'c') cmd = 'copy';
    else if (mod && k === 'v') cmd = 'paste';
    else if (mod && k === 'a') cmd = 'selectAll';
    else if (mod && (k === '=' || k === '+')) cmd = 'zoomIn';
    else if (mod && k === '-') cmd = 'zoomOut';
    else if (mod) return;
    else if (k === ' ') cmd = 'play';
    else if (k === 's') cmd = 'split';
    else if (k === 'q') cmd = 'trimLeft';
    else if (k === 'w') cmd = 'trimRight';
    else if (k === 'delete' || k === 'backspace') cmd = 'delete';
    else if (k === 'm') cmd = 'marker';
    else if (k === 'n') cmd = 'snap';
    else if (k === 'l') cmd = 'loop';
    else if (k === 'home') cmd = 'toStart';
    else if (k === 'end') cmd = 'toEnd';
    else if (k === '=' || k === '+') cmd = 'zoomIn';
    else if (k === '-' || k === '_') cmd = 'zoomOut';
    else if (k === 'z' && e.shiftKey) cmd = 'zoomFit';
    else if (k === 'arrowleft') {
      e.preventDefault();
      if (e.shiftKey) engine.seek(store.time - 1);
      else engine.step(-1);
      return;
    } else if (k === 'arrowright') {
      e.preventDefault();
      if (e.shiftKey) engine.seek(store.time + 1);
      else engine.step(1);
      return;
    } else if (k === 'escape') {
      store.select({ kind: 'none' });
      return;
    } else if (k === '?' || (k === '/' && e.shiftKey)) {
      (document.getElementById('shortcuts-dialog') as HTMLDialogElement | null)?.showModal();
      return;
    }
    if (!cmd) return;
    e.preventDefault();
    runCommand(cmd);
  });
}
