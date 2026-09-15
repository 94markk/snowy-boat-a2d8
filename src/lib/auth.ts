/**
 * Registration, login and sessions.
 *
 * Passwords are hashed with PBKDF2-SHA256 through Web Crypto, which is what
 * Cloudflare Workers offers natively. Argon2id would be preferable, but every
 * available JS implementation is either a large WASM dependency or too slow
 * inside a Worker's CPU budget; a high PBKDF2 iteration count is the honest
 * trade-off here, and the format string below lets the cost be raised later
 * without invalidating existing hashes.
 */

import { newId, now, safeEqual, sha256Hex } from "./db";
import type { Locale } from "../i18n/ui";

const PBKDF2_ITERATIONS = 210_000;
const SALT_BYTES = 16;
const KEY_BITS = 256;

export const SESSION_COOKIE = "__Host-ds_session";
const SESSION_TTL_SECONDS = 60 * 60 * 24 * 14;
/** Sessions older than this are refreshed, to keep last_seen_at meaningful. */
const SESSION_TOUCH_INTERVAL = 60 * 15;

const MAX_FAILED_LOGINS = 8;
const LOCKOUT_SECONDS = 60 * 15;

export interface SessionUser {
  id: string;
  email: string;
  role: "customer" | "staff" | "admin";
  status: "active" | "suspended";
  balanceCentimes: number;
  locale: Locale;
}

/* -------------------------------------------------------------------------- */
/* Password hashing                                                            */
/* -------------------------------------------------------------------------- */

function toBase64(bytes: Uint8Array): string {
  let binary = "";
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary);
}

function fromBase64(value: string): Uint8Array {
  const binary = atob(value);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
  return bytes;
}

async function derive(password: string, salt: Uint8Array, iterations: number): Promise<Uint8Array> {
  const key = await crypto.subtle.importKey(
    "raw",
    new TextEncoder().encode(password),
    "PBKDF2",
    false,
    ["deriveBits"],
  );
  const bits = await crypto.subtle.deriveBits(
    { name: "PBKDF2", hash: "SHA-256", salt, iterations },
    key,
    KEY_BITS,
  );
  return new Uint8Array(bits);
}

/** Produces `pbkdf2-sha256$iterations$salt$hash`. */
export async function hashPassword(password: string): Promise<string> {
  const salt = crypto.getRandomValues(new Uint8Array(SALT_BYTES));
  const hash = await derive(password, salt, PBKDF2_ITERATIONS);
  return `pbkdf2-sha256$${PBKDF2_ITERATIONS}$${toBase64(salt)}$${toBase64(hash)}`;
}

/**
 * Verify a password against a stored hash. Returns false rather than throwing
 * on a malformed hash, so a corrupt row cannot be probed for information.
 */
export async function verifyPassword(password: string, stored: string): Promise<boolean> {
  const parts = stored.split("$");
  if (parts.length !== 4 || parts[0] !== "pbkdf2-sha256") return false;

  const iterations = Number(parts[1]);
  if (!Number.isInteger(iterations) || iterations < 1_000 || iterations > 1_000_000) return false;

  try {
    const salt = fromBase64(parts[2]!);
    const expected = derive(password, salt, iterations);
    return safeEqual(toBase64(await expected), parts[3]!);
  } catch {
    return false;
  }
}

/* -------------------------------------------------------------------------- */
/* Sessions                                                                    */
/* -------------------------------------------------------------------------- */

