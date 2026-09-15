import { describe, expect, it } from "vitest";
import { shouldAlert, type WatchState } from "../../../scripts/watch.ts";

const rules = { minConfidence: 45, cooldown: 3600 };
const now = 1_700_000_000_000;

describe("shouldAlert", () => {
  it("alerts on the first actionable verdict for a symbol", () => {
    expect(shouldAlert({ action: "BUY", confidence: 70 }, undefined, rules, now)).toBe(true);
  });

  it("stays quiet while the verdict is unchanged", () => {
    const previous: WatchState = { lastAction: "BUY", lastAlertAt: now - 10_000 };
    expect(shouldAlert({ action: "BUY", confidence: 90 }, previous, rules, now)).toBe(false);
  });

  it("never alerts on HOLD", () => {
    expect(shouldAlert({ action: "HOLD", confidence: 99 }, undefined, rules, now)).toBe(false);
  });

  it("ignores actionable verdicts below the confidence floor", () => {
    expect(shouldAlert({ action: "SELL", confidence: 20 }, undefined, rules, now)).toBe(false);
  });

  it("honours the cooldown even when the verdict flips", () => {
    const previous: WatchState = { lastAction: "BUY", lastAlertAt: now - 60_000 };
    expect(shouldAlert({ action: "SELL", confidence: 80 }, previous, rules, now)).toBe(false);
  });

  it("alerts on a flip once the cooldown has elapsed", () => {
    const previous: WatchState = { lastAction: "BUY", lastAlertAt: now - 3_600_001 };
    expect(shouldAlert({ action: "SELL", confidence: 80 }, previous, rules, now)).toBe(true);
  });
});
