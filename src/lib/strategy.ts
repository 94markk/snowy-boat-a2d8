import {
  adx as adxIndicator,
  atr as atrIndicator,
  bollinger,
  donchian,
  ema,
  macd,
  percentileRank,
  rsi,
  sma,
  type Series,
} from "./indicators.ts";
import type { Action, Candle, Factor, RiskPlan, Signal } from "./types.ts";

/**
 * A multi-factor confluence strategy.
 *
 * The engine scores several independent rules, each in [-1, +1], and takes a
 * weighted average. Trading only on *confluence* (several unrelated rules
 * agreeing) is what keeps it from firing on every wiggle -- but note that no
 * combination of indicators predicts price. Treat the output as a structured
 * opinion and judge it with `backtest()`, never on its own say-so.
 */

export interface StrategyConfig {
  emaFast: number;
  emaSlow: number;
  rsiPeriod: number;
  atrPeriod: number;
  adxPeriod: number;
  bollingerPeriod: number;
  donchianPeriod: number;
  volumePeriod: number;
  /** |score| needed to emit BUY/SELL instead of HOLD. */
  entryThreshold: number;
  /** Confidence (0-100) needed to emit BUY/SELL instead of HOLD. */
  minConfidence: number;
  /** Below this ADX the market is treated as too choppy to trade. */
  minAdx: number;
  /** Stop distance as a multiple of ATR. */
  atrStopMultiple: number;
  /** Fraction of the account risked per trade, used for position sizing. */
  riskPerTrade: number;
  accountSize: number;
  weights: Record<string, number>;
}

export const DEFAULT_CONFIG: StrategyConfig = {
  emaFast: 50,
  emaSlow: 200,
  rsiPeriod: 14,
  atrPeriod: 14,
  adxPeriod: 14,
  bollingerPeriod: 20,
  donchianPeriod: 20,
  volumePeriod: 20,
  entryThreshold: 0.3,
  minConfidence: 45,
  minAdx: 18,
  atrStopMultiple: 1.5,
  riskPerTrade: 0.01,
  accountSize: 10_000,
  weights: {
    trend: 0.26,
    momentum: 0.22,
    rsi: 0.16,
    breakout: 0.16,
    bands: 0.1,
    volume: 0.1,
  },
};

/** Minimum number of candles before any signal can be produced. */
export function warmupBars(config: StrategyConfig): number {
  return Math.max(config.emaSlow, config.adxPeriod * 3, config.donchianPeriod + 1) + 5;
}

/** All indicator series, computed once and reused for every bar. */
export interface IndicatorSet {
  emaFast: Series;
  emaSlow: Series;
  rsi: Series;
  macdHistogram: Series;
  atr: Series;
  atrPercentile: Series;
  adx: Series;
  plusDi: Series;
  minusDi: Series;
  percentB: Series;
  donchianUpper: Series;
  donchianLower: Series;
  volumeAverage: Series;
}

export function computeIndicators(candles: Candle[], config: StrategyConfig = DEFAULT_CONFIG): IndicatorSet {
  const closes = candles.map((c) => c.close);
  const volumes = candles.map((c) => c.volume);
  const atrSeries = atrIndicator(candles, config.atrPeriod);
  // Express ATR as a fraction of price so its percentile is comparable over time.
  const atrPercent: Series = atrSeries.map((v, i) => (v === null || closes[i] === 0 ? null : v / closes[i]));
  const { histogram } = macd(closes);
  const { adx, plusDi, minusDi } = adxIndicator(candles, config.adxPeriod);
  const { percentB } = bollinger(closes, config.bollingerPeriod);
  const { upper, lower } = donchian(candles, config.donchianPeriod);

  return {
    emaFast: ema(closes, config.emaFast),
    emaSlow: ema(closes, config.emaSlow),
    rsi: rsi(closes, config.rsiPeriod),
    macdHistogram: histogram,
    atr: atrSeries,
    atrPercentile: percentileRank(atrPercent, 100),
    adx,
    plusDi,
    minusDi,
    percentB,
    donchianUpper: upper,
    donchianLower: lower,
    volumeAverage: sma(volumes, config.volumePeriod),
  };
}

function clamp(value: number, min = -1, max = 1): number {
  return Math.min(max, Math.max(min, value));
}

function round(value: number, digits = 2): number {
  const f = 10 ** digits;
  return Math.round(value * f) / f;
}

/** Price precision that works for both $90,000 BTC and $0.42 altcoins. */
function pricePrecision(price: number): number {
  if (price >= 1000) return 2;
  if (price >= 1) return 4;
  return 6;
}

/**
 * Score every rule for bar `index`, using only candles `0..index`.
 * Returns `null` when there is not enough history yet.
 */
