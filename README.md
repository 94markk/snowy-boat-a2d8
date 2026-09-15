# Trading Signal Bot

A transparent, rule-based market signal engine with a backtester attached, running
on Astro + Cloudflare Workers.

## Read this before anything else

**This cannot predict prices, and neither can anything else.** If a public bot
could reliably forecast markets, the edge would be arbitraged away within days of
anyone noticing. Be sceptical of any tool that claims otherwise, including ones
that cost money.

What this project does instead is narrower and actually achievable:

- It applies **six fixed rules** to price history and shows you exactly how each
  one voted, so the output is an argument you can inspect and disagree with,
  rather than a number you have to trust.
- It ships a **backtester** that replays those same rules bar by bar, pays fees
  and slippage on every fill, and splits history so you can see whether an
  apparent edge survives on data the settings were not chosen on.
- It refuses to flatter itself: signals are computed only on **closed** candles,
  entries fill on the bar **after** the signal, and when a bar touches both stop
  and target the **stop** is assumed to hit first.

Use it to structure your own thinking and to test ideas honestly. Do not use it
as an oracle, and never risk money you cannot afford to lose. Nothing here is
financial advice.

## What is in the box

| Path | What it does |
| :--- | :--- |
| `src/lib/indicators.ts` | EMA, RSI, MACD, ATR, ADX, Bollinger, Donchian. Aligned, null-padded series. |
| `src/lib/strategy.ts` | The six-factor scoring engine and the risk plan (stop, targets, position size). |
| `src/lib/backtest.ts` | Event-driven backtester, cost model, metrics, walk-forward split. |
| `src/lib/market.ts` | Data providers: Binance for crypto, Yahoo Finance for everything else. |
| `src/lib/synthetic.ts` | Seeded synthetic price series, for tests and offline demos. |
| `src/pages/signals.astro` | The dashboard. |
| `src/pages/api/` | `GET /api/signal` and `GET /api/backtest`. |
| `scripts/backtest.ts` | Command-line backtest runner. |
| `scripts/watch.ts` | The bot proper: polls a watchlist and alerts on verdict changes. |

## Quick start

```bash
npm install
npm run dev          # dashboard at http://localhost:4321/signals
npm test             # 40 tests, including a no-lookahead property test
```

Try it with no network and no API keys:

```bash
npm run backtest -- --demo --allowShorts
```

## The six rules

Each scores from -1 (bearish) to +1 (bullish); the verdict is the weighted
average. A trade is only proposed when the composite clears a threshold **and**
confidence clears a floor **and** ADX says the market is actually trending.

| Rule | Weight | What it measures |
| :--- | -----: | :--- |
| Trend (EMA 50/200) | 26% | Separation of the moving averages, measured in ATR units |
| Momentum (MACD) | 22% | Histogram size and whether it is expanding |
| RSI (14) | 16% | Read relative to the trend: a pullback in an uptrend is an opportunity |
| Breakout | 16% | Close versus the prior 20-bar range |
| Bollinger %B | 10% | Deliberately *opposes* stretched moves, as a brake on chasing |
| Volume | 10% | Whether the bar's volume confirms its direction |

Confidence blends three things: the strength of the composite score, how much
the six rules agree with each other, and ADX trend strength. **It is not a
probability of profit** — no such number is available.

## Running the bot

```bash
# Poll a watchlist, print alerts when the verdict changes
npm run watch -- --symbols BTCUSDT,ETHUSDT,AAPL --interval 1h

# Post alerts to Slack, Discord, or any JSON endpoint
npm run watch -- --symbols BTCUSDT --webhook https://hooks.slack.com/services/...

# One pass and exit, for cron
npm run watch -- --symbols BTCUSDT --once
```

It alerts only when the verdict *changes*, and respects a cooldown, so a
three-day HOLD does not page you seventy times.

## Judging a backtest honestly

```bash
npm run backtest -- --symbol BTCUSDT --interval 1h --limit 1000 --allowShorts
```

Read the output in this order, and stop at the first failure:

1. **Out-of-sample trade count.** Under ~30 trades, the result is noise. Nothing
   below matters.
