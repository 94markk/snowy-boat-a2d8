/** A single OHLCV bar. `time` is a Unix timestamp in milliseconds (bar open). */
export interface Candle {
  time: number;
  open: number;
  high: number;
  low: number;
  close: number;
  volume: number;
}

export type Action = "BUY" | "SELL" | "HOLD";

/** One contributing rule inside the scoring engine. */
export interface Factor {
  /** Stable identifier, e.g. "trend". */
  key: string;
  /** Human-readable name shown in the UI. */
  label: string;
  /** Directional score, -1 (max bearish) to +1 (max bullish). */
  score: number;
  /** Relative importance in the weighted average. */
  weight: number;
  /** Plain-English explanation of why this score was produced. */
  reason: string;
}

/** Suggested trade levels derived from volatility (ATR), not from prediction. */
export interface RiskPlan {
  entry: number;
  stop: number;
  /** Take-profit levels at 1R, 2R and 3R. */
  targets: number[];
  /** Distance from entry to stop, in price terms. */
  riskPerUnit: number;
  atr: number;
  /** Units to buy/sell for a given account size and risk fraction. */
  positionSize: number;
}

export interface Signal {
  symbol: string;
  interval: string;
  /** Bar open time the signal was computed on. */
  time: number;
  price: number;
  action: Action;
  /** Weighted composite of all factors, -1 to +1. */
  score: number;
  /** 0-100. Blends score magnitude, factor agreement and trend strength. */
  confidence: number;
  factors: Factor[];
  risk: RiskPlan | null;
  /** Reasons the engine refused to act (e.g. choppy market). */
  warnings: string[];
}
