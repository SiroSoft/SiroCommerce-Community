import { describe, expect, it } from "vitest";
import { fill, getCopy, type Locale } from "@/lib/i18n";

describe("i18n fallback and interpolation", () => {
  it("falls back to English for an unsupported locale at runtime", () => {
    const fallback = getCopy("fr" as Locale);
    expect(fallback).toBe(getCopy("en"));
    expect(fallback.home.title).toBe(getCopy("en").home.title);
  });

  it("fills every placeholder and leaves unknown placeholders untouched", () => {
    expect(fill("Hello {name}, you have {count} items", { name: "Ann", count: 2 })).toBe("Hello Ann, you have 2 items");
    expect(fill("Hello {name}", {})).toBe("Hello {name}");
  });

  it("treats a missing locale argument as the configured default", () => {
    expect(getCopy()).toBe(getCopy("en"));
  });
});
