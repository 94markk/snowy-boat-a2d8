/**
 * Favourites, kept in the browser.
 *
 * Signed-in customers could have these on the server, but keeping them local
 * means the heart works instantly and without an account — and nothing here is
 * sensitive enough to need a round trip.
 */

const STORAGE_KEY = "delica.favorites.v1";

function read(): string[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed.filter((id) => typeof id === "string") : [];
  } catch {
    return [];
  }
}

function write(ids: string[]): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(ids.slice(0, 200)));
  } catch {
    // Storage unavailable; favourites simply do not persist.
  }
}

export function isFavorite(id: string): boolean {
  return read().includes(id);
}

/** Flips the favourite state and returns the new one. */
export function toggleFavorite(id: string): boolean {
  const ids = read();
  const index = ids.indexOf(id);
  if (index === -1) {
    ids.push(id);
    write(ids);
    return true;
  }
  ids.splice(index, 1);
  write(ids);
  return false;
}
