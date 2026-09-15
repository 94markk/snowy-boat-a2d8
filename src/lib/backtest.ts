import { computeIndicators, DEFAULT_CONFIG, evaluateAt, warmupBars, type StrategyConfig } from "./strategy.ts";
import type { Candle } from "./types.ts";

/**
 * Event-driven backtester.
 *
 * The point of this file is honesty. A signal engine can always be made to look
 * good on a chart after the fact; the only way to know whether it has an edge is
 * to replay it bar by bar under realistic costs. Two rules keep it fair:
 *
 *  1. A signal computed at the close of bar `i` is filled at the OPEN of bar
 *     `i+1`. You cannot trade a close you have only just observed.
 *  2. If a bar's range contains both the stop and the target, the stop is
 *     assumed to hit first. Intrabar order is unknowable, so assume the worse.
 */

export interface BacktestConfig {
  initialCapital: number;
  /** Round-trip exchange fee, per side, in basis points. */
  feeBps: number;
  /** Adverse price movement assumed on every fill, in basis points. */
  slippageBps: number;
  /** Allow short trades. Off by default: many spot accounts cannot short. */
  allowShorts: boolean;
  /** Notional cap as a multiple of equity. 1 = no leverage. */
  maxLeverage: number;
  /** Exit when the engine flips to the opposite signal. */
  exitOnOppositeSignal: boolean;
  /** Bars per year, used to annualise Sharpe and CAGR. */
  barsPerYear: number;
}

export const DEFAULT_BACKTEST_CONFIG: BacktestConfig = {
  initialCapital: 10_000,
  feeBps: 10,
  slippageBps: 5,
  allowShorts: false,
  maxLeverage: 1,
  exitOnOppositeSignal: true,
  barsPerYear: 365 * 24,
};

export type ExitReason = "stop" | "target" | "opposite-signal" | "end-of-data";

export interface Trade {
  direction: "long" | "short";
  entryTime: number;
  entryPrice: number;
  exitTime: number;
  exitPrice: number;
  quantity: number;
  /** Net of fees and slippage. */
  pnl: number;
  returnPct: number;
  /** Profit measured in units of initial risk (pnl / risked cash). */
  rMultiple: number;
  bars: number;
  exitReason: ExitReason;
  entryScore: number;
  entryConfidence: number;
}

export interface BacktestMetrics {
  trades: number;
  wins: number;
  losses: number;
  winRate: number;
  avgWin: number;
  avgLoss: number;
  profitFactor: number;
  expectancyR: number;
  totalReturnPct: number;
  cagrPct: number;
  maxDrawdownPct: number;
  sharpe: number;
  /** Return of simply holding the asset over the same window. */
  buyHoldReturnPct: number;
  exposurePct: number;
}

export interface BacktestResult {
  metrics: BacktestMetrics;
  trades: Trade[];
  equityCurve: { time: number; equity: number }[];
  /** Number of bars the engine was actually able to evaluate. */
  barsTested: number;
  warnings: string[];
}

interface OpenPosition {
  direction: 1 | -1;
  entryTime: number;
  entryPrice: number;
  quantity: number;
  stop: number;
  target: number;
  riskedCash: number;
  entryBar: number;
  entryScore: number;
  entryConfidence: number;
}

function round(value: number, digits = 4): number {
  const f = 10 ** digits;
  return Math.round(value * f) / f;
}

