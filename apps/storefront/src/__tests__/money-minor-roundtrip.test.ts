import { describe, expect, it } from "vitest";
import { fromMinorUnits, toMinorUnits } from "../lib/money.js";

describe("currency minor-unit conversion", () => {
  it("round-trips zero-decimal currencies without multiplying by 100", () => {
    expect(toMinorUnits("100000", "VND")).toBe(100000);
    expect(fromMinorUnits(100000, "VND")).toBe(100000);
    expect(toMinorUnits("6800", "JPY")).toBe(6800);
    expect(fromMinorUnits(6800, "JPY")).toBe(6800);
  });

  it("round-trips two-decimal currencies", () => {
    expect(toMinorUnits("12.50", "USD")).toBe(1250);
    expect(fromMinorUnits(1250, "USD")).toBe(12.5);
    expect(toMinorUnits("0.99", "EUR")).toBe(99);
    expect(fromMinorUnits(99, "EUR")).toBe(0.99);
  });
});