2. **Out-of-sample expectancy.** If it is positive in sample and negative out of
   sample, the parameters are curve-fitted. This is the normal outcome, and the
   honest response is to discard the idea rather than tune it until it passes.
3. **Buy & hold.** A strategy that trades constantly to underperform holding the
   asset is an expensive hobby.
4. **Max drawdown.** Ask whether you would have kept going through it. Most
   people overestimate this about themselves.

Costs matter enormously at short intervals. The defaults (10 bps fee, 5 bps
slippage per side) are realistic for liquid crypto and optimistic for everything
else. Raise them and see what survives.

## API

```
GET /api/signal?symbol=BTCUSDT&interval=1h
GET /api/backtest?symbol=BTCUSDT&interval=1h&limit=1000&allowShorts=1
```

Both accept `demo=1` to use the synthetic series with no upstream call, plus
`entryThreshold`, `minConfidence`, `minAdx`, `atrStopMultiple`, `riskPerTrade`
and `accountSize` to override the defaults.

Crypto pairs (`BTCUSDT`) route to Binance; anything else (`AAPL`, `SPY`,
`EURUSD=X`) routes to Yahoo Finance. Both are free, keyless, rate-limited, and
can change without notice — swap in a paid feed by implementing one function in
`src/lib/market.ts`.

## Known limits

- **One position at a time, no pyramiding, no partial exits.**
- **Backtests assume your order fills.** In thin books it may not, or not at
  that price.
- **No survivorship-bias correction** on stock tickers.
- **Yahoo intraday history is short**, so intraday stock backtests will be
  small-sample by construction.
- **The engine is trend-following at heart.** It will underperform badly in
  long sideways markets, and the ADX filter only partly protects against that.

---

# Astro Starter Kit: Blog

![Astro Template Preview](https://github.com/withastro/astro/assets/2244813/ff10799f-a816-4703-b967-c78997e8323d)

<!-- dash-content-start -->

Create a blog with Astro and deploy it on Cloudflare Workers as a [static website](https://developers.cloudflare.com/workers/static-assets/).

Features:

- ✅ Minimal styling (make it your own!)
- ✅ 100/100 Lighthouse performance
- ✅ SEO-friendly with canonical URLs and OpenGraph data
- ✅ Sitemap support
- ✅ RSS Feed support
- ✅ Markdown & MDX support

<!-- dash-content-end -->

## Getting Started

Outside of this repo, you can start a new project with this template using [C3](https://developers.cloudflare.com/pages/get-started/c3/) (the `create-cloudflare` CLI):

```bash
npm create cloudflare@latest -- --template=cloudflare/templates/snowy-boat-a2d8
```

A live public deployment of this template is available at [https://snowy-boat-a2d8.templates.workers.dev](https://snowy-boat-a2d8.templates.workers.dev)

## 🚀 Project Structure

Astro looks for `.astro` or `.md` files in the `src/pages/` directory. Each page is exposed as a route based on its file name.

There's nothing special about `src/components/`, but that's where we like to put any Astro/React/Vue/Svelte/Preact components.

The `src/content/` directory contains "collections" of related Markdown and MDX documents. Use `getCollection()` to retrieve posts from `src/content/blog/`, and type-check your frontmatter using an optional schema. See [Astro's Content Collections docs](https://docs.astro.build/en/guides/content-collections/) to learn more.

Any static assets, like images, can be placed in the `public/` directory.

## 🧞 Commands

All commands are run from the root of the project, from a terminal:

| Command                   | Action                                           |
| :------------------------ | :----------------------------------------------- |
| `npm install`             | Installs dependencies                            |
| `npm run dev`             | Starts local dev server at `localhost:4321`      |
| `npm run build`           | Build your production site to `./dist/`          |
| `npm run preview`         | Preview your build locally, before deploying     |
| `npm run astro ...`       | Run CLI commands like `astro add`, `astro check` |
| `npm run astro -- --help` | Get help using the Astro CLI                     |
| `npm run deploy`          | Deploy your production site to Cloudflare        |

## 👀 Want to learn more?

Check out [our documentation](https://docs.astro.build) or jump into our [Discord server](https://astro.build/chat).

## Credit

This theme is based off of the lovely [Bear Blog](https://github.com/HermanMartinus/bearblog/).