export function backtest(
  candles: Candle[],
  strategyConfig: StrategyConfig = DEFAULT_CONFIG,
  backtestConfig: Partial<BacktestConfig> = {},
): BacktestResult {
  const cfg: BacktestConfig = { ...DEFAULT_BACKTEST_CONFIG, ...backtestConfig };
  const warnings: string[] = [];
  const warmup = warmupBars(strategyConfig);

  if (candles.length <= warmup + 10) {
    return {
      metrics: emptyMetrics(),
      trades: [],
      equityCurve: [],
      barsTested: 0,
      warnings: [
        `Need more than ${warmup + 10} candles to backtest this configuration; received ${candles.length}.`,
      ],
    };
  }

  const ind = computeIndicators(candles, strategyConfig);
  const feeRate = cfg.feeBps / 10_000;
  const slipRate = cfg.slippageBps / 10_000;

  let equity = cfg.initialCapital;
  let position: OpenPosition | null = null;
  const trades: Trade[] = [];
  const equityCurve: { time: number; equity: number }[] = [];
  let barsInMarket = 0;
  let barsTested = 0;

  const closeTrade = (exitPrice: number, exitTime: number, bar: number, reason: ExitReason) => {
    if (!position) return;
    // Slippage always works against us on the way out too.
    const fill = exitPrice * (1 - position.direction * slipRate);
    const grossPnl = position.direction * (fill - position.entryPrice) * position.quantity;
    const exitFee = fill * position.quantity * feeRate;
    const pnl = grossPnl - exitFee;
    equity += pnl;
    trades.push({
      direction: position.direction === 1 ? "long" : "short",
      entryTime: position.entryTime,
      entryPrice: round(position.entryPrice),
      exitTime,
      exitPrice: round(fill),
      quantity: round(position.quantity, 8),
      pnl: round(pnl, 2),
      returnPct: round((pnl / cfg.initialCapital) * 100, 3),
      rMultiple: position.riskedCash > 0 ? round(pnl / position.riskedCash, 3) : 0,
      bars: bar - position.entryBar,
      exitReason: reason,
      entryScore: position.entryScore,
      entryConfidence: position.entryConfidence,
    });
    position = null;
  };

  for (let i = warmup; i < candles.length - 1; i++) {
    const signal = evaluateAt(candles, i, ind, strategyConfig);
    if (!signal) continue;
    barsTested++;

    const next = candles[i + 1];

    // --- Manage an open position on the NEXT bar's range -------------------
    if (position) {
      barsInMarket++;
      const dir = position.direction;
      const hitStop = dir === 1 ? next.low <= position.stop : next.high >= position.stop;
      const hitTarget = dir === 1 ? next.high >= position.target : next.low <= position.target;
      if (hitStop) {
        // Pessimistic: when both are touched in one bar, take the stop.
        closeTrade(position.stop, next.time, i + 1, "stop");
      } else if (hitTarget) {
        closeTrade(position.target, next.time, i + 1, "target");
      } else if (
        cfg.exitOnOppositeSignal &&
        signal.action !== "HOLD" &&
        Math.sign(signal.score) !== dir
      ) {
        closeTrade(next.open, next.time, i + 1, "opposite-signal");
      }
    }

    // --- Open a new position at the next bar's open ------------------------
    if (!position && signal.action !== "HOLD" && signal.risk) {
      const dir: 1 | -1 = signal.action === "BUY" ? 1 : -1;
      if (dir === -1 && !cfg.allowShorts) {
        // Shorts disabled: stay flat.
      } else {
        const fill = next.open * (1 + dir * slipRate);
        const riskPerUnit = signal.risk.riskPerUnit;
        if (riskPerUnit > 0) {
          const riskCash = equity * strategyConfig.riskPerTrade;
          const maxQty = (equity * cfg.maxLeverage) / fill;
          const quantity = Math.min(riskCash / riskPerUnit, maxQty);
          if (quantity > 0) {
            const entryFee = fill * quantity * feeRate;
            equity -= entryFee;
            position = {
              direction: dir,
              entryTime: next.time,
              entryPrice: fill,
              quantity,
              stop: fill - dir * riskPerUnit,
              // Target the 2R level; 1R rarely covers costs, 3R fills too seldom.
              target: fill + dir * riskPerUnit * 2,
              riskedCash: quantity * riskPerUnit,
              entryBar: i + 1,
              entryScore: signal.score,
              entryConfidence: signal.confidence,
            };
          }
        }
      }
    }

    // Mark to market so the drawdown reflects open risk, not just closed trades.
    const openPnl = position
      ? position.direction * (next.close - position.entryPrice) * position.quantity
      : 0;
    equityCurve.push({ time: next.time, equity: round(equity + openPnl, 2) });
  }

  if (position) {
    const last = candles[candles.length - 1];
    closeTrade(last.close, last.time, candles.length - 1, "end-of-data");
    equityCurve.push({ time: last.time, equity: round(equity, 2) });
  }

  if (trades.length < 30) {
    warnings.push(
      `Only ${trades.length} trades in this sample. Fewer than ~30 trades cannot distinguish skill from luck.`,
    );
  }

  const metrics = computeMetrics(trades, equityCurve, candles, cfg, barsInMarket, barsTested);
  return { metrics, trades, equityCurve, barsTested, warnings };
}

