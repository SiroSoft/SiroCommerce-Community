import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { BannerGrid } from "@/components/merchandising/BannerGrid";
import { UspStrip } from "@/components/merchandising/UspStrip";
import { FaqAccordion } from "@/components/merchandising/FaqAccordion";
import { hasSectionContent, readBannerItems } from "@/homepage/section-data";

vi.mock("next/link", () => ({ default: ({ children, ...props }: React.PropsWithChildren<{ href: string }>) => <a {...props} href={props.href}>{children}</a> }));

afterEach(cleanup);

describe("homepage banner grid", () => {
  it("renders up to four banners with optional media and calls to action", () => {
    const { container } = render(<BannerGrid locale="en" props={{ eyebrow: "Edit", title: "Banners", banners: [
      { title: "One", subtitle: "First", href: "/c/one", image: "/one.svg", image_alt: "One image", cta: { label: "Shop one", href: "/c/one" } },
      { title: "Two", href: "/c/two" },
    ] }} />);
    expect(screen.getByRole("heading", { level: 2, name: "Banners" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { level: 3, name: "One" })).toBeInTheDocument();
    expect(screen.getByAltText("One image")).toHaveAttribute("src", "/one.svg");
    expect(screen.getByRole("link", { name: "Shop one" })).toHaveAttribute("href", "/c/one");
    expect(container.querySelector('[data-count="2"]')).not.toBeNull();
  });

  it("renders nothing when the backend sends no usable banners", () => {
    const { container } = render(<BannerGrid locale="en" props={{ items: [] }} />);
    expect(container.firstChild).toBeNull();
    expect(hasSectionContent("banner_grid", { items: [] })).toBe(false);
  });

  it("drops unsafe URLs and entries without a title", () => {
    const items = readBannerItems({ items: [
      { title: "Safe", href: "javascript:alert(1)", image: "data:image/png;base64,xx" },
      { href: "/c/no-title" },
      { title: "External", href: "https://example.com" },
    ] });
    expect(items).toHaveLength(2);
    expect(items[0]?.href).toBeUndefined();
    expect(items[0]?.image).toBeUndefined();
    expect(items[1]?.href).toBe("https://example.com");
  });
});

describe("homepage USP strip", () => {
  it("renders vetted icons and copy inline", () => {
    const { container } = render(<UspStrip locale="en" props={{ items: [
      { title: "Fast delivery", copy: "Ships in 48h", icon: "truck" },
      { title: "Secure payment", copy: "Encrypted", icon: "shield" },
      { title: "Unknown icon", copy: "No glyph", icon: "script" },
    ] }} />);
    expect(screen.getByRole("heading", { level: 3, name: "Fast delivery" })).toBeInTheDocument();
    expect(container.querySelectorAll(".sf-sec-usp-icon svg")).toHaveLength(2);
    expect(container.querySelector('[data-count="3"]')).not.toBeNull();
  });

  it("renders nothing without points", () => {
    const { container } = render(<UspStrip locale="en" props={{ items: [] }} />);
    expect(container.firstChild).toBeNull();
  });
});

describe("homepage FAQ accordion", () => {
  it("starts closed, toggles with the summary, and emits FAQPage JSON-LD", () => {
    const { container } = render(<FaqAccordion locale="en" props={{ items: [
      { question: "Is shipping free?", answer: "On qualifying orders." },
      { question: "Can I return it?", answer: "Yes, within 30 days." },
    ] }} />);
    const details = container.querySelector("details");
    expect(details).not.toBeNull();
    expect(details).not.toHaveAttribute("open");
    const summary = screen.getByText("Is shipping free?");
    fireEvent.click(summary);
    expect(details).toHaveAttribute("open");
    fireEvent.click(summary);
    expect(details).not.toHaveAttribute("open");
    const script = container.querySelector('script[type="application/ld+json"]');
    expect(script?.textContent).toContain('"@type":"FAQPage"');
    expect(script?.textContent).toContain("Is shipping free?");
    expect(script?.textContent).toContain("Can I return it?");
  });

  it("renders nothing and no schema when no FAQ entries exist", () => {
    const { container } = render(<FaqAccordion locale="en" props={{ items: [{ question: "", answer: "" }] }} />);
    expect(container.firstChild).toBeNull();
    expect(container.querySelector('script[type="application/ld+json"]')).toBeNull();
  });
});
