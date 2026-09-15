import type { APIRoute } from "astro";
import { errorResponse, json, parseQuery } from "../../lib/api.ts";
import { fetchCandles } from "../../lib/market.ts";
import { latestSignal } from "../../lib/strategy.ts";
import { generateCandles } from "../../lib/synthetic.ts";
import type { Candle } from "../../lib/types.ts";

export const prerender = false;

/**
 * GET /api/signal?symbol=BTCUSDT&interval=1h
 *
 * Returns the signal for the most recently CLOSED candle, plus the factor
 * breakdown behind it. The breakdown is the point: a score you cannot inspect
 * is a score you cannot judge.
 */
export const GET: APIRoute = async ({ url }) => {
  try {
    const query = parseQuery(url);

    let candles: Candle[];
    let provider: string;
    if (query.demo) {
      candles = generateCandles({ bars: query.limit, seed: 42 });
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

    const signal = latestSignal(candles, query.strategy, {
      symbol: query.symbol.toUpperCase(),
      interval: query.interval,
    });

    if (!signal) {
      return json(
        {
          error:
            `Not enough history to evaluate ${query.symbol}. ` +
            `Received ${candles.length} candles; the 200-period EMA alone needs 200.`,
        },
        422,
      );
    }

    return json(
      {
        signal,
        provider,
        candlesUsed: candles.length,
        // Closing prices for the dashboard sparkline.
        recentCloses: candles.slice(-120).map((c) => ({ time: c.time, close: c.close })),
        disclaimer:
          "Educational tool. This is a rule-based reading of past prices, not a prediction and not financial advice.",
      },
      200,
      // Short cache: the signal only changes when a new candle closes, and this
      // keeps the free upstream providers from rate limiting you.
      30,
    );
  } catch (error) {
    return errorResponse(error);
  }
};