function emptyMetrics(): BacktestMetrics {
  return {
    trades: 0,
    wins: 0,
    losses: 0,
    winRate: 0,
    avgWin: 0,
    avgLoss: 0,
    profitFactor: 0,
    expectancyR: 0,
    totalReturnPct: 0,
    cagrPct: 0,
    maxDrawdownPct: 0,
    sharpe: 0,
    buyHoldReturnPct: 0,
    exposurePct: 0,
  };
}

function computeMetrics(
  trades: Trade[],
  equityCurve: { time: number; equity: number }[],
  candles: Candle[],
  cfg: BacktestConfig,
  barsInMarket: number,
  barsTested: number,
): BacktestMetrics {
  if (equityCurve.length === 0) return emptyMetrics();

  const wins = trades.filter((t) => t.pnl > 0);
  const losses = trades.filter((t) => t.pnl <= 0);
  const grossProfit = wins.reduce((s, t) => s + t.pnl, 0);
  const grossLoss = Math.abs(losses.reduce((s, t) => s + t.pnl, 0));

  const finalEquity = equityCurve[equityCurve.length - 1].equity;
  const totalReturnPct = ((finalEquity - cfg.initialCapital) / cfg.initialCapital) * 100;

  let peak = equityCurve[0].equity;
  let maxDrawdown = 0;
  for (const point of equityCurve) {
    peak = Math.max(peak, point.equity);
    if (peak > 0) maxDrawdown = Math.max(maxDrawdown, (peak - point.equity) / peak);
  }

  const returns: number[] = [];
  for (let i = 1; i < equityCurve.length; i++) {
    const prev = equityCurve[i - 1].equity;
    if (prev > 0) returns.push(equityCurve[i].equity / prev - 1);
  }
  const mean = returns.reduce((s, r) => s + r, 0) / (returns.length || 1);
  const variance = returns.reduce((s, r) => s + (r - mean) ** 2, 0) / (returns.length || 1);
  const sd = Math.sqrt(variance);
  const sharpe = sd === 0 ? 0 : (mean / sd) * Math.sqrt(cfg.barsPerYear);

  const years = equityCurve.length / cfg.barsPerYear;
  const cagr =
    years > 0 && finalEquity > 0 ? ((finalEquity / cfg.initialCapital) ** (1 / years) - 1) * 100 : 0;

  const firstClose = candles[candles.length - equityCurve.length]?.close ?? candles[0].close;
  const lastClose = candles[candles.length - 1].close;
  const buyHold = firstClose > 0 ? ((lastClose - firstClose) / firstClose) * 100 : 0;

  return {
    trades: trades.length,
    wins: wins.length,
    losses: losses.length,
    winRate: trades.length ? round((wins.length / trades.length) * 100, 2) : 0,
    avgWin: wins.length ? round(grossProfit / wins.length, 2) : 0,
    avgLoss: losses.length ? round(-grossLoss / losses.length, 2) : 0,
    profitFactor: grossLoss === 0 ? (grossProfit > 0 ? Infinity : 0) : round(grossProfit / grossLoss, 3),
    expectancyR: trades.length
      ? round(trades.reduce((s, t) => s + t.rMultiple, 0) / trades.length, 3)
      : 0,
    totalReturnPct: round(totalReturnPct, 2),
    cagrPct: round(cagr, 2),
    maxDrawdownPct: round(maxDrawdown * 100, 2),
    sharpe: round(sharpe, 3),
    buyHoldReturnPct: round(buyHold, 2),
    exposurePct: barsTested ? round((barsInMarket / barsTested) * 100, 2) : 0,
  };
}

/**
 * Split the history in two, so a promising result can be checked on data the
 * parameters were not chosen on. An in-sample edge that vanishes out-of-sample
 * is the single most common way backtests lie.
 */
export function walkForward(
  candles: Candle[],
  strategyConfig: StrategyConfig = DEFAULT_CONFIG,
  backtestConfig: Partial<BacktestConfig> = {},
  splitRatio = 0.6,
): { inSample: BacktestResult; outOfSample: BacktestResult } {
  const split = Math.floor(candles.length * splitRatio);
  const warmup = warmupBars(strategyConfig);
  return {
    inSample: backtest(candles.slice(0, split), strategyConfig, backtestConfig),
    // Carry the warmup window into the second half so its first bars are usable.
    outOfSample: backtest(candles.slice(Math.max(0, split - warmup)), strategyConfig, backtestConfig),
  };
}
