// IndexedDB persistence: projects (JSON) and media (blobs + metadata).
import type { MediaItem, Project } from './types';

const DB_NAME = 'cutline-editor';
const DB_VERSION = 1;
const LAST_KEY = 'cutline:last-project';

export interface MediaRecord extends Omit<MediaItem, 'url'> {
  projectId: string;
  blob: Blob;
}

export interface ProjectRecord extends Project {
  thumb?: string;
  durationSec?: number;
}

let dbp: Promise<IDBDatabase> | null = null;

function open(): Promise<IDBDatabase> {
  if (dbp) return dbp;
  dbp = new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains('projects')) db.createObjectStore('projects', { keyPath: 'id' });
      if (!db.objectStoreNames.contains('media')) {
        const s = db.createObjectStore('media', { keyPath: 'id' });
        s.createIndex('projectId', 'projectId');
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
  return dbp;
}

function wrap<T>(req: IDBRequest<T>): Promise<T> {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function tx(storeName: string, mode: IDBTransactionMode = 'readonly'): Promise<IDBObjectStore> {
  const db = await open();
  return db.transaction(storeName, mode).objectStore(storeName);
}

export async function saveProject(p: Project, extra: { thumb?: string; durationSec?: number } = {}): Promise<void> {
  const rec: ProjectRecord = { ...JSON.parse(JSON.stringify(p)), ...extra };
  await wrap((await tx('projects', 'readwrite')).put(rec));
  try {
    localStorage.setItem(LAST_KEY, p.id);
  } catch {
    /* storage unavailable */
  }
}

export async function loadProject(id: string): Promise<ProjectRecord | undefined> {
  return wrap((await tx('projects')).get(id)) as Promise<ProjectRecord | undefined>;
}

export async function listProjects(): Promise<ProjectRecord[]> {
  const all = (await wrap((await tx('projects')).getAll())) as ProjectRecord[];
  return all.sort((a, b) => b.updated - a.updated);
}

export async function deleteProject(id: string): Promise<void> {
  const media = await loadMediaRecords(id);
  const ms = await tx('media', 'readwrite');
  await Promise.all(media.map((m) => wrap(ms.delete(m.id))));
  await wrap((await tx('projects', 'readwrite')).delete(id));
}

export async function saveMedia(projectId: string, m: MediaItem, blob: Blob): Promise<void> {
  const { url: _url, ...meta } = m;
  const rec: MediaRecord = { ...meta, projectId, blob };
  await wrap((await tx('media', 'readwrite')).put(rec));
}

export async function deleteMedia(id: string): Promise<void> {
  await wrap((await tx('media', 'readwrite')).delete(id));
}

async function loadMediaRecords(projectId: string): Promise<MediaRecord[]> {
  const s = await tx('media');
  return wrap(s.index('projectId').getAll(projectId)) as Promise<MediaRecord[]>;
}

export async function loadMedia(projectId: string): Promise<MediaItem[]> {
  const recs = await loadMediaRecords(projectId);
  return recs.map(({ blob, projectId: _p, ...meta }) => ({ ...meta, url: URL.createObjectURL(blob) }));
}

export async function getMediaBlob(id: string): Promise<Blob | undefined> {
  const rec = (await wrap((await tx('media')).get(id))) as MediaRecord | undefined;
  return rec?.blob;
}

export function lastProjectId(): string | null {
  try {
    return localStorage.getItem(LAST_KEY);
  } catch {
    return null;
  }
}
