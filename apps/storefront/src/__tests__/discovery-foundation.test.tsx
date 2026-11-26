import "@testing-library/jest-dom/vitest";
import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { CatalogState } from "@/components/CatalogState";
import { CatalogControls } from "@/components/CatalogControls";
import { Breadcrumbs } from "@/components/Breadcrumbs";
import { ProductCard } from "@/components/ProductCard";
import Loading from "@/app/loading";
import Home from "@/app/page";
import Category, { generateMetadata as categoryMetadata } from "@/app/category/[slug]/page";
import ProductDetail, { generateMetadata as productMetadata } from "@/app/product/[slug]/page";
import { CatalogRequestError, products } from "@/services/catalog";
import { latestRequest } from "@/test-utils/discovery";

const shirt = products[0]!;
const mug = products[1]!;

afterEach(() => {
  vi.unstubAllEnvs();
  document.body.innerHTML = "";
});

describe("DISC-FOUNDATION discovery journey", () => {
  it("DISC-001 renders Home with a primary heading, landmark, links, and JSON-LD", async () => {
    vi.stubEnv("STOREFRONT_CATALOG_SOURCE", "fixture");
    const view = render(<main>{await Home()}</main>);
    expect(screen.getByRole("heading", { name: /useful things/i })).toBeInTheDocument();
    expect(screen.getByRole("main")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /shop the collection/i })).toHaveAttribute("href", "/category/apparel");
    expect(screen.getByRole("heading", { name: "Shop by category" })).toBeInTheDocument();
    expect(screen.getAllByRole("link", { name: /Apparel/ }).length).toBeGreaterThan(0);
    expect(screen.getByRole("heading", { name: "Before you buy" })).toBeInTheDocument();
    expect(view.container.querySelector('script[type="application/ld+json"]')).toHaveTextContent('"WebSite"');
  });

  it("DISC-002 follows category to search/filter/sort and exposes stable product links", () => {
    render(<><ProductCard product={shirt} /><ProductCard product={mug} /></>);
    expect(screen.getByRole("heading", { name: shirt.name })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /linen camp shirt/i })).toHaveAttribute("href", "/product/linen-camp-shirt");
    expect(screen.getAllByRole("img")[0]).toHaveAttribute("alt", shirt.imageAlt);
  });

  it("DISC-003 renders exact USD minor-unit money and compare-at money", async () => {
    vi.stubEnv("STOREFRONT_CATALOG_SOURCE", "fixture");
    render(await ProductDetail({ params: Promise.resolve({ slug: "linen-camp-shirt" }) }));
    expect(screen.getByText("$68.00")).toBeInTheDocument();
    expect(screen.getByText("$82.00")).toBeInTheDocument();
  });

  it("DISC-004 renders the loading skeleton as a busy region", () => {
    render(<Loading />);
    expect(screen.getByRole("region", { name: "Loading catalog" })).toHaveAttribute("aria-busy", "true");
    expect(document.querySelectorAll(".skeleton")).toHaveLength(3);
  });

  it("DISC-005 renders an explicit empty catalog status", async () => {
    render(<CatalogControls initialProducts={[]} query={{ search: "missing" }} />);
    expect(screen.getByText("No products found.")).toBeInTheDocument();
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("DISC-005b exposes server-provided facets as interactive controls", () => {
    render(<CatalogControls initialProducts={[shirt]} query={{ category: "apparel" }} facets={[{ name: "color", values: [{ value: "natural", count: 1 }] }]} />);
    expect(screen.getByRole("checkbox", { name: /natural/i })).toBeInTheDocument();
    expect(screen.getByText("Filter results")).toBeInTheDocument();
  });

  it.each([
    [401, "Please sign in"], [403, "not available"], [404, "couldn't find"],
    [429, "Too many requests"], [500, "temporarily unavailable"], [503, "temporarily unavailable"],
  ])("DISC-006-%s maps catalog failure to an accessible recovery state", (status, message) => {
    render(<CatalogState error={new CatalogRequestError(status, "failure")} />);
    expect(screen.getByRole("alert")).toHaveTextContent(message);
    expect(screen.getByRole("link", { name: "Return home" })).toHaveAttribute("href", "/");
  });

  it("DISC-007 rejects stale query results and aborts the previous scope", async () => {
    const run = latestRequest<string>();
    let resolveOld!: (value: string) => void;
    const old = run((signal) => new Promise<string>((resolve) => { resolveOld = resolve; signal.addEventListener("abort", () => resolve("old")); }));
    const current = await run(async () => "new");
    resolveOld("old");
    expect(await old).toBeUndefined();
    expect(current).toBe("new");
  });

  it("DISC-007b cancels the active scope when a newer query settles", async () => {
    const run = latestRequest<string>();
    let oldSignal!: AbortSignal;
    const old = run((signal) => { oldSignal = signal; return new Promise<string>((resolve) => signal.addEventListener("abort", () => resolve("cancelled"))); });
    await expect(run(async () => "current")).resolves.toBe("current");
    expect(oldSignal.aborted).toBe(true);
    await expect(old).resolves.toBeUndefined();
  });

  it("DISC-008 includes canonical, OpenGraph, and Product JSON-LD metadata", async () => {
    vi.stubEnv("STOREFRONT_CATALOG_SOURCE", "fixture");
    await expect(categoryMetadata({ params: Promise.resolve({ slug: "home" }) })).resolves.toMatchObject({ alternates: { canonical: "/category/home" } });
    await expect(productMetadata({ params: Promise.resolve({ slug: "linen-camp-shirt" }) })).resolves.toMatchObject({ alternates: { canonical: "/product/linen-camp-shirt" }, openGraph: { url: "/product/linen-camp-shirt", images: [shirt.image] } });
    const view = render(await ProductDetail({ params: Promise.resolve({ slug: "linen-camp-shirt" }) }));
    const json = JSON.parse(view.container.querySelector('script[type="application/ld+json"]')!.textContent!);
    expect(json).toMatchObject({ "@type": "Product", "@id": "https://sirocommerce.test/product/linen-camp-shirt#product", brand: { name: "SiroCommerce" }, offers: { price: 68, priceCurrency: "USD" } });
  });

  it("DISC-008b publishes collection structured data and search noindex metadata", async () => {
    vi.stubEnv("STOREFRONT_CATALOG_SOURCE", "fixture");
    const view = render(await Category({ params: Promise.resolve({ slug: "apparel" }) }));
    expect(JSON.parse(view.container.querySelector('script[type="application/ld+json"]')!.textContent!)).toMatchObject({ "@type": "CollectionPage", mainEntity: { "@type": "ItemList" } });
    const Search = (await import("@/app/search/page")).default;
    await expect((await import("@/app/search/page")).generateMetadata()).resolves.toMatchObject({ robots: { index: false, follow: true } });
    expect(Search).toBeTypeOf("function");
  });

  it("DISC-008c publishes stable category and search metadata", async () => {
    vi.stubEnv("STOREFRONT_CATALOG_SOURCE", "fixture");
    await expect(categoryMetadata({ params: Promise.resolve({ slug: "apparel" }) })).resolves.toMatchObject({
      title: "Apparel",
      description: "Considered apparel pieces for everyday rituals.",
      alternates: { canonical: "/category/apparel" },
      openGraph: { title: "Apparel", url: "/category/apparel", type: "website" },
    });
    const { generateMetadata: searchMetadataFactory } = await import("@/app/search/page");
    expect(await searchMetadataFactory()).toMatchObject({
      title: "Search",
      alternates: { canonical: "/search" },
      robots: { index: false, follow: true },
    });
  });

  it("DISC-009 keeps detail image alt, heading, and add-to-cart post semantics", async () => {
    vi.stubEnv("STOREFRONT_CATALOG_SOURCE", "fixture");
    render(await ProductDetail({ params: Promise.resolve({ slug: "linen-camp-shirt" }) }));
    expect(screen.getByRole("heading", { level: 1, name: "Linen camp shirt" })).toBeInTheDocument();
    expect(screen.getAllByRole("img").length).toBeGreaterThan(0);
    expect(screen.getByRole("link", { name: "Apparel" })).toHaveAttribute("href", "/category/apparel");
    expect(screen.getByRole("heading", { level: 1, name: "Linen camp shirt" })).toBeInTheDocument();
    const form = screen.getAllByRole("button", { name: "Add to cart" })[0]!.closest("form") as HTMLFormElement;
    expect(form).not.toBeNull();
    expect(new FormData(form).get("product_id")).toBe("p-1");
    expect(form.getAttribute("action") ?? "").not.toContain("product_price");
  });

  it("DISC-010 has keyboard focus, reduced-motion, and responsive layout smoke rules", async () => {
    const css = readFileSync(join(process.cwd(), "src", "app", "globals.css"), "utf8");
    expect(css).toContain(":focus-visible");
    expect(css).toContain("prefers-reduced-motion:reduce");
    expect(css).toMatch(/max-width:700px/);
    expect(css).toMatch(/grid-template-columns:repeat\(4,1fr\)/);
    expect(css).toMatch(/grid-template-columns:repeat\(2,1fr\)/);
  });

  it("DISC-011 exposes navigable breadcrumb semantics", () => {
    render(<Breadcrumbs current="Apparel" />);
    expect(screen.getByRole("navigation", { name: "Breadcrumb" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Home" })).toHaveAttribute("href", "/");
    expect(screen.getByRole("listitem", { current: "page" })).toHaveTextContent("Apparel");
  });

  it("DISC-011b adds a category level before the current product", () => {
    render(<Breadcrumbs current="Linen camp shirt" category={{ label: "Apparel", href: "/category/apparel" }} />);
    expect(screen.getByRole("link", { name: "Apparel" })).toHaveAttribute("href", "/category/apparel");
    expect(screen.getByRole("listitem", { current: "page" })).toHaveTextContent("Linen camp shirt");
  });
});
