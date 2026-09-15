import type { Candle } from "./types.ts";

/**
 * Technical indicators.
 *
 * Every function returns an array the same length as its input, where leading
 * values that cannot be computed yet are `null`. Keeping the arrays aligned
 * with the candle array is what lets the backtester read index `i` of any
 * indicator and know it only used candles `0..i` -- i.e. no lookahead.
 */

export type Series = (number | null)[];

export function sma(values: number[], period: number): Series {
  const out: Series = new Array(values.length).fill(null);
  if (period <= 0) return out;
  let sum = 0;
  for (let i = 0; i < values.length; i++) {
    sum += values[i];
    if (i >= period) sum -= values[i - period];
    if (i >= period - 1) out[i] = sum / period;
  }
  return out;
}

export function ema(values: number[], period: number): Series {
  const out: Series = new Array(values.length).fill(null);
  if (period <= 0 || values.length < period) return out;
  const k = 2 / (period + 1);
  // Seed with the SMA of the first `period` values so the series is
  // deterministic regardless of how much history is passed in.
  let seed = 0;
  for (let i = 0; i < period; i++) seed += values[i];
  let prev = seed / period;
  out[period - 1] = prev;
  for (let i = period; i < values.length; i++) {
    prev = values[i] * k + prev * (1 - k);
    out[i] = prev;
  }
  return out;
}

/** Wilder's smoothing (used by RSI, ATR and ADX). */
export function wilderSmooth(values: number[], period: number): Series {
  const out: Series = new Array(values.length).fill(null);
  if (period <= 0 || values.length < period) return out;
  let sum = 0;
  for (let i = 0; i < period; i++) sum += values[i];
  let prev = sum / period;
  out[period - 1] = prev;
  for (let i = period; i < values.length; i++) {
    prev = prev + (values[i] - prev) / period;
    out[i] = prev;
  }
  return out;
}

export function rsi(closes: number[], period = 14): Series {
  const out: Series = new Array(closes.length).fill(null);
  if (closes.length <= period) return out;
  const gains: number[] = [0];
  const losses: number[] = [0];
  for (let i = 1; i < closes.length; i++) {
    const change = closes[i] - closes[i - 1];
    gains.push(Math.max(0, change));
    losses.push(Math.max(0, -change));
  }
  // Drop the synthetic first element so the smoothing window covers real changes.
  const avgGain = wilderSmooth(gains.slice(1), period);
  const avgLoss = wilderSmooth(losses.slice(1), period);
  for (let i = 0; i < avgGain.length; i++) {
    const g = avgGain[i];
    const l = avgLoss[i];
    if (g === null || l === null) continue;
    out[i + 1] = l === 0 ? 100 : 100 - 100 / (1 + g / l);
  }
  return out;
}

export interface MacdResult {
  macd: Series;
  signal: Series;
  histogram: Series;
}

export function macd(closes: number[], fast = 12, slow = 26, signalPeriod = 9): MacdResult {
  const fastEma = ema(closes, fast);
  const slowEma = ema(closes, slow);
  const macdLine: Series = closes.map((_, i) => {
    const f = fastEma[i];
    const s = slowEma[i];
    return f === null || s === null ? null : f - s;
  });
  // The signal line is an EMA of the MACD line, which only exists from `slow-1` on.
  const firstIndex = macdLine.findIndex((v) => v !== null);
  const signal: Series = new Array(closes.length).fill(null);
  const histogram: Series = new Array(closes.length).fill(null);
  if (firstIndex === -1) return { macd: macdLine, signal, histogram };
  const dense = macdLine.slice(firstIndex) as number[];
  const denseSignal = ema(dense, signalPeriod);
  for (let i = 0; i < denseSignal.length; i++) {
    const s = denseSignal[i];
    if (s === null) continue;
    const idx = firstIndex + i;
    signal[idx] = s;
    histogram[idx] = (macdLine[idx] as number) - s;
  }
  return { macd: macdLine, signal, histogram };
}

export function trueRange(candles: Candle[]): number[] {
  return candles.map((c, i) => {
    if (i === 0) return c.high - c.low;
    const prevClose = candles[i - 1].close;
    return Math.max(c.high - c.low, Math.abs(c.high - prevClose), Math.abs(c.low - prevClose));
  });
}

export function atr(candles: Candle[], period = 14): Series {
  return wilderSmooth(trueRange(candles), period);
}

export interface BollingerResult {
  upper: Series;
  middle: Series;
  lower: Series;
  /** (close - lower) / (upper - lower): 0 at the lower band, 1 at the upper. */
  percentB: Series;
  /** Band width as a fraction of the middle band; low values mean a squeeze. */
  bandwidth: Series;
}