function scoreFactors(
  candles: Candle[],
  index: number,
  ind: IndicatorSet,
  config: StrategyConfig,
): Factor[] | null {
  const candle = candles[index];
  const price = candle.close;
  const emaF = ind.emaFast[index];
  const emaS = ind.emaSlow[index];
  const atrValue = ind.atr[index];
  const rsiValue = ind.rsi[index];
  const hist = ind.macdHistogram[index];
  const prevHist = index > 0 ? ind.macdHistogram[index - 1] : null;
  const percentB = ind.percentB[index];
  const upper = ind.donchianUpper[index];
  const lower = ind.donchianLower[index];
  const volAvg = ind.volumeAverage[index];

  if (emaF === null || emaS === null || atrValue === null || rsiValue === null || hist === null) return null;
  if (percentB === null || upper === null || lower === null || volAvg === null) return null;
  if (atrValue === 0 || price === 0) return null;

  const factors: Factor[] = [];

  // 1. Trend. EMA separation measured in ATR units, so it means the same thing
  //    on a quiet index and on a volatile altcoin.
  const separation = (emaF - emaS) / atrValue;
  const trendScore = clamp(separation / 3);
  const priceAboveFast = price > emaF;
  factors.push({
    key: "trend",
    label: "Trend (EMA 50/200)",
    score: clamp(trendScore * 0.8 + (priceAboveFast ? 0.2 : -0.2)),
    weight: config.weights.trend,
    reason:
      `EMA${config.emaFast} is ${separation >= 0 ? "above" : "below"} EMA${config.emaSlow} by ` +
      `${round(Math.abs(separation))} ATR; price is ${priceAboveFast ? "above" : "below"} EMA${config.emaFast}.`,
  });

  // 2. Momentum. MACD histogram normalised by ATR, plus whether it is expanding.
  const histNorm = clamp((hist / atrValue) * 2);
  const expanding = prevHist === null ? 0 : Math.sign(hist) * (Math.abs(hist) - Math.abs(prevHist)) > 0 ? 0.2 : -0.1;
  factors.push({
    key: "momentum",
    label: "Momentum (MACD)",
    score: clamp(histNorm + Math.sign(histNorm || 1) * expanding),
    weight: config.weights.momentum,
    reason:
      `MACD histogram is ${hist >= 0 ? "positive" : "negative"} (${round(hist / atrValue, 2)} ATR) and ` +
      `${expanding > 0 ? "expanding" : "contracting"}.`,
  });

  // 3. RSI, read differently depending on the trend regime: in an uptrend a
  //    pullback is an opportunity, while in a downtrend it is a continuation.
  const uptrend = emaF > emaS;
  let rsiScore: number;
  let rsiReason: string;
  if (rsiValue >= 70) {
    rsiScore = uptrend ? -0.1 : -0.6;
    rsiReason = `RSI ${round(rsiValue, 1)} is overbought${uptrend ? " but the trend is up, so this is only a caution" : ""}.`;
  } else if (rsiValue <= 30) {
    rsiScore = uptrend ? 0.6 : 0.1;
    rsiReason = `RSI ${round(rsiValue, 1)} is oversold${uptrend ? " inside an uptrend -- a pullback buy zone" : " but the trend is down, so this is only a caution"}.`;
  } else if (uptrend && rsiValue < 45) {
    rsiScore = 0.5;
    rsiReason = `RSI ${round(rsiValue, 1)} has pulled back inside an uptrend.`;
  } else if (!uptrend && rsiValue > 55) {
    rsiScore = -0.5;
    rsiReason = `RSI ${round(rsiValue, 1)} has bounced inside a downtrend.`;
  } else {
    rsiScore = clamp((rsiValue - 50) / 40);
    rsiReason = `RSI ${round(rsiValue, 1)} is neutral.`;
  }
  factors.push({
    key: "rsi",
    label: `RSI (${config.rsiPeriod})`,
    score: rsiScore,
    weight: config.weights.rsi,
    reason: rsiReason,
  });

  // 4. Breakout of the prior N-bar range.
  let breakoutScore = 0;
  let breakoutReason = `Price is inside the ${config.donchianPeriod}-bar range.`;
  if (price > upper) {
    breakoutScore = clamp(0.6 + ((price - upper) / atrValue) * 0.4);
    breakoutReason = `Price broke above the ${config.donchianPeriod}-bar high of ${round(upper, pricePrecision(upper))}.`;
  } else if (price < lower) {
    breakoutScore = clamp(-0.6 - ((lower - price) / atrValue) * 0.4);
    breakoutReason = `Price broke below the ${config.donchianPeriod}-bar low of ${round(lower, pricePrecision(lower))}.`;
  } else if (upper > lower) {
    // Position within the range, mapped from -0.3 (at the low) to +0.3 (at the high).
    breakoutScore = ((price - lower) / (upper - lower) - 0.5) * 0.6;
  }
  factors.push({
    key: "breakout",
    label: `Breakout (${config.donchianPeriod}-bar range)`,
    score: breakoutScore,
    weight: config.weights.breakout,
    reason: breakoutReason,
  });

  // 5. Bollinger %B -- mean reversion. Deliberately *opposes* stretched moves,
  //    so it acts as a brake on chasing an extended candle.
  const bandScore = clamp((0.5 - percentB) * 2);
  factors.push({
    key: "bands",
    label: "Bollinger %B",
    score: bandScore,
    weight: config.weights.bands,
    reason:
      percentB > 1
        ? `Price is above the upper band (%B ${round(percentB)}) -- stretched, mean reversion risk.`
        : percentB < 0
          ? `Price is below the lower band (%B ${round(percentB)}) -- stretched, bounce potential.`
          : `%B is ${round(percentB)} within the bands.`,
  });

  // 6. Volume confirmation: only meaningful in the direction the bar closed.
  const volRatio = volAvg === 0 ? 1 : candle.volume / volAvg;
  const barDirection = Math.sign(candle.close - candle.open);
  const volumeScore = clamp(barDirection * Math.min(1, (volRatio - 1) / 1.5));
  factors.push({
    key: "volume",
    label: "Volume confirmation",
    score: volumeScore,
    weight: config.weights.volume,
    reason: `Volume is ${round(volRatio)}x its ${config.volumePeriod}-bar average on ${
      barDirection >= 0 ? "an up" : "a down"
    } bar.`,
  });

  return factors;
}

