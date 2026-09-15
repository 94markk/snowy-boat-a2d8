-- Delicat Store Haiti — initial schema (Cloudflare D1 / SQLite).
--
-- Money is stored everywhere as INTEGER centimes of gourde. No floats touch a
-- balance at any point.

PRAGMA foreign_keys = ON;

-- ---------------------------------------------------------------------------
-- Accounts
-- ---------------------------------------------------------------------------

CREATE TABLE users (
  id                TEXT PRIMARY KEY,
  email             TEXT NOT NULL,
  -- Lower-cased email, so uniqueness is case-insensitive without relying on
  -- collation behaviour that differs between SQLite builds.
  email_normalized  TEXT NOT NULL UNIQUE,
  phone             TEXT,
  -- PBKDF2-SHA256, stored as algorithm$iterations$salt$hash.
  password_hash     TEXT NOT NULL,
  role              TEXT NOT NULL DEFAULT 'customer' CHECK (role IN ('customer', 'staff', 'admin')),
  status            TEXT NOT NULL DEFAULT 'active'   CHECK (status IN ('active', 'suspended')),
  -- Cached sum of wallet_entries.amount_centimes for this user. Updated only
  -- in the same atomic batch as the ledger row, and never allowed below zero.
  balance_centimes  INTEGER NOT NULL DEFAULT 0 CHECK (balance_centimes >= 0),
  locale            TEXT NOT NULL DEFAULT 'fr' CHECK (locale IN ('fr', 'en', 'ht')),
  failed_logins     INTEGER NOT NULL DEFAULT 0,
  locked_until      INTEGER,
  created_at        INTEGER NOT NULL,
  updated_at        INTEGER NOT NULL
);

CREATE INDEX idx_users_phone ON users (phone);

-- Sessions hold only a SHA-256 of the cookie value, so a leaked database does
-- not hand out live sessions.
CREATE TABLE sessions (
  id            TEXT PRIMARY KEY,
  token_hash    TEXT NOT NULL UNIQUE,
  user_id       TEXT NOT NULL REFERENCES users (id) ON DELETE CASCADE,
  created_at    INTEGER NOT NULL,
  expires_at    INTEGER NOT NULL,
  last_seen_at  INTEGER NOT NULL,
  ip            TEXT,
  user_agent    TEXT
);

CREATE INDEX idx_sessions_user ON sessions (user_id);
CREATE INDEX idx_sessions_expiry ON sessions (expires_at);

-- ---------------------------------------------------------------------------
-- Wallet
-- ---------------------------------------------------------------------------

-- Append-only ledger. Rows are never updated or deleted; a correction is a new
-- row with the opposite sign. The balance is always reconstructible from here.
CREATE TABLE wallet_entries (
  id               TEXT PRIMARY KEY,
  user_id          TEXT NOT NULL REFERENCES users (id) ON DELETE CASCADE,
  -- Positive credits the customer, negative debits them.
  amount_centimes  INTEGER NOT NULL CHECK (amount_centimes <> 0),
  kind             TEXT NOT NULL CHECK (kind IN ('topup', 'purchase', 'refund', 'adjustment')),
  -- Free-text pointer to whatever caused this: an order id, a top-up id, a
  -- staff note.
  reference        TEXT,
  -- Makes crediting a payment safe to retry: a repeated webhook delivery hits
  -- this constraint instead of crediting twice.
  idempotency_key  TEXT NOT NULL UNIQUE,
  -- Running balance immediately after this entry, for auditing.
  balance_after    INTEGER NOT NULL,
  created_by       TEXT REFERENCES users (id),
  created_at       INTEGER NOT NULL
);

CREATE INDEX idx_wallet_user_time ON wallet_entries (user_id, created_at DESC);

-- ---------------------------------------------------------------------------
-- Mobile-money top-ups
-- ---------------------------------------------------------------------------

-- Every SMS the forwarder delivers, matched or not. Kept as the audit trail for
-- money entering the system.
CREATE TABLE sms_messages (
  id               TEXT PRIMARY KEY,
  provider         TEXT NOT NULL CHECK (provider IN ('moncash', 'natcash', 'unknown')),
  -- Identifier supplied by the forwarding device. Unique, so a replayed or
  -- retried delivery is ignored rather than credited twice.
  external_id      TEXT NOT NULL UNIQUE,
  device_id        TEXT NOT NULL,
  sender           TEXT,
  body             TEXT NOT NULL,
  parsed_amount_centimes INTEGER,
  parsed_msisdn    TEXT,
  parsed_txn_id    TEXT,
  status           TEXT NOT NULL DEFAULT 'received'
                     CHECK (status IN ('received', 'parsed', 'matched', 'unmatched', 'ignored')),
  received_at      INTEGER NOT NULL,
  processed_at     INTEGER
);