/** Creates a session and returns the raw token to put in the cookie. */
export async function createSession(
  db: D1Database,
  userId: string,
  meta: { ip?: string | null; userAgent?: string | null } = {},
): Promise<{ token: string; expiresAt: number }> {
  const token = newId();
  const tokenHash = await sha256Hex(token);
  const createdAt = now();
  const expiresAt = createdAt + SESSION_TTL_SECONDS;

  await db
    .prepare(
      `INSERT INTO sessions (id, token_hash, user_id, created_at, expires_at, last_seen_at, ip, user_agent)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
    )
    .bind(
      newId("ses"),
      tokenHash,
      userId,
      createdAt,
      expiresAt,
      createdAt,
      meta.ip ?? null,
      (meta.userAgent ?? "").slice(0, 300) || null,
    )
    .run();

  return { token, expiresAt };
}

/** Resolves a cookie token to its user, or null. Expired rows are cleaned up. */
export async function resolveSession(
  db: D1Database,
  token: string | undefined,
): Promise<SessionUser | null> {
  if (!token) return null;

  const tokenHash = await sha256Hex(token);
  const row = await db
    .prepare(
      `SELECT s.id AS session_id, s.expires_at, s.last_seen_at,
              u.id, u.email, u.role, u.status, u.balance_centimes, u.locale
         FROM sessions s
         JOIN users u ON u.id = s.user_id
        WHERE s.token_hash = ?`,
    )
    .bind(tokenHash)
    .first<any>();

  if (!row) return null;

  const current = now();
  if (row.expires_at <= current) {
    await db.prepare(`DELETE FROM sessions WHERE id = ?`).bind(row.session_id).run();
    return null;
  }

  // A suspended account keeps its session row but is treated as signed out.
  if (row.status !== "active") return null;

  if (current - row.last_seen_at > SESSION_TOUCH_INTERVAL) {
    await db
      .prepare(`UPDATE sessions SET last_seen_at = ? WHERE id = ?`)
      .bind(current, row.session_id)
      .run();
  }

  return {
    id: row.id,
    email: row.email,
    role: row.role,
    status: row.status,
    balanceCentimes: row.balance_centimes,
    locale: row.locale,
  };
}

export async function destroySession(db: D1Database, token: string | undefined): Promise<void> {
  if (!token) return;
  await db.prepare(`DELETE FROM sessions WHERE token_hash = ?`).bind(await sha256Hex(token)).run();
}

/** Signs every other device out — used after a password change. */
export async function destroyAllSessions(db: D1Database, userId: string): Promise<void> {
  await db.prepare(`DELETE FROM sessions WHERE user_id = ?`).bind(userId).run();
}

/**
 * `__Host-` prefix forces Secure, path=/ and no Domain, which stops a
 * subdomain from ever setting or reading this cookie.
 */
export function sessionCookie(token: string, maxAgeSeconds = SESSION_TTL_SECONDS): string {
  return [
    `${SESSION_COOKIE}=${token}`,
    "Path=/",
    "HttpOnly",
    "Secure",
    "SameSite=Lax",
    `Max-Age=${maxAgeSeconds}`,
  ].join("; ");
}

export function clearedSessionCookie(): string {
  return `${SESSION_COOKIE}=; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=0`;
}

/* -------------------------------------------------------------------------- */
/* Login throttling                                                            */
/* -------------------------------------------------------------------------- */

export function isLockedOut(row: { locked_until: number | null }): boolean {
  return row.locked_until !== null && row.locked_until > now();
}

export async function recordFailedLogin(db: D1Database, userId: string): Promise<void> {
  const current = now();
  await db
    .prepare(
      `UPDATE users
          SET failed_logins = failed_logins + 1,
              locked_until = CASE WHEN failed_logins + 1 >= ? THEN ? ELSE locked_until END,
              updated_at = ?
        WHERE id = ?`,
    )
    .bind(MAX_FAILED_LOGINS, current + LOCKOUT_SECONDS, current, userId)
    .run();
}

export async function clearFailedLogins(db: D1Database, userId: string): Promise<void> {
  await db
    .prepare(`UPDATE users SET failed_logins = 0, locked_until = NULL, updated_at = ? WHERE id = ?`)
    .bind(now(), userId)
    .run();
}

/* -------------------------------------------------------------------------- */
/* Input rules                                                                 */
/* -------------------------------------------------------------------------- */

const EMAIL_RE = /^[^@\s]{1,64}@[^@\s.]+(\.[^@\s.]+)+$/;

export function normalizeEmail(email: string): string {
  return email.trim().toLowerCase();
}

export function isValidEmail(email: string): boolean {
  return email.length <= 120 && EMAIL_RE.test(email);
}

/**
 * Deliberately length-first rather than a composition rulebook: a long
 * passphrase beats four character classes, and complexity rules push people
 * toward predictable substitutions.
 */
export function passwordProblem(password: string): "too_short" | "too_long" | null {
  if (password.length < 10) return "too_short";
  if (password.length > 200) return "too_long";
  return null;
}

/** Haitian mobile numbers: 8 digits, optionally prefixed with 509. */
export function normalizeMsisdn(input: string): string | null {
  const digits = input.replace(/\D/g, "");
  const local = digits.startsWith("509") ? digits.slice(3) : digits;
  return /^[0-9]{8}$/.test(local) ? local : null;
}
