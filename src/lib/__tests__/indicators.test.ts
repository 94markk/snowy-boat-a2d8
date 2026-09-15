import { describe, expect, it } from "vitest";
import { adx, atr, bollinger, donchian, ema, macd, percentileRank, rsi, sma } from "../indicators.ts";
import { generateCandles } from "../synthetic.ts";
import type { Candle } from "../types.ts";

const closes = [
  44.34, 44.09, 44.15, 43.61, 44.33, 44.83, 45.1, 45.42, 45.84, 46.08, 45.89, 46.03, 45.61, 46.28,
  46.28, 46.0, 46.03, 46.41, 46.22, 45.64, 46.21, 46.25, 45.71, 46.45, 45.78, 45.35, 44.03, 44.18,
  44.22, 44.57, 43.42, 42.66, 43.13,
];

function toCandles(values: number[]): Candle[] {
  return values.map((c, i) => ({
    time: i * 3_600_000,
    open: i === 0 ? c : values[i - 1],
    high: c + 0.2,
    low: c - 0.2,
    close: c,
    volume: 1000,
  }));
}

describe("sma", () => {
  it("leaves the warmup window null and averages the window", () => {
    const result = sma([1, 2, 3, 4, 5], 3);
    expect(result.slice(0, 2)).toEqual([null, null]);
    expect(result[2]).toBeCloseTo(2, 10);
    expect(result[4]).toBeCloseTo(4, 10);
  });
});

describe("ema", () => {
  it("seeds from the SMA and then applies the smoothing factor", () => {
    const result = ema([1, 2, 3, 4, 5], 3);
    expect(result[1]).toBeNull();
    expect(result[2]).toBeCloseTo(2, 10);
    // 4 * 0.5 + 2 * 0.5
    expect(result[3]).toBeCloseTo(3, 10);
    expect(result[4]).toBeCloseTo(4, 10);
  });

  it("is independent of extra leading history beyond the seed window", () => {
    const long = ema(closes, 10);
    const short = ema(closes.slice(0, 25), 10);
    expect(long[24]).toBeCloseTo(short[24] as number, 10);
  });
});

describe("rsi", () => {
  // Reference values from Wilder's original worked example.
  it("matches Wilder's reference values", () => {
    const result = rsi(closes, 14);
    expect(result[13]).toBeNull();
    expect(result[14]).toBeCloseTo(70.46, 1);
    expect(result[15]).toBeCloseTo(66.25, 1);
    expect(result[16]).toBeCloseTo(66.48, 1);
  });

  it("stays within 0 and 100 on random data", () => {
    const candles = generateCandles({ bars: 400, seed: 7 });
    for (const value of rsi(candles.map((c) => c.close))) {
      if (value === null) continue;
      expect(value).toBeGreaterThanOrEqual(0);
      expect(value).toBeLessThanOrEqual(100);
    }
  });

  it("reports 100 when every bar advances", () => {
    const rising = Array.from({ length: 30 }, (_, i) => 100 + i);
    expect(rsi(rising, 14).at(-1)).toBe(100);
  });
});

describe("macd", () => {
  it("keeps histogram equal to macd minus signal", () => {
    const candles = generateCandles({ bars: 300, seed: 11 });
    const { macd: line, signal, histogram } = macd(candles.map((c) => c.close));
    for (let i = 0; i < histogram.length; i++) {
      if (histogram[i] === null) continue;
      expect(histogram[i] as number).toBeCloseTo((line[i] as number) - (signal[i] as number), 10);
    }
  });
});

describe("atr", () => {
  it("is always positive and reflects the bar range", () => {
    const candles = toCandles(closes);
    const result = atr(candles, 14);
    expect(result[12]).toBeNull();
    for (const v of result) if (v !== null) expect(v).toBeGreaterThan(0);
  });
});

describe("bollinger", () => {
  it("orders the bands and reports %B consistently", () => {
    const candles = generateCandles({ bars: 200, seed: 3 });
    const values = candles.map((c) => c.close);
    const { upper, middle, lower, percentB } = bollinger(values, 20, 2);
    for (let i = 19; i < values.length; i++) {
      expect(upper[i] as number).toBeGreaterThanOrEqual(middle[i] as number);
      expect(middle[i] as number).toBeGreaterThanOrEqual(lower[i] as number);
      const expected = ((values[i] - (lower[i] as number)) / ((upper[i] as number) - (lower[i] as number)));
      expect(percentB[i] as number).toBeCloseTo(expected, 10);
    }
  });
});

describe("adx", () => {
  it("stays in 0..100 and rises on a persistent trend", () => {
    const trending = generateCandles({ bars: 400, seed: 5, drift: 0.004, volatility: 0.004, regimeLength: 0 });
    const { adx: values, plusDi, minusDi } = adx(trending, 14);
    const last = values.at(-1) as number;
    expect(last).toBeGreaterThan(20);
    for (const series of [values, plusDi, minusDi]) {
      for (const v of series) {
        if (v === null) continue;
        expect(v).toBeGreaterThanOrEqual(0);
        expect(v).toBeLessThanOrEqual(100);
      }
    }
  });
});

describe("donchian", () => {
  it("excludes the current bar from its own channel", () => {
    const candles = toCandles([1, 2, 3, 10, 4]);
    const { upper } = donchian(candles, 3);
    // Bar 3 has the highest high but must not set its own upper channel.
    expect(upper[3]).toBeCloseTo(3.2, 10);
  });
});

describe("percentileRank", () => {
  it("returns 1 when the newest value is the largest in the window", () => {
    expect(percentileRank([1, 2, 3, 4, 5], 5).at(-1)).toBe(1);
    expect(percentileRank([5, 4, 3, 2, 1], 5).at(-1)).toBeCloseTo(0.2, 10);
  });
});
