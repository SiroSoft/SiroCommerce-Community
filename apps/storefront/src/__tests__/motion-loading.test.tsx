import "@testing-library/jest-dom/vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { Reveal } from "@/components/Reveal";
import { CollectionDirectory } from "@/components/merchandising/CollectionDirectory";
import { products } from "@/services/catalog";

afterEach(() => {
  document.body.innerHTML = "";
  document.documentElement.classList.remove("reveal-armed");
  vi.unstubAllGlobals();
});

function mockIntersectionObserver() {
  const observed: Element[] = [];
  let callback: IntersectionObserverCallback = () => undefined;
  vi.stubGlobal("IntersectionObserver", class {
    constructor(next: IntersectionObserverCallback) { callback = next; }
    observe(node: Element) { observed.push(node); }
    disconnect() { observed.length = 0; }
    unobserve() { /* noop */ }
  });
  return {
    trigger() {
      callback(observed.map((target) => ({ target, isIntersecting: true }) as IntersectionObserverEntry), {} as IntersectionObserver);
    },
  };
}

describe("flagship motion and loading states", () => {
  it("keeps section content visible until the observer fires, then reveals", async () => {
    const io = mockIntersectionObserver();
    render(<Reveal><h2>Heading</h2></Reveal>);
    const wrapper = screen.getByText("Heading").parentElement!;
    expect(wrapper.className).toContain("reveal");
    expect(document.documentElement.classList.contains("reveal-armed")).toBe(true);
    io.trigger();
    await waitFor(() => expect(wrapper.className).toContain("is-visible"));
  });

  it("renders collection tiles inside a reveal wrapper without changing links", () => {
    mockIntersectionObserver();
    render(<CollectionDirectory products={products} />);
    expect(screen.getByRole("heading", { name: /find your kind/i })).toBeInTheDocument();
    expect(screen.getAllByRole("link")[0]?.closest(".reveal")).not.toBeNull();
  });

  it("ships the motion system CSS with reduced-motion guards", () => {
    const css = readFileSync(join(process.cwd(), "src", "app", "globals.css"), "utf8");
    for (const marker of ["@keyframes image-settle", "@keyframes hero-drift", "@keyframes hero-rise", "@keyframes card-in", "@keyframes filter-expand", "@keyframes spin-once", ".reveal-armed .reveal:not(.is-visible)", ".button.is-loading::after", ".button:active", ".skeleton-card"]) {
      expect(css).toContain(marker);
    }
    expect(css).toMatch(/prefers-reduced-motion:reduce/);
  });

  it("lazy-loads collection imagery below the fold", () => {
    mockIntersectionObserver();
    const { container } = render(<CollectionDirectory products={products} />);
    const images = [...container.querySelectorAll("img")];
    expect(images.length).toBeGreaterThan(0);
    for (const image of images) {
      expect(image.getAttribute("loading")).toBe("lazy");
      expect(image.getAttribute("decoding")).toBe("async");
    }
  });
});
