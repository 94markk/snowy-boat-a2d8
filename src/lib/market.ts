import type { Candle } from "./types.ts";

/**
 * Market data providers.
 *
 * Both providers are public, keyless endpoints, which keeps deployment simple
 * but also means they are rate limited and can change without notice. If you
 * later move to a paid feed, implement `fetchCandles` for it and register it
 * below -- nothing else in the codebase needs to change.
 */

export type ProviderId = "binance" | "yahoo";

export interface MarketRequest {
  symbol: string;
  interval: string;
  limit: number;
}

export interface MarketData {
  symbol: string;
  interval: string;
  provider: ProviderId;
  candles: Candle[];
}

export class MarketDataError extends Error {
  status: number;
  constructor(message: string, status = 502) {
    super(message);
    this.name = "MarketDataError";
    this.status = status;
  }
}

/** Approximate number of bars per year, used to annualise backtest metrics. */
export const BARS_PER_YEAR: Record<string, number> = {
  "1m": 365 * 24 * 60,
  "5m": 365 * 24 * 12,
  "15m": 365 * 24 * 4,
  "30m": 365 * 24 * 2,
  "1h": 365 * 24,
  "4h": 365 * 6,
  "1d": 365,
  "1wk": 52,
};

export function barsPerYear(interval: string): number {
  return BARS_PER_YEAR[interval] ?? 365;
}

const BINANCE_INTERVALS = new Set(["1m", "5m", "15m", "30m", "1h", "4h", "1d", "1w"]);
const YAHOO_INTERVALS = new Set(["1m", "5m", "15m", "30m", "1h", "1d", "1wk"]);

/**
 * Crypto pairs look like BTCUSDT / ETHUSDC. Anything else (AAPL, SPY, MSFT,
 * EURUSD=X) is routed to Yahoo.
 */
export function detectProvider(symbol: string): ProviderId {
  const upper = symbol.toUpperCase();
  const isCryptoPair = /^[A-Z]{2,10}(USDT|USDC|BUSD|BTC|ETH|FDUSD|TUSD)$/.test(upper);
  return isCryptoPair ? "binance" : "yahoo";
}

function toNumber(value: unknown): number {
  const n = typeof value === "number" ? value : Number(value);
  return Number.isFinite(n) ? n : NaN;
}

/** Discard malformed bars rather than letting NaN poison every indicator. */
function sanitise(candles: Candle[]): Candle[] {
  return candles.filter(
    (c) =>
      Number.isFinite(c.time) &&
      Number.isFinite(c.open) &&
      Number.isFinite(c.high) &&
      Number.isFinite(c.low) &&
      Number.isFinite(c.close) &&
      c.high >= c.low &&
      c.close > 0,
  );
}

async function fetchJson(url: string): Promise<unknown> {
  let response: Response;
  try {
    response = await fetch(url, {
      headers: {
        // Yahoo rejects requests without a browser-like agent.
        "User-Agent": "Mozilla/5.0 (compatible; trading-signal-bot/1.0)",
        Accept: "application/json",
      },
    });
  } catch (error) {
    throw new MarketDataError(`Network error contacting the data provider: ${(error as Error).message}`);
  }
  if (!response.ok) {
    throw new MarketDataError(
      `Data provider returned HTTP ${response.status}. The symbol may not exist, or you may be rate limited.`,
      response.status === 404 || response.status === 400 ? 404 : 502,
    );
  }
  return response.json();
}

