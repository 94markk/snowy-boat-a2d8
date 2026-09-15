import { DEFAULT_CONFIG, type StrategyConfig } from "./strategy.ts";
import { MarketDataError, type ProviderId } from "./market.ts";

/** Request parsing shared by the API routes. */

export const SUPPORTED_INTERVALS = ["5m", "15m", "30m", "1h", "4h", "1d", "1wk"] as const;

export class BadRequestError extends Error {
  status = 400;
}

function num(params: URLSearchParams, key: string, fallback: number, min: number, max: number): number {
  const raw = params.get(key);
  if (raw === null || raw === "") return fallback;
  const value = Number(raw);
  if (!Number.isFinite(value)) throw new BadRequestError(`"${key}" must be a number.`);
  if (value < min || value > max) {
    throw new BadRequestError(`"${key}" must be between ${min} and ${max}.`);
  }
  return value;
}

function bool(params: URLSearchParams, key: string, fallback: boolean): boolean {
  const raw = params.get(key);
  if (raw === null || raw === "") return fallback;
  return raw === "1" || raw.toLowerCase() === "true";
}

export interface ParsedQuery {
  symbol: string;
  interval: string;
  limit: number;
  provider: ProviderId | undefined;
  /** Use the deterministic synthetic feed instead of a live provider. */
  demo: boolean;
  strategy: StrategyConfig;
}

export function parseQuery(url: URL): ParsedQuery {
  const params = url.searchParams;
  const demo = bool(params, "demo", false);

  const symbol = (params.get("symbol") ?? (demo ? "DEMO" : "")).trim();
  if (!symbol) throw new BadRequestError('Missing required "symbol" parameter, e.g. ?symbol=BTCUSDT.');
  if (!/^[A-Za-z0-9.^=:-]{1,20}$/.test(symbol)) {
    throw new BadRequestError(`"${symbol}" is not a valid symbol.`);
  }

  const interval = params.get("interval") ?? "1h";
  if (!SUPPORTED_INTERVALS.includes(interval as (typeof SUPPORTED_INTERVALS)[number])) {
    throw new BadRequestError(`"interval" must be one of: ${SUPPORTED_INTERVALS.join(", ")}.`);
  }

  const providerParam = params.get("provider");
  if (providerParam && providerParam !== "binance" && providerParam !== "yahoo") {
    throw new BadRequestError('"provider" must be "binance" or "yahoo".');
  }

  const strategy: StrategyConfig = {
    ...DEFAULT_CONFIG,
    entryThreshold: num(params, "entryThreshold", DEFAULT_CONFIG.entryThreshold, 0, 1),
    minConfidence: num(params, "minConfidence", DEFAULT_CONFIG.minConfidence, 0, 100),
    minAdx: num(params, "minAdx", DEFAULT_CONFIG.minAdx, 0, 60),
    atrStopMultiple: num(params, "atrStopMultiple", DEFAULT_CONFIG.atrStopMultiple, 0.25, 10),
    riskPerTrade: num(params, "riskPerTrade", DEFAULT_CONFIG.riskPerTrade, 0.0001, 0.1),
    accountSize: num(params, "accountSize", DEFAULT_CONFIG.accountSize, 1, 1_000_000_000),
  };

  return {
    symbol,
    interval,
    limit: Math.round(num(params, "limit", 500, 100, 1000)),
    provider: (providerParam as ProviderId | null) ?? undefined,
    demo,
    strategy,
  };
}

export interface BacktestQuery {
  feeBps: number;
  slippageBps: number;
  allowShorts: boolean;
  maxLeverage: number;
}

export function parseBacktestQuery(url: URL): BacktestQuery {
  const params = url.searchParams;
  return {
    feeBps: num(params, "feeBps", 10, 0, 500),
    slippageBps: num(params, "slippageBps", 5, 0, 500),
    allowShorts: bool(params, "allowShorts", false),
    maxLeverage: num(params, "maxLeverage", 1, 0.1, 5),
  };
}

export function json(body: unknown, status = 200, cacheSeconds = 0): Response {
  const headers: Record<string, string> = { "Content-Type": "application/json; charset=utf-8" };
  if (cacheSeconds > 0) headers["Cache-Control"] = `public, max-age=${cacheSeconds}`;
  return new Response(JSON.stringify(body, null, 2), { status, headers });
}

/** Turn any thrown value into a JSON error response with a sensible status. */
export function errorResponse(error: unknown): Response {
  if (error instanceof BadRequestError) return json({ error: error.message }, 400);
  if (error instanceof MarketDataError) return json({ error: error.message }, error.status);
  const message = error instanceof Error ? error.message : "Unexpected error.";
  return json({ error: message }, 500);
}
