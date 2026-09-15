/**
 * Command-line backtest runner.
 *
 *   npm run backtest -- --symbol BTCUSDT --interval 1h --limit 1000
 *   npm run backtest -- --demo            # deterministic synthetic data
 *
 * Runs on Node 22+ via its built-in TypeScript stripping; no build step.
 */
import { backtest, walkForward, type BacktestResult } from "../src/lib/backtest.ts";
import { barsPerYear, fetchCandles } from "../src/lib/market.ts";
import { DEFAULT_CONFIG, type StrategyConfig } from "../src/lib/strategy.ts";
import { generateCandles } from "../src/lib/synthetic.ts";
import type { Candle } from "../src/lib/types.ts";

function parseArgs(argv: string[]): Record<string, string | boolean> {
  const args: Record<string, string | boolean> = {};
  for (let i = 0; i < argv.length; i++) {
    const token = argv[i];
    if (!token.startsWith("--")) continue;
    const key = token.slice(2);
    const next = argv[i + 1];
    if (next === undefined || next.startsWith("--")) args[key] = true;
    else {
      args[key] = next;
      i++;
    }
  }
  return args;
}

function numberArg(args: Record<string, string | boolean>, key: string, fallback: number): number {
  const raw = args[key];
  if (raw === undefined || typeof raw === "boolean") return fallback;
  const value = Number(raw);
  return Number.isFinite(value) ? value : fallback;
}

function formatMetrics(label: string, result: BacktestResult): string {
  const m = result.metrics;
  const pf = Number.isFinite(m.profitFactor) ? m.profitFactor.toFixed(2) : "inf";
  return [
    `\n${label}`,
    "-".repeat(label.length),
    `Trades            ${m.trades}  (${m.wins}W / ${m.losses}L)`,
    `Win rate          ${m.winRate.toFixed(1)}%`,
    `Profit factor     ${pf}`,
    `Expectancy        ${m.expectancyR.toFixed(3)} R per trade`,
    `Total return      ${m.totalReturnPct.toFixed(2)}%`,
    `CAGR              ${m.cagrPct.toFixed(2)}%`,
    `Max drawdown      ${m.maxDrawdownPct.toFixed(2)}%`,
    `Sharpe            ${m.sharpe.toFixed(2)}`,
    `Time in market    ${m.exposurePct.toFixed(1)}%`,
    `Buy & hold        ${m.buyHoldReturnPct.toFixed(2)}%`,
  ].join("\n");
}

async function main(): Promise<void> {
  const args = parseArgs(process.argv.slice(2));
  const demo = Boolean(args.demo);
  const symbol = typeof args.symbol === "string" ? args.symbol : "BTCUSDT";
  const interval = typeof args.interval === "string" ? args.interval : "1h";
  const limit = numberArg(args, "limit", 1000);

  const strategy: StrategyConfig = {
    ...DEFAULT_CONFIG,
    entryThreshold: numberArg(args, "entryThreshold", DEFAULT_CONFIG.entryThreshold),
    minConfidence: numberArg(args, "minConfidence", DEFAULT_CONFIG.minConfidence),
    minAdx: numberArg(args, "minAdx", DEFAULT_CONFIG.minAdx),
    atrStopMultiple: numberArg(args, "atrStopMultiple", DEFAULT_CONFIG.atrStopMultiple),
    riskPerTrade: numberArg(args, "riskPerTrade", DEFAULT_CONFIG.riskPerTrade),
  };

  let candles: Candle[];
  if (demo) {
    candles = generateCandles({ bars: Math.max(limit, 2000), seed: numberArg(args, "seed", 42) });
    console.log(`Using synthetic data: ${candles.length} bars (seed ${numberArg(args, "seed", 42)}).`);
  } else {
    console.log(`Fetching ${limit} ${interval} candles for ${symbol}...`);
    const data = await fetchCandles({ symbol, interval, limit });
    candles = data.candles;
    console.log(`Provider: ${data.provider}. ${candles.length} closed candles.`);
  }

  const config = {
    feeBps: numberArg(args, "feeBps", 10),
    slippageBps: numberArg(args, "slippageBps", 5),
    allowShorts: Boolean(args.allowShorts),
    barsPerYear: demo ? barsPerYear("1h") : barsPerYear(interval),
  };

  const full = backtest(candles, strategy, config);
  console.log(formatMetrics(`ALL DATA (${demo ? "synthetic" : `${symbol} ${interval}`})`, full));

  const { inSample, outOfSample } = walkForward(candles, strategy, config);
  console.log(formatMetrics("IN SAMPLE (first 60%)", inSample));
  console.log(formatMetrics("OUT OF SAMPLE (last 40%)", outOfSample));

  const warnings = [...new Set([...full.warnings, ...inSample.warnings, ...outOfSample.warnings])];
  if (warnings.length) {
    console.log("\nWarnings");
    console.log("--------");
    for (const warning of warnings) console.log(`- ${warning}`);
  }

  console.log(
    "\nRead this honestly: an edge that shows up in sample but not out of sample is " +
      "curve fitting, and fewer than ~30 trades is noise either way.",
  );
}

main().catch((error: unknown) => {
  console.error(error instanceof Error ? error.message : error);
  process.exit(1);
});
