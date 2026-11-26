import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

const css = readFileSync(join(process.cwd(), "src/app/globals.css"), "utf8");
const rootEnd = css.indexOf("}", css.indexOf(":root {"));
const outsideRoot = css.slice(rootEnd + 1);

describe("storefront craft-floor design system", () => {
  it("ships the spacing, type, shadow and measure tokens", () => {
    for (const token of ["--space-1", "--space-6", "--space-12", "--text-xs", "--text-3xl", "--leading-loose", "--shadow-sm", "--shadow-md", "--shadow-lg", "--measure"]) {
      expect(css).toContain(`${token}:`);
    }
    expect(css).toMatch(/--measure:\s*6[5-9]ch|--measure:\s*7[0-5]ch/);
  });

  it("raises small type to a readable floor", () => {
    const sizes = [...outsideRoot.matchAll(/font-size:\s*(\d*\.?\d+)rem/g)].map((m) => Number(m[1]));
    expect(sizes.filter((value) => value < 0.75)).toEqual([]);
    expect(css).toContain("font-size: var(--text-xs)");
    expect(css).toContain("font-size: var(--text-sm)");
  });

  it("keeps responsive breakpoints in descending order", () => {
    const first1180 = css.indexOf("@media (max-width: 1180px)");
    const first1100 = css.indexOf("@media (max-width: 1100px)");
    expect(first1180).toBeGreaterThan(-1);
    expect(first1100).toBeGreaterThan(first1180);
  });

  it("covers native browser surfaces and accessible focus", () => {
    for (const marker of ["scrollbar-width", "::-webkit-scrollbar", "caret-color", "font-variant-numeric: tabular-nums", "text-wrap: balance", "text-wrap: pretty", "font-synthesis: none", ":focus-visible", "prefers-reduced-motion:reduce"]) {
      expect(css).toContain(marker);
    }
  });

  it("keeps hard-coded hex colours inside :root", () => {
    expect(outsideRoot).not.toMatch(/#[0-9a-fA-F]{3,8}\b/);
  });
});
