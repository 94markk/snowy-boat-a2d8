/**
 * Browser-side cart.
 *
 * Stores variant ids, quantities and the customer's own inputs (player id,
 * delivery email) — never prices or product names. Those are read from the
 * catalog when rendering, and recomputed on the server when the order is
 * placed, so an edited localStorage entry cannot change what is charged.
 */

const STORAGE_KEY = "delica.cart.v2";
const CHANGE_EVENT = "delica:cart-change";

export interface CartLine {
  variantId: string;
  qty: number;
  /** Customer-supplied required fields, keyed by field name. */
  fields: Record<string, string>;
}

function isLine(value: unknown): value is CartLine {
  return (
    !!value &&
    typeof value === "object" &&
    typeof (value as CartLine).variantId === "string" &&
    Number.isFinite((value as CartLine).qty)
  );
}

export function readCart(): CartLine[] {
  if (typeof localStorage === "undefined") return [];
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    const parsed = raw ? JSON.parse(raw) : [];
    if (!Array.isArray(parsed)) return [];
    return parsed.filter(isLine).map((line) => ({
      variantId: line.variantId,
      qty: Math.max(1, Math.floor(line.qty)),
      fields:
        line.fields && typeof line.fields === "object"
          ? Object.fromEntries(
              Object.entries(line.fields)
                .filter(([, v]) => typeof v === "string")
                .map(([k, v]) => [k, String(v).slice(0, 200)]),
            )
          : {},
    }));
  } catch {
    return [];
  }
}

function write(lines: CartLine[]): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(lines.slice(0, 20)));
  } catch {
    // Private mode or quota exceeded — the cart just does not persist.
  }
  window.dispatchEvent(new CustomEvent(CHANGE_EVENT));
}

/**
 * Lines are keyed by variant *and* by the details entered, so two top-ups of
 * the same pack for two different player ids stay separate.
 */
function sameLine(a: CartLine, variantId: string, fields: Record<string, string>): boolean {
  if (a.variantId !== variantId) return false;
  const keys = new Set([...Object.keys(a.fields), ...Object.keys(fields)]);
  for (const key of keys) {
    if (a.fields[key] !== fields[key]) return false;
  }
  return true;
}

export function addToCart(variantId: string, fields: Record<string, string> = {}, qty = 1): void {
  const lines = readCart();
  const existing = lines.find((line) => sameLine(line, variantId, fields));
  if (existing) existing.qty = Math.min(10, existing.qty + qty);
  else lines.push({ variantId, qty: Math.min(10, Math.max(1, qty)), fields });
  write(lines);
}

export function setQty(index: number, qty: number): void {
  const lines = readCart();
  if (index < 0 || index >= lines.length) return;
  if (qty <= 0) lines.splice(index, 1);
  else lines[index]!.qty = Math.min(10, Math.floor(qty));
  write(lines);
}

export function removeAt(index: number): void {
  const lines = readCart();
  if (index < 0 || index >= lines.length) return;
  lines.splice(index, 1);
  write(lines);
}

export function clearCart(): void {
  write([]);
}

export function cartCount(): number {
  return readCart().reduce((sum, line) => sum + line.qty, 0);
}

/** Subscribe to cart changes, including from another tab. */
export function onCartChange(handler: () => void): () => void {
  const onLocal = () => handler();
  const onStorage = (event: StorageEvent) => {
    if (event.key === STORAGE_KEY) handler();
  };
  window.addEventListener(CHANGE_EVENT, onLocal);
  window.addEventListener("storage", onStorage);
  return () => {
    window.removeEventListener(CHANGE_EVENT, onLocal);
    window.removeEventListener("storage", onStorage);
  };
}
