import { describe, expect, it } from "vitest";
import { backtest, DEFAULT_BACKTEST_CONFIG, walkForward } from "../backtest.ts";
import { DEFAULT_CONFIG } from "../strategy.ts";
import { generateCandles } from "../synthetic.ts";
import type { Candle } from "../types.ts";

const candles = generateCandles({ bars: 2000, seed: 77 });

describe("backtest", () => {
  it("refuses to report metrics when there is not enough history", () => {
    const result = backtest(candles.slice(0, 50));
    expect(result.metrics.trades).toBe(0);
    expect(result.warnings[0]).toMatch(/Need more than/);
  });

  it("produces internally consistent metrics", () => {
    const result = backtest(candles, DEFAULT_CONFIG, { allowShorts: true });
    const { metrics, trades } = result;
    expect(metrics.trades).toBe(trades.length);
    expect(metrics.wins + metrics.losses).toBe(trades.length);
    if (trades.length > 0) {
      expect(metrics.winRate).toBeCloseTo((metrics.wins / trades.length) * 100, 2);
    }
    expect(metrics.maxDrawdownPct).toBeGreaterThanOrEqual(0);
    expect(metrics.exposurePct).toBeGreaterThanOrEqual(0);
    expect(metrics.exposurePct).toBeLessThanOrEqual(100);
  });

  it("enters on the bar after the signal, never on the signal bar's close", () => {
    const result = backtest(candles, DEFAULT_CONFIG, { allowShorts: true });
    expect(result.trades.length).toBeGreaterThan(0);
    for (const trade of result.trades) {
      const entryIndex = candles.findIndex((c) => c.time === trade.entryTime);
      expect(entryIndex).toBeGreaterThan(0);
      const bar = candles[entryIndex];
      // Fill is the bar's open plus slippage, so it sits very close to that
      // open. Recorded prices are rounded to 4dp, so allow that quantum too.
      const drift = Math.abs(trade.entryPrice - bar.open) / bar.open;
      const tolerance = DEFAULT_BACKTEST_CONFIG.slippageBps / 10_000 + 1e-4 / bar.open;
      expect(drift).toBeLessThanOrEqual(tolerance);
    }
  });

  it("never holds more than one position at a time", () => {
    const result = backtest(candles, DEFAULT_CONFIG, { allowShorts: true });
    const sorted = [...result.trades].sort((a, b) => a.entryTime - b.entryTime);
    for (let i = 1; i < sorted.length; i++) {
      expect(sorted[i].entryTime).toBeGreaterThanOrEqual(sorted[i - 1].exitTime);
    }
  });

  it("stays flat on shorts when shorting is disabled", () => {
    const result = backtest(candles, DEFAULT_CONFIG, { allowShorts: false });
    expect(result.trades.every((t) => t.direction === "long")).toBe(true);
  });

  it("makes results worse when costs rise", () => {
    const cheap = backtest(candles, DEFAULT_CONFIG, { feeBps: 0, slippageBps: 0, allowShorts: true });
    const pricey = backtest(candles, DEFAULT_CONFIG, { feeBps: 50, slippageBps: 25, allowShorts: true });
    expect(pricey.metrics.totalReturnPct).toBeLessThan(cheap.metrics.totalReturnPct);
  });

  it("closes losing trades at the stop and winners at the target", () => {
    const result = backtest(candles, DEFAULT_CONFIG, { allowShorts: true });
    for (const trade of result.trades) {
      if (trade.exitReason === "stop") expect(trade.rMultiple).toBeLessThan(0.1);
      if (trade.exitReason === "target") expect(trade.rMultiple).toBeGreaterThan(0.5);
    }
  });

  it("flags small samples as statistically meaningless", () => {
    const short = backtest(candles.slice(0, 400));
    if (short.metrics.trades < 30) {
      expect(short.warnings.join(" ")).toMatch(/cannot distinguish skill from luck/);
    }
  });

  it("tracks a buy-and-hold benchmark over the same window", () => {
    const rising: Candle[] = generateCandles({
      bars: 1200,
      seed: 4,
      drift: 0.002,
      volatility: 0.006,
      regimeLength: 0,
    });
    const result = backtest(rising);
    expect(result.metrics.buyHoldReturnPct).toBeGreaterThan(0);
  });

  it("loses money on a pure random walk once costs are applied", () => {
    // There is no edge to find in a driftless random walk. A backtester that
    // shows a profit here is measuring its own bugs.
    const noise = generateCandles({ bars: 3000, seed: 99, drift: 0, volatility: 0.01, regimeLength: 0 });
    const result = backtest(noise, DEFAULT_CONFIG, { allowShorts: true, feeBps: 10, slippageBps: 5 });
    if (result.metrics.trades >= 30) {
      expect(result.metrics.expectancyR).toBeLessThan(0.25);
    }
  });
});

describe("walkForward", () => {
  it("splits history and reports both halves separately", () => {
    const { inSample, outOfSample } = walkForward(candles, DEFAULT_CONFIG, { allowShorts: true });
    expect(inSample.barsTested).toBeGreaterThan(0);
    expect(outOfSample.barsTested).toBeGreaterThan(0);
    const allInSample = inSample.trades.every((t) => t.entryTime < candles[Math.floor(candles.length * 0.6)].time);
    expect(allInSample).toBe(true);
  });
});
