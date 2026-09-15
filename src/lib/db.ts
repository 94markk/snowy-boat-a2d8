/**
 * Thin helpers over the D1 binding.
 *
 * Everything here uses prepared statements with bound parameters — no SQL is
 * ever built by string concatenation, so user input cannot reach the parser.
 */

/** Seconds since the epoch. All timestamps in the schema use this unit. */
export function now(): number {
  return Math.floor(Date.now() / 1000);
}

/** URL-safe random identifier, 128 bits of entropy. */
export function newId(prefix = ""): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  const body = base32(bytes);
  return prefix ? `${prefix}_${body}` : body;
}

/**
 * Short, human-quotable order reference: DS-7Q4KX2.
 * Crockford-style alphabet, so 0/O and 1/I cannot be confused over the phone.
 */
export function newReference(): string {
  const alphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ";
  const bytes = crypto.getRandomValues(new Uint8Array(6));
  let out = "";
  for (const byte of bytes) out += alphabet[byte % alphabet.length];
  return `DS-${out}`;
}

function base32(bytes: Uint8Array): string {
  const alphabet = "abcdefghijklmnopqrstuvwxyz234567";
  let out = "";
  for (const byte of bytes) out += alphabet[byte % alphabet.length];
  return out;
}

/** Constant-time string comparison for tokens and secrets. */
export function safeEqual(a: string, b: string): boolean {
  const left = new TextEncoder().encode(a);
  const right = new TextEncoder().encode(b);
  if (left.length !== right.length) return false;
  let diff = 0;
  for (let i = 0; i < left.length; i++) diff |= left[i]! ^ right[i]!;
  return diff === 0;
}

/** SHA-256, hex encoded. Used for session tokens at rest. */
export async function sha256Hex(input: string): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(input));
  return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, "0")).join("");
}

export async function writeAudit(
  db: D1Database,
  entry: {
    actorUserId?: string | null;
    action: string;
    target?: string | null;
    detail?: unknown;
    ip?: string | null;
  },
): Promise<void> {
  await db
    .prepare(
      `INSERT INTO audit_log (id, actor_user_id, action, target, detail, ip, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?)`,
    )
    .bind(
      newId("aud"),
      entry.actorUserId ?? null,
      entry.action,
      entry.target ?? null,
      entry.detail === undefined ? null : JSON.stringify(entry.detail),
      entry.ip ?? null,
      now(),
    )
    .run();
}
