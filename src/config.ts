/**
 * Site-wide configuration.
 *
 * Money is held everywhere as an integer number of centimes (1 gourde = 100).
 * The gourde is not subdivided in practice, so amounts are displayed without
 * decimals — but storing minor units keeps arithmetic exact if that changes.
 */

export const SITE = {
  url: "https://delicastoreha.com",
  name: "Delicat Store Haiti",
  shortName: "Delicat Store",
  legalName: "Delicat Group Haiti",
  email: "support@delicastoreha.com",
  phone: "+50900000000",
  whatsapp: "50900000000",
} as const;

export const CURRENCY = {
  code: "HTG",
  /** Displayed before the amount, as on the current site: G144. */
  symbol: "G",
  minorUnits: 100,
  /** Gourde amounts are shown as whole numbers. */
  fractionDigits: 0,
} as const;

/** Wallet top-up bounds, in centimes. Anything outside is rejected server-side. */
export const WALLET = {
  minTopUpCentimes: 50_00,
  maxTopUpCentimes: 100_000_00,
  /** A pending top-up expires if no matching SMS arrives within this window. */
  topUpTtlMinutes: 60,
} as const;

/**
 * Mobile-money accounts customers send to. These are shown to the customer on
 * the top-up screen, so they must match the real merchant numbers.
 */
export const PAYMENT_ACCOUNTS = {
  moncash: { label: "MonCash", number: "+509 0000 0000" },
  natcash: { label: "NatCash", number: "+509 0000 0000" },
} as const;

export type PaymentProvider = keyof typeof PAYMENT_ACCOUNTS;

/** Hard caps on a single order, so a hostile client cannot submit absurd input. */
export const MAX_QTY_PER_ITEM = 10;
export const MAX_ORDER_LINES = 20;
