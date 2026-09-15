/**
 * Watchlist bot.
 *
 * Polls a list of symbols and alerts only when the verdict CHANGES, so a
 * multi-day HOLD does not spam you. Run it on your own machine or any box with
 * cron; it needs no server.
 *
 *   npm run watch -- --symbols BTCUSDT,ETHUSDT,AAPL --interval 1h
 *   npm run watch -- --symbols BTCUSDT --webhook https://hooks.slack.com/... --once
 *
 * The webhook payload is a generic `{ text, signal }` JSON POST, which Slack
 * and Discord both accept, and which anything else can parse.
 */
import { fetchCandles } from "../src/lib/market.ts";
import { DEFAULT_CONFIG, latestSignal, type StrategyConfig } from "../src/lib/strategy.ts";
import type { Signal } from "../src/lib/types.ts";

interface Options {
  symbols: string[];
  interval: string;
  /** Seconds between polls. */
  every: number;
  webhook: string | null;
  once: boolean;
  /** Suppress repeat alerts for the same symbol within this many seconds. */
  cooldown: number;
  minConfidence: number;
}

function parseOptions(argv: string[]): Options {
  const args: Record<string, string | boolean> = {};
  for (let i = 0; i < argv.length; i++) {
    if (!argv[i].startsWith("--")) continue;
    const key = argv[i].slice(2);
    const next = argv[i + 1];
    if (next === undefined || next.startsWith("--")) args[key] = true;
    else {
      args[key] = next;
      i++;
    }
  }
  const str = (key: string, fallback: string) =>
    typeof args[key] === "string" ? (args[key] as string) : fallback;
  const numeric = (key: string, fallback: number) => {
    const value = Number(str(key, String(fallback)));
    return Number.isFinite(value) ? value : fallback;
  };

  return {
    symbols: str("symbols", "BTCUSDT")
      .split(",")
      .map((s) => s.trim())
      .filter(Boolean),
    interval: str("interval", "1h"),
    every: numeric("every", 300),
    webhook: typeof args.webhook === "string" ? args.webhook : null,
    once: Boolean(args.once),
    cooldown: numeric("cooldown", 3600),
    minConfidence: numeric("minConfidence", DEFAULT_CONFIG.minConfidence),
  };
}

function describe(signal: Signal): string {
  const lines = [
    `${signal.action} ${signal.symbol} ${signal.interval} @ ${signal.price}`,
    `Score ${signal.score.toFixed(3)}, confidence ${signal.confidence}%.`,
  ];
  if (signal.risk) {
    lines.push(
      `Stop ${signal.risk.stop}, targets ${signal.risk.targets.join(" / ")}, ` +
        `size ${signal.risk.positionSize} units at 1% risk.`,
    );
  }
  const top = [...signal.factors].sort((a, b) => Math.abs(b.score) - Math.abs(a.score)).slice(0, 3);
  lines.push(`Drivers: ${top.map((f) => `${f.label} ${f.score.toFixed(2)}`).join("; ")}.`);
  if (signal.warnings.length) lines.push(`Caveats: ${signal.warnings.join(" ")}`);
  return lines.join("\n");
}

async function notify(webhook: string | null, signal: Signal): Promise<void> {
  const text = describe(signal);
  console.log(`\n[${new Date().toISOString()}]\n${text}`);
  if (!webhook) return;
  try {
    const response = await fetch(webhook, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ text, signal }),
    });
    if (!response.ok) console.error(`Webhook returned HTTP ${response.status}.`);
  } catch (error) {
    console.error(`Webhook failed: ${(error as Error).message}`);
  }
}

export interface WatchState {
  lastAction: string;
  lastAlertAt: number;
}

/**
 * Decide whether a signal is worth interrupting someone for.
 *
 * Three conditions, all required: the verdict actually changed, it is an
 * actionable verdict with enough confidence, and we have not just alerted on
 * this symbol. Pure so it can be tested without touching the network.
 */
export function shouldAlert(
  signal: Pick<Signal, "action" | "confidence">,
  previous: WatchState | undefined,
  rules: { minConfidence: number; cooldown: number },
  now = Date.now(),
): boolean {
  const changed = previous?.lastAction !== signal.action;
  const cooledDown = !previous || now - previous.lastAlertAt >= rules.cooldown * 1000;
  const actionable = signal.action !== "HOLD" && signal.confidence >= rules.minConfidence;
  return changed && actionable && cooledDown;
}

async function pollOnce(
  options: Options,
  strategy: StrategyConfig,
  state: Map<string, WatchState>,
): Promise<void> {
  for (const symbol of options.symbols) {
    try {
      const data = await fetchCandles({ symbol, interval: options.interval, limit: 500 });
      const signal = latestSignal(data.candles, strategy, { symbol, interval: options.interval });
      if (!signal) {
        console.error(`${symbol}: not enough history (${data.candles.length} candles).`);
        continue;
      }

      const previous = state.get(symbol);
      const now = Date.now();

      if (shouldAlert(signal, previous, options, now)) {
        await notify(options.webhook, signal);
        state.set(symbol, { lastAction: signal.action, lastAlertAt: now });
      } else {
        if (previous) state.set(symbol, { ...previous, lastAction: signal.action });
        else state.set(symbol, { lastAction: signal.action, lastAlertAt: 0 });
        console.log(
          `${new Date().toISOString()} ${symbol} ${signal.action} ` +
            `(score ${signal.score.toFixed(3)}, confidence ${signal.confidence}%) - no alert`,
        );
      }
    } catch (error) {
      console.error(`${symbol}: ${(error as Error).message}`);
    }
  }
}

async function main(): Promise<void> {
  const options = parseOptions(process.argv.slice(2));
  const strategy: StrategyConfig = { ...DEFAULT_CONFIG, minConfidence: options.minConfidence };
  const state = new Map<string, WatchState>();

  console.log(
    `Watching ${options.symbols.join(", ")} on ${options.interval}` +
      (options.once ? " (single pass)." : `, polling every ${options.every}s.`),
  );
  console.log(
    "Alerts fire when the verdict changes, not on every poll. " +
      "Nothing here is a prediction -- size positions so a wrong call is survivable.\n",
  );

  await pollOnce(options, strategy, state);
  if (options.once) return;

  setInterval(() => {
    void pollOnce(options, strategy, state);
  }, options.every * 1000);
}

const invokedDirectly = process.argv[1]?.endsWith("watch.ts") ?? false;
if (invokedDirectly) {
  main().catch((error: unknown) => {
    console.error(error instanceof Error ? error.message : error);
    process.exit(1);
  });
}
