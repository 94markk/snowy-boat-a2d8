import type { Signal } from "../lib/types.ts";

/**
 * Client-side rendering for the signals dashboard.
 *
 * Everything is built with `textContent` rather than `innerHTML` so a symbol
 * echoed back by the API can never inject markup into the page.
 */

interface SignalResponse {
  signal: Signal;
  provider: string;
  candlesUsed: number;
  recentCloses: { time: number; close: number }[];
}

interface Metrics {
  trades: number;
  wins: number;
  losses: number;
  winRate: number;
  profitFactor: number;
  expectancyR: number;
  totalReturnPct: number;
  cagrPct: number;
  maxDrawdownPct: number;
  sharpe: number;
  buyHoldReturnPct: number;
  exposurePct: number;
}

interface BacktestResponse {
  symbol: string;
  interval: string;
  provider: string;
  candlesUsed: number;
  period: { from: string; to: string } | null;
  costs: { feeBps: number; slippageBps: number; allowShorts: boolean };
  metrics: Metrics;
  warnings: string[];
  walkForward: { inSample: Metrics; outOfSample: Metrics };
  trades: {
    direction: string;
    entryTime: number;
    exitTime: number;
    entryPrice: number;
    exitPrice: number;
    rMultiple: number;
    pnl: number;
    exitReason: string;
  }[];
}

/**
 * Append children to a node.
 *
 * `node.append(...)` cannot be used here: this project loads
 * `@cloudflare/workers-types` globally, and its HTMLRewriter `Element` shadows
 * the DOM `Element`, so the inherited `append` has the wrong signature.
 */
function add<T extends Node>(parent: T, ...children: (Node | string)[]): T {
  for (const child of children) {
    parent.appendChild(typeof child === "string" ? document.createTextNode(child) : child);
  }
  return parent;
}