async function fetchBinance(req: MarketRequest): Promise<Candle[]> {
  const interval = req.interval === "1wk" ? "1w" : req.interval;
  if (!BINANCE_INTERVALS.has(interval)) {
    throw new MarketDataError(
      `Binance does not support interval "${req.interval}". Try one of: ${[...BINANCE_INTERVALS].join(", ")}.`,
      400,
    );
  }
  const url =
    `https://api.binance.com/api/v3/klines?symbol=${encodeURIComponent(req.symbol.toUpperCase())}` +
    `&interval=${interval}&limit=${Math.min(1000, req.limit)}`;
  const raw = await fetchJson(url);
  if (!Array.isArray(raw)) throw new MarketDataError("Unexpected response shape from Binance.");
  const candles = raw.map((row) => {
    const k = row as unknown[];
    return {
      time: toNumber(k[0]),
      open: toNumber(k[1]),
      high: toNumber(k[2]),
      low: toNumber(k[3]),
      close: toNumber(k[4]),
      volume: toNumber(k[5]),
    } satisfies Candle;
  });
  return sanitise(candles);
}

/** Yahoo takes a range rather than a bar count, so approximate one from the other. */
function yahooRange(interval: string, limit: number): string {
  const perDay: Record<string, number> = {
    "1m": 390,
    "5m": 78,
    "15m": 26,
    "30m": 13,
    "1h": 7,
    "1d": 1,
    "1wk": 0.2,
  };
  const days = Math.ceil(limit / (perDay[interval] ?? 1)) * 1.6;
  if (interval === "1m") return "7d";
  if (days <= 30) return "1mo";
  if (days <= 90) return "3mo";
  if (days <= 180) return "6mo";
  if (days <= 365) return "1y";
  if (days <= 730) return "2y";
  if (days <= 1825) return "5y";
  return "10y";
}

async function fetchYahoo(req: MarketRequest): Promise<Candle[]> {
  if (!YAHOO_INTERVALS.has(req.interval)) {
    throw new MarketDataError(
      `Yahoo does not support interval "${req.interval}". Try one of: ${[...YAHOO_INTERVALS].join(", ")}.`,
      400,
    );
  }
  const url =
    `https://query1.finance.yahoo.com/v8/finance/chart/${encodeURIComponent(req.symbol.toUpperCase())}` +
    `?interval=${req.interval}&range=${yahooRange(req.interval, req.limit)}`;
  const raw = (await fetchJson(url)) as {
    chart?: {
      error?: { description?: string } | null;
      result?: {
        timestamp?: number[];
        indicators?: { quote?: { open?: (number | null)[]; high?: (number | null)[]; low?: (number | null)[]; close?: (number | null)[]; volume?: (number | null)[] }[] };
      }[];
    };
  };

  if (raw.chart?.error) {
    throw new MarketDataError(raw.chart.error.description ?? "Yahoo rejected the request.", 404);
  }
  const result = raw.chart?.result?.[0];
  const quote = result?.indicators?.quote?.[0];
  if (!result?.timestamp || !quote) {
    throw new MarketDataError(`No data returned for "${req.symbol}". Check the ticker.`, 404);
  }

  const candles = result.timestamp.map((ts, i) => ({
    time: ts * 1000,
    open: toNumber(quote.open?.[i]),
    high: toNumber(quote.high?.[i]),
    low: toNumber(quote.low?.[i]),
    close: toNumber(quote.close?.[i]),
    volume: toNumber(quote.volume?.[i] ?? 0),
  }));
  return sanitise(candles);
}

/**
 * Fetch OHLCV history, newest last.
 *
 * The final bar returned by both providers is still forming. It is dropped:
 * acting on an unclosed candle is the most common way a signal bot appears to
 * work in testing and repaints in production.
 */
export async function fetchCandles(req: MarketRequest, provider?: ProviderId): Promise<MarketData> {
  const chosen = provider ?? detectProvider(req.symbol);
  const candles = chosen === "binance" ? await fetchBinance(req) : await fetchYahoo(req);
  if (candles.length === 0) {
    throw new MarketDataError(`No candles returned for "${req.symbol}" at ${req.interval}.`, 404);
  }
  return {
    symbol: req.symbol.toUpperCase(),
    interval: req.interval,
    provider: chosen,
    candles: candles.slice(0, -1).slice(-req.limit),
  };
}
