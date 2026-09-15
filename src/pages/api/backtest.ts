import type { APIRoute } from "astro";
import { errorResponse, json, parseBacktestQuery, parseQuery } from "../../lib/api.ts";
import { backtest, walkForward } from "../../lib/backtest.ts";
import { barsPerYear, fetchCandles } from "../../lib/market.ts";
import { generateCandles } from "../../lib/synthetic.ts";
import type { Candle } from "../../lib/types.ts";

export const prerender = false;

/**
 * GET /api/backtest?symbol=BTCUSDT&interval=1h&limit=1000
 *
 * Replays the same signal engine bar by bar under fees and slippage, and splits
 * the history so you can see whether the result survives out of sample. Use
 * this before trusting anything /api/signal tells you.
 */
export const GET: APIRoute = async ({ url }) => {
  try {
    const query = parseQuery(url);
    const costs = parseBacktestQuery(url);

    let candles: Candle[];
    let provider: string;
    if (query.demo) {
      candles = generateCandles({ bars: Math.max(query.limit, 1000), seed: 42 });
      provider = "synthetic";
      // Never label synthetic bars with a real ticker.
      query.symbol = "SYNTHETIC";
    } else {
      const data = await fetchCandles(
        { symbol: query.symbol, interval: query.interval, limit: query.limit },
        query.provider,
      );
      candles = data.candles;
      provider = data.provider;
    }

    const backtestConfig = { ...costs, barsPerYear: barsPerYear(query.interval) };
    const full = backtest(candles, query.strategy, backtestConfig);
    const split = walkForward(candles, query.strategy, backtestConfig);

    return json(
      {
        symbol: query.symbol.toUpperCase(),
        interval: query.interval,
        provider,
        candlesUsed: candles.length,
        period:
          candles.length > 0
            ? { from: new Date(candles[0].time).toISOString(), to: new Date(candles.at(-1)!.time).toISOString() }
            : null,
        costs,
        metrics: full.metrics,
        warnings: full.warnings,
        walkForward: {
          inSample: split.inSample.metrics,
          outOfSample: split.outOfSample.metrics,
        },
        // Cap the payload: the full list can run to thousands of entries.
        trades: full.trades.slice(-50),
        equityCurve: full.equityCurve.filter(
          (_, i) => i % Math.ceil(full.equityCurve.length / 300 || 1) === 0,
        ),
        disclaimer:
          "Past performance on a limited sample says little about the future. Costs, slippage and survivorship all flatter backtests.",
      },
      200,
      60,
    );
  } catch (error) {
    return errorResponse(error);
  }
};