function el<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  className?: string,
  text?: string,
): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function formatNumber(value: number, digits = 2): string {
  if (!Number.isFinite(value)) return "∞";
  return value.toLocaleString(undefined, { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

function formatDate(ms: number): string {
  return new Date(ms).toLocaleString(undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

/** A signed bar centred on zero, so -1 and +1 are mirror images. */
function meter(score: number): HTMLElement {
  const wrap = el("div", "meter");
  const bar = el("span", score >= 0 ? "positive" : "negative");
  const magnitude = Math.min(1, Math.abs(score)) * 50;
  bar.style.left = score >= 0 ? "50%" : `${50 - magnitude}%`;
  bar.style.width = `${magnitude}%`;
  add(wrap, bar);
  return wrap;
}

function sparkline(points: { close: number }[]): SVGSVGElement | null {
  if (points.length < 2) return null;
  const values = points.map((p) => p.close);
  const min = Math.min(...values);
  const max = Math.max(...values);
  const range = max - min || 1;
  const width = 600;
  const height = 90;
  const path = values
    .map((value, i) => {
      const x = (i / (values.length - 1)) * width;
      const y = height - ((value - min) / range) * (height - 8) - 4;
      return `${i === 0 ? "M" : "L"}${x.toFixed(2)},${y.toFixed(2)}`;
    })
    .join(" ");

  const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
  svg.setAttribute("class", "sparkline");
  svg.setAttribute("viewBox", `0 0 ${width} ${height}`);
  svg.setAttribute("preserveAspectRatio", "none");
  svg.setAttribute("role", "img");
  svg.setAttribute(
    "aria-label",
    `Closing prices for the last ${values.length} candles, from ${min} to ${max}.`,
  );
  const pathNode = document.createElementNS("http://www.w3.org/2000/svg", "path");
  pathNode.setAttribute("d", path);
  add(svg, pathNode);
  return svg;
}

function definition(term: string, value: string): HTMLElement {
  const fragment = el("div");
  add(fragment, el("dt", undefined, term), el("dd", undefined, value));
  return fragment;
}

export function renderSignal(container: HTMLElement, data: SignalResponse): void {
  const { signal } = data;
  container.replaceChildren();

  const chart = sparkline(data.recentCloses);
  if (chart) add(container, chart);

  const verdict = el("div", "verdict");
  add(verdict, el("div", `action ${signal.action}`, signal.action));

  const list = el("dl");
  add(list, 
    definition("Symbol", `${signal.symbol} ${signal.interval}`),
    definition("Price", formatNumber(signal.price, signal.price >= 1000 ? 2 : 4)),
    definition("Score", formatNumber(signal.score, 3)),
    definition("Confidence", `${signal.confidence}%`),
    definition("Candle closed", formatDate(signal.time)),
  );
  add(verdict, list);

  const note = el(
    "p",
    "verdict-note",
    signal.action === "HOLD"
      ? "No trade: the rules do not agree strongly enough to justify one. Most bars should look like this."
      : `Confidence blends the strength of the score, how much the six rules agree, and how strongly the market is trending. It is not a probability of profit.`,
  );
  add(verdict, note);
  add(container, verdict);

  if (signal.warnings.length > 0) {
    add(container, el("h2", undefined, "Caveats on this reading"));
    const warnings = el("ul", "warnings");
    for (const warning of signal.warnings) add(warnings, el("li", undefined, warning));
    add(container, warnings);
  }

  add(container, el("h2", undefined, "How each rule voted"));
  const table = el("table", "data");
  const head = el("thead");
  const headRow = el("tr");
  for (const label of ["Rule", "Weight", "Score", "", "Why"]) {
    add(headRow, el("th", undefined, label));
  }
  add(head, headRow);
  add(table, head);

  const body = el("tbody");
  for (const factor of signal.factors) {
    const row = el("tr");
    add(row, el("td", undefined, factor.label));
    add(row, el("td", "num", `${Math.round(factor.weight * 100)}%`));
    add(row, el("td", "num", formatNumber(factor.score, 2)));
    const meterCell = el("td");
    add(meterCell, meter(factor.score));
    add(row, meterCell);
    add(row, el("td", undefined, factor.reason));
    add(body, row);
  }
  add(table, body);
  add(container, table);

  if (signal.risk) {
    add(container, el("h2", undefined, "If you take this trade"));
    const risk = el("table", "data");
    const riskBody = el("tbody");
    const rows: [string, string][] = [
      ["Entry", formatNumber(signal.risk.entry, 4)],
      ["Stop loss", `${formatNumber(signal.risk.stop, 4)} (1.5 x ATR)`],
      ["Targets (1R / 2R / 3R)", signal.risk.targets.map((t) => formatNumber(t, 4)).join("  /  ")],
      ["Risk per unit", formatNumber(signal.risk.riskPerUnit, 4)],
      ["Position size", `${formatNumber(signal.risk.positionSize, 4)} units (1% of a 10,000 account)`],
    ];
    for (const [label, value] of rows) {
      const row = el("tr");
      add(row, el("th", undefined, label), el("td", "num", value));
      add(riskBody, row);
    }
    add(risk, riskBody);
    add(container, risk);
    add(container, 
      el(
        "p",
        "verdict-note",
        "Levels are derived from current volatility (ATR), not from a forecast. The stop is the part that matters: it caps the loss when the reading is wrong, which it often will be.",
      ),
    );
  }
}

function metricsTable(title: string, sets: { label: string; metrics: Metrics }[]): HTMLElement {
  const wrap = el("div");
  add(wrap, el("h3", undefined, title));
  const table = el("table", "data");

  const head = el("thead");
  const headRow = el("tr");
  add(headRow, el("th", undefined, "Metric"));
  for (const set of sets) add(headRow, el("th", undefined, set.label));
  add(head, headRow);
  add(table, head);

  const rows: [string, (m: Metrics) => string][] = [
    ["Trades", (m) => `${m.trades} (${m.wins}W / ${m.losses}L)`],
    ["Win rate", (m) => `${formatNumber(m.winRate, 1)}%`],
    ["Profit factor", (m) => formatNumber(m.profitFactor, 2)],
    ["Expectancy", (m) => `${formatNumber(m.expectancyR, 2)} R`],
    ["Total return", (m) => `${formatNumber(m.totalReturnPct, 2)}%`],
    ["Buy & hold", (m) => `${formatNumber(m.buyHoldReturnPct, 2)}%`],
    ["Max drawdown", (m) => `${formatNumber(m.maxDrawdownPct, 2)}%`],
    ["Sharpe", (m) => formatNumber(m.sharpe, 2)],
    ["Time in market", (m) => `${formatNumber(m.exposurePct, 1)}%`],
  ];

  const body = el("tbody");
  for (const [label, accessor] of rows) {
    const row = el("tr");
    add(row, el("th", undefined, label));
    for (const set of sets) add(row, el("td", "num", accessor(set.metrics)));
    add(body, row);
  }
  add(table, body);
  add(wrap, table);
  return wrap;
}

export function renderBacktest(container: HTMLElement, data: BacktestResponse): void {
  container.replaceChildren();

  const period = data.period
    ? `${new Date(data.period.from).toLocaleDateString()} to ${new Date(data.period.to).toLocaleDateString()}`
    : "unknown period";
  add(container, 
    el(
      "p",
      "verdict-note",
      `${data.symbol} ${data.interval}, ${data.candlesUsed} candles (${period}). ` +
        `Costs applied: ${data.costs.feeBps} bps fee and ${data.costs.slippageBps} bps slippage per side. ` +
        `Shorts ${data.costs.allowShorts ? "enabled" : "disabled"}.`,
    ),
  );

  add(container, 
    metricsTable("Results", [
      { label: "All data", metrics: data.metrics },
      { label: "In sample (first 60%)", metrics: data.walkForward.inSample },
      { label: "Out of sample (last 40%)", metrics: data.walkForward.outOfSample },
    ]),
  );

  // The in-sample vs out-of-sample gap is the honest headline number.
  const inSample = data.walkForward.inSample;
  const outOfSample = data.walkForward.outOfSample;
  const verdict = el("div");
  if (outOfSample.trades < 30) {
    verdict.className = "oos-warning";
    verdict.textContent =
      `Only ${outOfSample.trades} out-of-sample trades. That is too few to conclude anything at all. ` +
      "Test a longer history or a faster interval before drawing conclusions.";
  } else if (outOfSample.expectancyR <= 0) {
    verdict.className = "oos-warning";
    verdict.textContent =
      `Expectancy is ${formatNumber(inSample.expectancyR, 2)}R in sample but ` +
      `${formatNumber(outOfSample.expectancyR, 2)}R out of sample. The edge does not hold up on data ` +
      "the settings were not chosen on. Do not trade this.";
  } else {
    verdict.className = "oos-ok";
    verdict.textContent =
      `Expectancy holds up out of sample (${formatNumber(outOfSample.expectancyR, 2)}R over ` +
      `${outOfSample.trades} trades). That is encouraging, not proof: one symbol over one period is ` +
      "still a single sample, and live slippage is usually worse than modelled.";
  }
  add(container, verdict);

  if (data.warnings.length > 0) {
    const warnings = el("ul", "warnings");
    for (const warning of data.warnings) add(warnings, el("li", undefined, warning));
    add(container, warnings);
  }

  if (data.trades.length > 0) {
    add(container, el("h3", undefined, `Last ${data.trades.length} trades`));
    const table = el("table", "data");
    const head = el("thead");
    const headRow = el("tr");
    for (const label of ["Direction", "Entry", "Exit", "Entry price", "Exit price", "R", "Exit reason"]) {
      add(headRow, el("th", undefined, label));
    }
    add(head, headRow);
    add(table, head);

    const body = el("tbody");
    for (const trade of data.trades.slice().reverse()) {
      const row = el("tr");
      add(row, el("td", undefined, trade.direction));
      add(row, el("td", "num", formatDate(trade.entryTime)));
      add(row, el("td", "num", formatDate(trade.exitTime)));
      add(row, el("td", "num", formatNumber(trade.entryPrice, 4)));
      add(row, el("td", "num", formatNumber(trade.exitPrice, 4)));
      add(row, el("td", "num", formatNumber(trade.rMultiple, 2)));
      add(row, el("td", undefined, trade.exitReason));
      add(body, row);
    }
    add(table, body);
    add(container, table);
  }
}