function buildRiskPlan(
  price: number,
  atrValue: number,
  direction: 1 | -1,
  config: StrategyConfig,
): RiskPlan {
  const digits = pricePrecision(price);
  const riskPerUnit = atrValue * config.atrStopMultiple;
  const stop = price - direction * riskPerUnit;
  const targets = [1, 2, 3].map((r) => round(price + direction * riskPerUnit * r, digits));
  const cashRisk = config.accountSize * config.riskPerTrade;
  return {
    entry: round(price, digits),
    stop: round(stop, digits),
    targets,
    riskPerUnit: round(riskPerUnit, digits),
    atr: round(atrValue, digits),
    positionSize: riskPerUnit > 0 ? round(cashRisk / riskPerUnit, 6) : 0,
  };
}

/**
 * Produce the signal for bar `index` using only information available at that
 * bar's close. This is the single code path used by both the live API and the
 * backtester, so a backtest cannot flatter the strategy with future data.
 */
export function evaluateAt(
  candles: Candle[],
  index: number,
  ind: IndicatorSet,
  config: StrategyConfig = DEFAULT_CONFIG,
  meta: { symbol: string; interval: string } = { symbol: "", interval: "" },
): Signal | null {
  const factors = scoreFactors(candles, index, ind, config);
  if (!factors) return null;

  const candle = candles[index];
  const totalWeight = factors.reduce((sum, f) => sum + f.weight, 0) || 1;
  const score = factors.reduce((sum, f) => sum + f.score * f.weight, 0) / totalWeight;

  // Confidence blends three things: how strong the composite is, how much the
  // factors agree with each other, and whether the market is actually trending.
  const magnitude = Math.min(1, Math.abs(score) / 0.6);
  const agreeingWeight = factors
    .filter((f) => Math.sign(f.score) === Math.sign(score) && f.score !== 0)
    .reduce((sum, f) => sum + f.weight, 0);
  const agreement = agreeingWeight / totalWeight;

  const adxValue = ind.adx[index];
  const trendStrength = adxValue === null ? 0.6 : 0.6 + clamp((adxValue - 15) / 25, 0, 1) * 0.5;
  const atrPct = ind.atrPercentile[index];
  const volatilityPenalty = atrPct !== null && atrPct > 0.9 ? 0.85 : 1;

  const confidence = Math.round(
    Math.min(100, 100 * magnitude * (0.5 + 0.5 * agreement) * trendStrength * volatilityPenalty),
  );

  const warnings: string[] = [];
  if (adxValue !== null && adxValue < config.minAdx) {
    warnings.push(`ADX ${round(adxValue, 1)} is below ${config.minAdx}: the market is ranging, breakouts often fail.`);
  }
  if (atrPct !== null && atrPct > 0.9) {
    warnings.push("Volatility is in the top decile of the last 100 bars: stops need to be wider and slippage is worse.");
  }
  if (agreement < 0.6) {
    warnings.push("Factors disagree with each other; the composite score is not backed by confluence.");
  }

  let action: Action = "HOLD";
  const wantsTrade = Math.abs(score) >= config.entryThreshold && confidence >= config.minConfidence;
  const trendOk = adxValue === null || adxValue >= config.minAdx;
  if (wantsTrade && trendOk) action = score > 0 ? "BUY" : "SELL";
  else if (wantsTrade && !trendOk) warnings.push("Entry suppressed by the ADX filter.");

  const atrValue = ind.atr[index] as number;
  const digits = pricePrecision(candle.close);

  return {
    symbol: meta.symbol,
    interval: meta.interval,
    time: candle.time,
    price: round(candle.close, digits),
    action,
    score: round(score, 4),
    confidence,
    factors: factors.map((f) => ({ ...f, score: round(f.score, 3) })),
    risk: action === "HOLD" ? null : buildRiskPlan(candle.close, atrValue, action === "BUY" ? 1 : -1, config),
    warnings,
  };
}

/** Signal for the most recent closed candle. */
export function latestSignal(
  candles: Candle[],
  config: StrategyConfig = DEFAULT_CONFIG,
  meta: { symbol: string; interval: string } = { symbol: "", interval: "" },
): Signal | null {
  if (candles.length === 0) return null;
  const ind = computeIndicators(candles, config);
  return evaluateAt(candles, candles.length - 1, ind, config, meta);
}