export function bollinger(closes: number[], period = 20, mult = 2): BollingerResult {
  const middle = sma(closes, period);
  const upper: Series = new Array(closes.length).fill(null);
  const lower: Series = new Array(closes.length).fill(null);
  const percentB: Series = new Array(closes.length).fill(null);
  const bandwidth: Series = new Array(closes.length).fill(null);
  for (let i = period - 1; i < closes.length; i++) {
    const mean = middle[i];
    if (mean === null) continue;
    let variance = 0;
    for (let j = i - period + 1; j <= i; j++) variance += (closes[j] - mean) ** 2;
    const sd = Math.sqrt(variance / period);
    const u = mean + mult * sd;
    const l = mean - mult * sd;
    upper[i] = u;
    lower[i] = l;
    percentB[i] = u === l ? 0.5 : (closes[i] - l) / (u - l);
    bandwidth[i] = mean === 0 ? 0 : (u - l) / mean;
  }
  return { upper, middle, lower, percentB, bandwidth };
}

export interface AdxResult {
  adx: Series;
  plusDi: Series;
  minusDi: Series;
}

/** Average Directional Index: measures trend *strength*, not direction. */
export function adx(candles: Candle[], period = 14): AdxResult {
  const len = candles.length;
  const empty: Series = new Array(len).fill(null);
  if (len < period * 2) return { adx: empty, plusDi: [...empty], minusDi: [...empty] };

  const plusDm: number[] = [];
  const minusDm: number[] = [];
  const tr: number[] = [];
  for (let i = 1; i < len; i++) {
    const up = candles[i].high - candles[i - 1].high;
    const down = candles[i - 1].low - candles[i].low;
    plusDm.push(up > down && up > 0 ? up : 0);
    minusDm.push(down > up && down > 0 ? down : 0);
    const prevClose = candles[i - 1].close;
    tr.push(
      Math.max(
        candles[i].high - candles[i].low,
        Math.abs(candles[i].high - prevClose),
        Math.abs(candles[i].low - prevClose),
      ),
    );
  }

  const smoothedTr = wilderSmooth(tr, period);
  const smoothedPlus = wilderSmooth(plusDm, period);
  const smoothedMinus = wilderSmooth(minusDm, period);

  const plusDi: Series = new Array(len).fill(null);
  const minusDi: Series = new Array(len).fill(null);
  const dx: number[] = [];
  const dxIndex: number[] = [];
  for (let i = 0; i < smoothedTr.length; i++) {
    const t = smoothedTr[i];
    const p = smoothedPlus[i];
    const m = smoothedMinus[i];
    if (t === null || p === null || m === null || t === 0) continue;
    const pdi = (p / t) * 100;
    const mdi = (m / t) * 100;
    plusDi[i + 1] = pdi;
    minusDi[i + 1] = mdi;
    const sum = pdi + mdi;
    dx.push(sum === 0 ? 0 : (Math.abs(pdi - mdi) / sum) * 100);
    dxIndex.push(i + 1);
  }

  const adxOut: Series = new Array(len).fill(null);
  const smoothedDx = wilderSmooth(dx, period);
  for (let i = 0; i < smoothedDx.length; i++) {
    const v = smoothedDx[i];
    if (v !== null) adxOut[dxIndex[i]] = v;
  }
  return { adx: adxOut, plusDi, minusDi };
}

export interface DonchianResult {
  upper: Series;
  lower: Series;
}

/** Highest high / lowest low of the `period` bars *before* the current one. */
export function donchian(candles: Candle[], period = 20): DonchianResult {
  const upper: Series = new Array(candles.length).fill(null);
  const lower: Series = new Array(candles.length).fill(null);
  for (let i = period; i < candles.length; i++) {
    let hi = -Infinity;
    let lo = Infinity;
    for (let j = i - period; j < i; j++) {
      hi = Math.max(hi, candles[j].high);
      lo = Math.min(lo, candles[j].low);
    }
    upper[i] = hi;
    lower[i] = lo;
  }
  return { upper, lower };
}

/** Percentage change of a series over `period` bars, in percent. */
export function rateOfChange(values: number[], period: number): Series {
  const out: Series = new Array(values.length).fill(null);
  for (let i = period; i < values.length; i++) {
    const base = values[i - period];
    if (base !== 0) out[i] = ((values[i] - base) / base) * 100;
  }
  return out;
}

/** Rolling percentile rank (0-1) of the latest value within its own window. */
export function percentileRank(values: Series, period: number): Series {
  const out: Series = new Array(values.length).fill(null);
  for (let i = period - 1; i < values.length; i++) {
    const current = values[i];
    if (current === null) continue;
    let count = 0;
    let total = 0;
    for (let j = i - period + 1; j <= i; j++) {
      const v = values[j];
      if (v === null) continue;
      total++;
      if (v <= current) count++;
    }
    if (total > 0) out[i] = count / total;
  }
  return out;
}
