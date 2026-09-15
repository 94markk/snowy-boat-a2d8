import type { Candle } from "./types.ts";

/**
 * Deterministic synthetic price series, used by the tests and by the demo
 * endpoint when no live data provider is reachable. A fixed seed means results
 * are reproducible across machines and CI runs.
 */

/** mulberry32: small, fast, seeded PRNG. */
export function createRandom(seed: number): () => number {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

export interface SyntheticOptions {
  bars: number;
  startPrice: number;
  /** Per-bar drift as a fraction, e.g. 0.0004 for a gentle uptrend. */
  drift: number;
  /** Per-bar volatility as a fraction. */
  volatility: number;
  seed: number;
  /** Length of alternating trend/range regimes, in bars. 0 disables regimes. */
  regimeLength?: number;
  intervalMs?: number;
}

export const DEFAULT_SYNTHETIC: SyntheticOptions = {
  bars: 1500,
  startPrice: 100,
  drift: 0.0003,
  volatility: 0.012,
  seed: 42,
  regimeLength: 250,
  intervalMs: 60 * 60 * 1000,
};

/**
 * Generate OHLCV bars from a geometric random walk with alternating trending
 * and mean-reverting regimes, so a strategy is not tested on one easy shape.
 */
export function generateCandles(options: Partial<SyntheticOptions> = {}): Candle[] {
  const opts = { ...DEFAULT_SYNTHETIC, ...options };
  const random = createRandom(opts.seed);
  const intervalMs = opts.intervalMs ?? 60 * 60 * 1000;
  const startTime = Date.UTC(2023, 0, 1);

  // Box-Muller, so the walk has normal rather than uniform increments.
  const gaussian = () => {
    const u = Math.max(random(), 1e-12);
    const v = random();
    return Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * v);
  };

  const candles: Candle[] = [];
  let price = opts.startPrice;
  for (let i = 0; i < opts.bars; i++) {
    let drift = opts.drift;
    if (opts.regimeLength && opts.regimeLength > 0) {
      const regime = Math.floor(i / opts.regimeLength) % 3;
      // 0 = uptrend, 1 = range, 2 = downtrend.
      drift = regime === 0 ? opts.drift : regime === 1 ? 0 : -opts.drift;
      if (regime === 1) {
        // Pull back towards the regime's starting price to create a range.
        const anchor = candles[Math.floor(i / opts.regimeLength) * opts.regimeLength]?.close ?? price;
        drift = ((anchor - price) / price) * 0.05;
      }
    }
    const open = price;
    const shock = gaussian() * opts.volatility;
    const close = Math.max(0.01, open * (1 + drift + shock));
    const wick = Math.abs(gaussian()) * opts.volatility * 0.5 * open;
    candles.push({
      time: startTime + i * intervalMs,
      open: Number(open.toFixed(6)),
      high: Number((Math.max(open, close) + wick).toFixed(6)),
      low: Number(Math.max(0.005, Math.min(open, close) - wick).toFixed(6)),
      close: Number(close.toFixed(6)),
      volume: Number((1000 * (0.6 + random())).toFixed(2)),
    });
    price = close;
  }
  return candles;
}
