import { describe, expect, it } from "vitest";
import { formatMinor, minorUnit } from "../lib/money.js";

describe("formatMinor", () => {
  it("formats VND with 0 decimals", () => {
    expect(formatMinor(100000, "VND", "en")).toContain("100,000");
  });
  it("formats USD minor units without float arithmetic", () => {
    expect(formatMinor(6800, "USD", "en-US")).toBe("$68.00");
  });
  it("rejects non-integers (no float money)", () => {
    expect(() => formatMinor(10.5)).toThrow();
  });
  it("rejects negatives", () => {
    expect(() => formatMinor(-1)).toThrow();
  });
  it("uses ISO minor units and rejects unsupported currencies", () => {
    expect(minorUnit("JPY")).toBe(0);
    expect(formatMinor(6800, "JPY", "ja-JP")).toContain("6,800");
    expect(minorUnit("KWD")).toBe(3);
    expect(formatMinor(1234, "KWD", "en-US")).toContain("1.234");
    expect(() => formatMinor(100, "BTC")).toThrow("Unsupported currency");
  });
});
