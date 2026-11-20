// Named editor commands shared by toolbars, context menus and shortcuts.
import * as A from './actions';
import { engine } from './engine';
import { store } from './store';
import { downloadBlob, toast } from './utils';

export interface Command {
  label: string;
  icon: string;
  keys?: string;
  run: () => void;
  enabled?: () => boolean;
}

const hasSel = () => store.selectedClips.length > 0 || store.selection.kind === 'transition';

export const commands: Record<string, Command> = {
  undo: { label: 'Undo', icon: 'undo', keys: 'Ctrl+Z', run: () => store.undo(), enabled: () => store.canUndo },
  redo: { label: 'Redo', icon: 'redo', keys: 'Ctrl+Shift+Z', run: () => store.redo(), enabled: () => store.canRedo },
  split: { label: 'Split', icon: 'scissors', keys: 'Ctrl+B', run: A.splitAtPlayhead, enabled: () => store.project.clips.length > 0 },
  trimLeft: { label: 'Delete left of playhead', icon: 'trimLeft', keys: 'Q', run: () => A.trimToPlayhead('left'), enabled: () => store.project.clips.length > 0 },
  trimRight: { label: 'Delete right of playhead', icon: 'trimRight', keys: 'W', run: () => A.trimToPlayhead('right'), enabled: () => store.project.clips.length > 0 },
  delete: { label: 'Delete', icon: 'trash', keys: 'Del', run: A.deleteSelection, enabled: hasSel },
  duplicate: { label: 'Duplicate', icon: 'copy', keys: 'Ctrl+D', run: A.duplicateSelection, enabled: () => store.selectedClips.length > 0 },
  copy: { label: 'Copy', icon: 'copy', keys: 'Ctrl+C', run: A.copySelection, enabled: () => store.selectedClips.length > 0 },
  paste: { label: 'Paste at playhead', icon: 'file', keys: 'Ctrl+V', run: A.paste },
  freeze: { label: 'Freeze frame', icon: 'freeze', run: () => void A.freezeFrame(), enabled: () => store.selectedClip?.type === 'video' },
  detach: {
    label: 'Extract audio',
    icon: 'detach',
    run: A.detachAudio,
    enabled: () => {
      const c = store.selectedClip;
      return c?.type === 'video' && !!(c.mediaId && store.media.get(c.mediaId)?.hasAudio) && !c.muted;
    },
  },
  marker: { label: 'Add / remove marker', icon: 'flag', keys: 'M', run: A.addMarker },
  magnet: {
    label: 'Main track magnet',
    icon: 'magnet',
    run: () => store.commit((p) => (p.magnet = !p.magnet)),
  },
  snap: {
    label: 'Snapping',
    icon: 'snap',
    keys: 'N',
    run: () => store.commit((p) => (p.snap = !p.snap)),
  },
  play: { label: 'Play / pause', icon: 'play', keys: 'Space', run: () => engine.toggle() },
  toStart: { label: 'Go to start', icon: 'toStart', keys: 'Home', run: () => engine.seek(0) },
  toEnd: { label: 'Go to end', icon: 'toEnd', keys: 'End', run: () => engine.seek(store.duration) },
  stepBack: { label: 'Previous frame', icon: 'stepBack', keys: '←', run: () => engine.step(-1) },
  stepFwd: { label: 'Next frame', icon: 'stepFwd', keys: '→', run: () => engine.step(1) },
  loop: {
    label: 'Loop playback',
    icon: 'repeat',
    keys: 'L',
    run: () => {
      engine.loop = !engine.loop;
      store.emit('play');
    },
  },
  zoomIn: { label: 'Zoom in', icon: 'zoomIn', keys: '+', run: () => store.setZoom(store.pps * 1.25) },
  zoomOut: { label: 'Zoom out', icon: 'zoomOut', keys: '−', run: () => store.setZoom(store.pps / 1.25) },
  zoomFit: {
    label: 'Fit timeline',
    icon: 'fit',
    keys: 'Shift+Z',
    run: () => dispatchEvent(new CustomEvent('timeline:fit')),
  },
  snapshot: {
    label: 'Save frame as PNG',
    icon: 'camera',
    run: async () => {
      if (!store.project.clips.length) return toast('Nothing to capture yet');
      const c = await engine.snapshot();
      c.toBlob((b) => b && downloadBlob(b, `${store.project.name} frame.png`), 'image/png');
    },
  },
  muteClip: {
    label: 'Mute clip',
    icon: 'volumeX',
    run: () => {
      const ids = store.selectedClips.filter((c) => c.type === 'video' || c.type === 'audio').map((c) => c.id);
      if (!ids.length) return;
      const muted = !store.clip(ids[0])!.muted;
      A.updateClips(ids, (c) => (c.muted = muted));
    },
  },
  selectAll: {
    label: 'Select all',
    icon: 'grid',
    keys: 'Ctrl+A',
    run: () => store.select({ kind: 'clips', ids: store.project.clips.map((c) => c.id) }),
  },
};

export function runCommand(name: string): void {
  const c = commands[name];
  if (!c) return;
  if (c.enabled && !c.enabled()) return;
  c.run();
}
