// Central state: project, media library, selection, playhead, undo/redo.
import type { ChangeReason, Clip, MediaItem, Project, Selection, Track } from './types';
import { clipsOnTrack, newProject, normalize, projectDuration } from './model';
import { clamp } from './utils';

type Listener = (reason: ChangeReason) => void;

const HISTORY_LIMIT = 200;

class Store {
  project: Project = newProject();
  media = new Map<string, MediaItem>();
  selection: Selection = { kind: 'none' };
  time = 0;
  pps = 60; // timeline pixels per second
  playing = false;

  private undoStack: string[] = [];
  private redoStack: string[] = [];
  private lastKey = '';
  private lastAt = 0;
  private listeners = new Set<Listener>();

  on(fn: Listener): () => void {
    this.listeners.add(fn);
    return () => this.listeners.delete(fn);
  }

  emit(reason: ChangeReason): void {
    for (const l of this.listeners) l(reason);
  }

  /**
   * Apply a mutation to the project with undo support.
   * Calls sharing a `key` within 800ms collapse into one undo step (sliders, drags).
   */
  commit(fn: (p: Project) => void, key = '', prefer: string[] = []): void {
    const now = performance.now();
    const coalesce = key && key === this.lastKey && now - this.lastAt < 800;
    if (!coalesce) {
      this.undoStack.push(JSON.stringify(this.project));
      if (this.undoStack.length > HISTORY_LIMIT) this.undoStack.shift();
      this.redoStack = [];
    }
    this.lastKey = key;
    this.lastAt = now;
    fn(this.project);
    normalize(this.project, new Set(prefer));
    this.project.updated = Date.now();
    this.pruneSelection();
    this.emit('project');
  }

  /** Break the coalescing chain (e.g. on pointerup). */
  seal(): void {
    this.lastKey = '';
  }

  get canUndo(): boolean {
    return this.undoStack.length > 0;
  }
  get canRedo(): boolean {
    return this.redoStack.length > 0;
  }

  undo(): void {
    const prev = this.undoStack.pop();
    if (!prev) return;
    this.redoStack.push(JSON.stringify(this.project));
    this.project = JSON.parse(prev) as Project;
    this.lastKey = '';
    this.pruneSelection();
    this.emit('project');
  }

  redo(): void {
    const next = this.redoStack.pop();
    if (!next) return;
    this.undoStack.push(JSON.stringify(this.project));
    this.project = JSON.parse(next) as Project;
    this.lastKey = '';
    this.pruneSelection();
    this.emit('project');
  }

  load(project: Project, media: MediaItem[]): void {
    for (const m of this.media.values()) URL.revokeObjectURL(m.url);
    this.project = project;
    normalize(this.project);
    this.media = new Map(media.map((m) => [m.id, m]));
    this.undoStack = [];
    this.redoStack = [];
    this.selection = { kind: 'none' };
    this.time = 0;
    this.emit('load');
    this.emit('media');
    this.emit('project');
    this.emit('selection');
    this.emit('time');
  }

  addMedia(m: MediaItem): void {
    this.media.set(m.id, m);
    this.emit('media');
  }

  removeMedia(id: string): void {
    const m = this.media.get(id);
    if (!m) return;
    this.media.delete(id);
    URL.revokeObjectURL(m.url);
    this.emit('media');
  }

  // ---- queries -------------------------------------------------------------

  get duration(): number {
    return projectDuration(this.project);
  }

  clip(id: string | undefined): Clip | undefined {
    return id ? this.project.clips.find((c) => c.id === id) : undefined;
  }

  track(id: string | undefined): Track | undefined {
    return id ? this.project.tracks.find((t) => t.id === id) : undefined;
  }

  clipsOn(trackId: string): Clip[] {
    return clipsOnTrack(this.project, trackId);
  }

  get selectedClips(): Clip[] {
    if (this.selection.kind !== 'clips') return [];
    return this.selection.ids.map((id) => this.clip(id)).filter((c): c is Clip => !!c);
  }

  get selectedClip(): Clip | undefined {
    const s = this.selectedClips;
    return s.length === 1 ? s[0] : undefined;
  }

  // ---- selection & time ----------------------------------------------------

  select(sel: Selection): void {
    this.selection = sel;
    this.emit('selection');
  }

  selectClip(id: string, additive = false): void {
    if (additive && this.selection.kind === 'clips') {
      const ids = this.selection.ids.includes(id)
        ? this.selection.ids.filter((x) => x !== id)
        : [...this.selection.ids, id];
      this.select(ids.length ? { kind: 'clips', ids } : { kind: 'none' });
    } else {
      this.select({ kind: 'clips', ids: [id] });
    }
  }

  private pruneSelection(): void {
    const s = this.selection;
    if (s.kind === 'clips') {
      const ids = s.ids.filter((id) => this.clip(id));
      if (ids.length !== s.ids.length) this.selection = ids.length ? { kind: 'clips', ids } : { kind: 'none' };
    } else if (s.kind === 'transition') {
      if (!this.clip(s.id)?.transition) this.selection = { kind: 'none' };
    }
  }

  setTime(t: number): void {
    const max = Math.max(this.duration, 0);
    this.time = clamp(t, 0, max);
    this.emit('time');
  }

  setZoom(pps: number): void {
    this.pps = clamp(pps, 4, 600);
    this.emit('zoom');
  }
}

export const store = new Store();
