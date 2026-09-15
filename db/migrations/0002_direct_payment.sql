-- Pay for an order directly with MonCash or NatCash, without topping up first.
--
-- A payment request can now belong to an order. When its SMS arrives, the
-- amount is credited to the wallet and immediately spent on that order, so
-- money still flows through the one audited ledger and a customer whose order
-- cannot be charged keeps the funds rather than losing them.

PRAGMA foreign_keys = ON;

-- What this payment is for: topping up the wallet, or settling one order.
ALTER TABLE topup_requests
  ADD COLUMN purpose TEXT NOT NULL DEFAULT 'topup'
    CHECK (purpose IN ('topup', 'order'));

ALTER TABLE topup_requests
  ADD COLUMN order_id TEXT REFERENCES orders (id);

CREATE INDEX idx_topup_order ON topup_requests (order_id);

-- An order awaiting its mobile-money payment needs somewhere to record which
-- provider the customer chose, so the account page can show the instructions
-- again if they close the tab.
ALTER TABLE orders
  ADD COLUMN payment_method TEXT
    CHECK (payment_method IN ('wallet', 'moncash', 'natcash'));
