/**
 * A minimal stand-in for the Cloudflare D1 binding, backed by node:sqlite.
 *
 * It implements just enough of the D1 surface for the tests: prepare/bind,
 * run/first/all, and a batch() that is genuinely transactional — which is the
 * property the wallet code depends on.
 */

import { DatabaseSync } from "node:sqlite";
import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";

class Statement {
  #db;
  #sql;
  #params;

  constructor(db, sql, params = []) {
    this.#db = db;
    this.#sql = sql;
    this.#params = params;
  }

  bind(...params) {
    return new Statement(this.#db, this.#sql, params);
  }

  #prepared() {
    return this.#db.prepare(this.#sql);
  }

  run() {
    const before = this.#db.prepare("SELECT total_changes() AS c").get().c;
    const info = this.#prepared().run(...this.#params);
    const after = this.#db.prepare("SELECT total_changes() AS c").get().c;
    return Promise.resolve({
      success: true,
      meta: { changes: after - before, last_row_id: Number(info.lastInsertRowid ?? 0) },
    });
  }

  first(column) {
    const row = this.#prepared().get(...this.#params);
    if (row === undefined) return Promise.resolve(null);
    return Promise.resolve(column ? row[column] : row);
  }

  all() {
    const results = this.#prepared().all(...this.#params);
    return Promise.resolve({ success: true, results, meta: { changes: 0 } });
  }
}

class FakeD1 {
  #db;

  constructor(db) {
    this.#db = db;
  }

  prepare(sql) {
    return new Statement(this.#db, sql);
  }

  /** All-or-nothing, like the real binding. */
  async batch(statements) {
    this.#db.exec("BEGIN");
    try {
      const results = [];
      for (const statement of statements) results.push(await statement.run());
      this.#db.exec("COMMIT");
      return results;
    } catch (error) {
      this.#db.exec("ROLLBACK");
      throw error;
    }
  }

  get raw() {
    return this.#db;
  }
}

/** Applies every migration in order, so tests always run the real schema. */
export function createTestDb(migrationsDir = "db/migrations") {
  const db = new DatabaseSync(":memory:");
  for (const file of readdirSync(migrationsDir).filter((f) => f.endsWith(".sql")).sort()) {
    db.exec(readFileSync(join(migrationsDir, file), "utf8"));
  }
  return new FakeD1(db);
}

/** Inserts a user and returns its id. */
export async function seedUser(d1, { id = "usr_test", email = "a@b.co", balance = 0 } = {}) {
  await d1
    .prepare(
      `INSERT INTO users (id, email, email_normalized, password_hash, balance_centimes, created_at, updated_at)
       VALUES (?, ?, ?, 'x', ?, 0, 0)`,
    )
    .bind(id, email, email.toLowerCase(), balance)
    .run();
  return id;
}