CREATE INDEX idx_sms_status ON sms_messages (status, received_at DESC);
CREATE UNIQUE INDEX idx_sms_txn ON sms_messages (provider, parsed_txn_id)
  WHERE parsed_txn_id IS NOT NULL;

-- A customer declares "I am about to send G500 from 509XXXXXXXX via MonCash".
-- The SMS that arrives later is matched against this.
CREATE TABLE topup_requests (
  id               TEXT PRIMARY KEY,
  user_id          TEXT NOT NULL REFERENCES users (id) ON DELETE CASCADE,
  provider         TEXT NOT NULL CHECK (provider IN ('moncash', 'natcash')),
  amount_centimes  INTEGER NOT NULL CHECK (amount_centimes > 0),
  -- Normalised to digits only, no country prefix, so SMS and form agree.
  payer_msisdn     TEXT NOT NULL,
  status           TEXT NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending', 'matched', 'expired', 'cancelled')),
  sms_id           TEXT REFERENCES sms_messages (id),
  created_at       INTEGER NOT NULL,
  expires_at       INTEGER NOT NULL,
  matched_at       INTEGER
);

-- The matcher looks up pending requests by exactly this shape.
CREATE INDEX idx_topup_match
  ON topup_requests (provider, payer_msisdn, amount_centimes, status);
CREATE INDEX idx_topup_user ON topup_requests (user_id, created_at DESC);

-- ---------------------------------------------------------------------------
-- Orders
-- ---------------------------------------------------------------------------

CREATE TABLE orders (
  id               TEXT PRIMARY KEY,
  -- Short human-quotable code shown to the customer, e.g. DS-7Q4KX2.
  reference        TEXT NOT NULL UNIQUE,
  user_id          TEXT NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
  status           TEXT NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending', 'paid', 'fulfilling', 'delivered', 'failed', 'refunded')),
  total_centimes   INTEGER NOT NULL CHECK (total_centimes >= 0),
  locale           TEXT NOT NULL DEFAULT 'fr',
  failure_reason   TEXT,
  created_at       INTEGER NOT NULL,
  updated_at       INTEGER NOT NULL
);

CREATE INDEX idx_orders_user ON orders (user_id, created_at DESC);
CREATE INDEX idx_orders_status ON orders (status, created_at DESC);

CREATE TABLE order_lines (
  id                   TEXT PRIMARY KEY,
  order_id             TEXT NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
  product_id           TEXT NOT NULL,
  variant_id           TEXT NOT NULL,
  -- Denormalised so an order still reads correctly after a catalog change.
  label                TEXT NOT NULL,
  qty                  INTEGER NOT NULL CHECK (qty > 0),
  unit_price_centimes  INTEGER NOT NULL CHECK (unit_price_centimes >= 0),
  -- JSON of the customer-supplied required fields (player id, email…).
  fields_json          TEXT NOT NULL DEFAULT '{}',
  fulfilment_status    TEXT NOT NULL DEFAULT 'pending'
                         CHECK (fulfilment_status IN ('pending', 'sent', 'delivered', 'failed')),
  supplier             TEXT,
  supplier_ref         TEXT,
  -- What the customer receives: a code, an activation note. Readable by its
  -- owner and by staff only.
  delivered_payload    TEXT,
  delivered_at         INTEGER,
  attempts             INTEGER NOT NULL DEFAULT 0,
  last_error           TEXT
);

CREATE INDEX idx_order_lines_order ON order_lines (order_id);
CREATE INDEX idx_order_lines_pending ON order_lines (fulfilment_status);

-- ---------------------------------------------------------------------------
-- Favourites
-- ---------------------------------------------------------------------------

CREATE TABLE favorites (
  user_id     TEXT NOT NULL REFERENCES users (id) ON DELETE CASCADE,
  product_id  TEXT NOT NULL,
  created_at  INTEGER NOT NULL,
  PRIMARY KEY (user_id, product_id)
);

-- ---------------------------------------------------------------------------
-- Audit
-- ---------------------------------------------------------------------------

-- Anything that moves money or changes an account lands here.
CREATE TABLE audit_log (
  id             TEXT PRIMARY KEY,
  actor_user_id  TEXT REFERENCES users (id),
  action         TEXT NOT NULL,
  target         TEXT,
  detail         TEXT,
  ip             TEXT,
  created_at     INTEGER NOT NULL
);

CREATE INDEX idx_audit_time ON audit_log (created_at DESC);
CREATE INDEX idx_audit_actor ON audit_log (actor_user_id, created_at DESC);
