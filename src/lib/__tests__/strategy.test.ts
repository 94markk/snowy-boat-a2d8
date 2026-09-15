import { describe, expect, it } from "vitest";
import { computeIndicators, DEFAULT_CONFIG, evaluateAt, latestSignal, warmupBars } from "../strategy.ts";
import { generateCandles } from "../synthetic.ts";

describe("evaluateAt", () => {
  const candles = generateCandles({ bars: 900, seed: 21 });
  const ind = computeIndicators(candles);

  it("returns null before the indicators have warmed up", () => {
    expect(evaluateAt(candles, 5, ind)).toBeNull();
  });

  /**
   * The most important test in the project: the signal for bar `i` must not
   * change when future bars are removed. If this fails, every backtest number
   * is fiction.
   */
  it("uses no data from future bars", () => {
    const warmup = warmupBars(DEFAULT_CONFIG);
    for (const i of [warmup + 1, warmup + 50, 400, 600, candles.length - 1]) {
      const withFuture = evaluateAt(candles, i, ind);
      const truncated = candles.slice(0, i + 1);
      const withoutFuture = evaluateAt(truncated, i, computeIndicators(truncated));
      expect(withoutFuture).not.toBeNull();
      expect(withoutFuture!.action).toBe(withFuture!.action);
      expect(withoutFuture!.score).toBeCloseTo(withFuture!.score, 6);
      expect(withoutFuture!.confidence).toBe(withFuture!.confidence);
    }
  });

  it("keeps scores and confidence inside their declared ranges", () => {
    for (let i = warmupBars(DEFAULT_CONFIG); i < candles.length; i++) {
      const signal = evaluateAt(candles, i, ind);
      if (!signal) continue;
      expect(signal.score).toBeGreaterThanOrEqual(-1);
      expect(signal.score).toBeLessThanOrEqual(1);
      expect(signal.confidence).toBeGreaterThanOrEqual(0);
      expect(signal.confidence).toBeLessThanOrEqual(100);
      for (const factor of signal.factors) {
        expect(factor.score).toBeGreaterThanOrEqual(-1);
        expect(factor.score).toBeLessThanOrEqual(1);
      }
    }
  });

  it("puts the stop on the losing side of entry and targets on the winning side", () => {
    let checked = 0;
    for (let i = warmupBars(DEFAULT_CONFIG); i < candles.length; i++) {
      const signal = evaluateAt(candles, i, ind);
      if (!signal?.risk) continue;
      checked++;
      const { entry, stop, targets } = signal.risk;
      if (signal.action === "BUY") {
        expect(stop).toBeLessThan(entry);
        expect(Math.min(...targets)).toBeGreaterThan(entry);
      } else {
        expect(stop).toBeGreaterThan(entry);
        expect(Math.max(...targets)).toBeLessThan(entry);
      }
      // Targets are spaced at 1R, 2R, 3R.
      expect(Math.abs(targets[1] - entry)).toBeCloseTo(2 * Math.abs(targets[0] - entry), 3);
    }
    expect(checked).toBeGreaterThan(0);
  });

  it("holds when no trade is signalled and therefore publishes no risk plan", () => {
    const holds = [];
    for (let i = warmupBars(DEFAULT_CONFIG); i < candles.length; i++) {
      const signal = evaluateAt(candles, i, ind);
      if (signal?.action === "HOLD") holds.push(signal);
    }
    expect(holds.length).toBeGreaterThan(0);
    for (const signal of holds) expect(signal.risk).toBeNull();
  });

  it("is bullish on a strong sustained uptrend", () => {
    const up = generateCandles({ bars: 600, seed: 9, drift: 0.005, volatility: 0.004, regimeLength: 0 });
    const signal = latestSignal(up);
    expect(signal).not.toBeNull();
    expect(signal!.score).toBeGreaterThan(0);
    expect(signal!.factors.find((f) => f.key === "trend")!.score).toBeGreaterThan(0.5);
  });

  it("is bearish on a strong sustained downtrend", () => {
    const down = generateCandles({ bars: 600, seed: 9, drift: -0.005, volatility: 0.004, regimeLength: 0 });
    const signal = latestSignal(down);
    expect(signal).not.toBeNull();
    expect(signal!.score).toBeLessThan(0);
    expect(signal!.factors.find((f) => f.key === "trend")!.score).toBeLessThan(-0.5);
  });

  it("warns about ranging markets instead of silently trading them", () => {
    const flat = generateCandles({ bars: 700, seed: 13, drift: 0, volatility: 0.002, regimeLength: 0 });
    const ind2 = computeIndicators(flat);
    const warned = [];
    for (let i = warmupBars(DEFAULT_CONFIG); i < flat.length; i++) {
      const signal = evaluateAt(flat, i, ind2);
      if (signal?.warnings.length) warned.push(signal);
    }
    expect(warned.length).toBeGreaterThan(0);
  });

  it("respects a raised entry threshold by emitting fewer trades", () => {
    const count = (threshold: number) => {
      let trades = 0;
      const config = { ...DEFAULT_CONFIG, entryThreshold: threshold, minConfidence: 0, minAdx: 0 };
      const indLocal = computeIndicators(candles, config);
      for (let i = warmupBars(config); i < candles.length; i++) {
        if (evaluateAt(candles, i, indLocal, config)?.action !== "HOLD") trades++;
      }
      return trades;
    };
    expect(count(0.6)).toBeLessThan(count(0.1));
  });
});

describe("latestSignal", () => {
  it("returns null for an empty series", () => {
    expect(latestSignal([])).toBeNull();
  });

  it("carries the symbol and interval through to the result", () => {
    const candles = generateCandles({ bars: 400, seed: 2 });
    const signal = latestSignal(candles, DEFAULT_CONFIG, { symbol: "BTCUSDT", interval: "1h" });
    expect(signal!.symbol).toBe("BTCUSDT");
    expect(signal!.interval).toBe("1h");
    expect(signal!.time).toBe(candles.at(-1)!.time);
  });
});
