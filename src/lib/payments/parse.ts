/**
 * Parsing of MonCash and NatCash confirmation SMS.
 *
 * IMPORTANT: the patterns below are written against the wording these services
 * are commonly seen to use, in Creole and French. Neither operator publishes a
 * stable format, and wording changes without notice. Before going live, collect
 * a handful of real messages from the merchant handset and check them with
 * `npm test` — `tests/sms-parse.test.js` is where to add them.
 *
 * A message that does not parse is never dropped: it is stored with status
 * 'unmatched' for a human to look at. Money is only ever credited from a
 * message this file understood completely.
 */

export type Provider = "moncash" | "natcash" | "unknown";

export interface ParsedSms {
  provider: Provider;
  /** Integer centimes of gourde. */
  amountCentimes: number | null;
  /** Payer's number, 8 local digits. */
  msisdn: string | null;
  /** Operator transaction id, used to deduplicate. */
  transactionId: string | null;
}

/**
 * Decide the provider from the SMS sender, falling back to the body. The
 * forwarder should send the sender as it appears on the handset.
 */
export function detectProvider(sender: string | null, body: string): Provider {
  const haystack = `${sender ?? ""} ${body}`.toLowerCase();
  if (haystack.includes("moncash") || haystack.includes("mon cash")) return "moncash";
  if (haystack.includes("natcash") || haystack.includes("nat cash")) return "natcash";
  return "unknown";
}

/**
 * Amounts appear as "500", "500.00", "1,500.00" or "1 500,00" depending on the
 * message. Returns centimes.
 */
function parseAmount(body: string): number | null {
  const match = body.match(
    /(?:^|[^\d])((?:\d{1,3}(?:[ ,]\d{3})+|\d+)(?:[.,]\d{1,2})?)\s*(?:HTG|GDES?|GOUD(?:ES?)?|G\b)/i,
  );
  if (!match) return null;

  const raw = match[1]!;
  // Strip thousands separators, then normalise the decimal separator.
  const cleaned = raw.replace(/[ ,](?=\d{3}\b)/g, "").replace(",", ".");
  const value = Number(cleaned);
  if (!Number.isFinite(value) || value <= 0) return null;

  const centimes = Math.round(value * 100);
  return Number.isSafeInteger(centimes) ? centimes : null;
}

/**
 * Find the payer's number. Prefers a number that follows a "from" marker, so a
 * merchant number quoted elsewhere in the text is not mistaken for the payer.
 */
function parseMsisdn(body: string): string | null {
  const markers = [
    /(?:nan\s+men|de\s+la\s+part\s+de|from|de|soti\s+nan|men)\s+[^()\n]{0,60}?\(?\+?(?:509)?[\s-]?(\d{4}[\s-]?\d{4})\)?/i,
    /\(\+?(?:509)?[\s-]?(\d{4}[\s-]?\d{4})\)/,
    /\+?509[\s-]?(\d{4}[\s-]?\d{4})/,
  ];

  for (const pattern of markers) {
    const match = body.match(pattern);
    if (match) {
      const digits = match[1]!.replace(/\D/g, "");
      if (/^\d{8}$/.test(digits)) return digits;
    }
  }
  return null;
}

function parseTransactionId(body: string): string | null {
  const patterns = [
    /(?:transaction\s*(?:id|no|n[°o])|id\s*tranzaksyon|tranzaksyon|r[ée]f(?:[ée]rence)?|ref)\s*[:#.]?\s*([A-Za-z0-9-]{4,40})/i,
    /\b([A-Z0-9]{8,20})\b(?=\s*$)/,
  ];

  for (const pattern of patterns) {
    const match = body.match(pattern);
    if (match?.[1]) return match[1].toUpperCase();
  }
  return null;
}

/**
 * Only messages that describe money *arriving* should ever credit a wallet.
 * A balance notification or an outgoing transfer must not.
 */
export function describesIncomingPayment(body: string): boolean {
  const incoming = [
    /\bou\s+resevwa\b/i,
    /\bw\s+resevwa\b/i,
    /\bresevwa\b/i,
    /\bvous\s+avez\s+re[çc]u\b/i,
    /\byou\s+(?:have\s+)?received\b/i,
    /\bre[çc]u\s+de\b/i,
  ];
  const outgoing = [
    /\bou\s+voye\b/i,
    /\bvous\s+avez\s+envoy[ée]\b/i,
    /\byou\s+(?:have\s+)?sent\b/i,
    /\btransfert\s+effectu[ée]\b/i,
    /\bretrait\b/i,
  ];

  if (outgoing.some((pattern) => pattern.test(body))) return false;
  return incoming.some((pattern) => pattern.test(body));
}

export function parseSms(sender: string | null, body: string): ParsedSms {
  return {
    provider: detectProvider(sender, body),
    amountCentimes: parseAmount(body),
    msisdn: parseMsisdn(body),
    transactionId: parseTransactionId(body),
  };
}

/** A message is only actionable when every field needed to match it is present. */
export function isActionable(parsed: ParsedSms, body: string): boolean {
  return (
    parsed.provider !== "unknown" &&
    parsed.amountCentimes !== null &&
    parsed.msisdn !== null &&
    describesIncomingPayment(body)
  );
}
