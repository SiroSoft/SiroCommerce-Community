import "@testing-library/jest-dom/vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("next/link", () => ({
  default: ({ children, ...props }: React.PropsWithChildren<{ href: string }>) => <a {...props} href={props.href}>{children}</a>,
}));

import { CatalogControls } from "@/components/CatalogControls";
import { SearchForm } from "@/components/SearchForm";
import { SearchSuggest } from "@/components/SearchSuggest";
import { products } from "@/services/catalog";

function json(body: unknown): Response {
  return new Response(JSON.stringify(body), { status: 200, headers: { "Content-Type": "application/json" } });
}

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("catalog controls", () => {
  it("shows a helpful empty state with recovery links", () => {
    render(<CatalogControls initialProducts={[]} total={0} query={{ search: "mug", facets: { color: ["red"] } }} facets={[{ name: "color", label: "Color", values: [{ value: "red", label: "Red", count: 0 }] }]} />);
    expect(screen.getByText("Nothing matches this edit yet")).toBeInTheDocument();
    expect(screen.getByText(/No products found for "mug"/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Clear search" })).toHaveAttribute("href", "/search");
    expect(screen.getAllByRole("link", { name: /Clear filters/ })[0]).toHaveAttribute("href", "?q=mug");
    expect(screen.getByRole("link", { name: /apparel/i })).toHaveAttribute("href", "/category/apparel");
  });

  it("renders gap pagination and uses the first-page query separator", () => {
    render(<CatalogControls initialProducts={products.slice(0, 4)} total={100} query={{ search: "", page: 5, perPage: 4 }} />);
    expect(screen.getByRole("link", { name: "Page 1" })).toHaveAttribute("href", "?page=1");
    expect(screen.getByLabelText("Page 5")).toHaveAttribute("aria-current", "page");
    const gaps = document.querySelectorAll(".sf-page-gap");
    expect(gaps.length).toBeGreaterThan(0);
    expect(screen.getByRole("link", { name: "Previous" })).toHaveAttribute("href", "?page=4");
  });

  it("changes sort through the URL and disables controls while pending", async () => {
    const assign = vi.fn();
    vi.stubGlobal("location", { pathname: "/search", search: "?q=linen", assign });
    render(<CatalogControls initialProducts={products.slice(0, 2)} total={2} query={{ search: "linen", page: 1, perPage: 24, sort: "featured" }} />);
    fireEvent.change(screen.getByRole("combobox", { name: "Sort" }), { target: { value: "price-asc" } });
    await waitFor(() => expect(assign).toHaveBeenCalledWith(expect.stringContaining("sort=price-asc")));
  });

  it("removes a single active filter and falls back to the raw facet value", () => {
    render(<CatalogControls initialProducts={products.slice(0, 1)} total={1} query={{ search: "", facets: { width: ["wide", "narrow"] } }} facets={[{ name: "width", label: "Width", values: [{ value: "wide", label: "Wide", count: 1 }] }]} />);
    expect(screen.getByText(/2 filters active/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Remove filter Wide" })).toHaveAttribute("href", "?facet_width=narrow");
    expect(screen.getByRole("link", { name: "Remove filter narrow" })).toHaveAttribute("href", "?facet_width=wide");
  });
});

describe("search form", () => {
  beforeEach(() => {
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_STORE_ID", "store-1");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CHANNEL_ID", "web");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_LOCALE", "en");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CURRENCY", "USD");
    window.localStorage.clear();
  });

  it("tracks a submitted query, renders suggestions and clears the value", async () => {
    const beacon = vi.fn((_url?: string, _data?: unknown) => true);
    Object.defineProperty(navigator, "sendBeacon", { configurable: true, value: beacon });
    window.localStorage.setItem("sirocommerce:analytics-consent", "granted");
    const product = products[0]!;
    const { container } = render(<SearchForm value="linen" suggestions={[product]} />);
    expect(screen.getByRole("combobox")).toHaveValue("linen");
    expect(container.querySelector("datalist option")).toHaveAttribute("value", product.name);
    expect(screen.getByRole("link", { name: "Clear search" })).toHaveAttribute("href", "/search");

    fireEvent.submit(screen.getByRole("search"));
    expect(beacon).toHaveBeenCalled();
    const payload = String((beacon.mock.calls[0]?.[1] as unknown as Blob).type);
    expect(payload).toBe("application/json");
  });

  it("does not track an empty query", () => {
    const beacon = vi.fn((_url?: string, _data?: unknown) => true);
    Object.defineProperty(navigator, "sendBeacon", { configurable: true, value: beacon });
    window.localStorage.setItem("sirocommerce:analytics-consent", "granted");
    render(<SearchForm />);
    fireEvent.submit(screen.getByRole("search"));
    expect(beacon).not.toHaveBeenCalled();
    expect(screen.queryByRole("link", { name: "Clear search" })).toBeNull();
  });

  it("picks a suggestion into the input and submits the form", async () => {
    vi.useFakeTimers();
    const transport = vi.fn(async () => json({ data: [{ query: "linen shirt", submits: 3, clicks: 1 }], products: [] }));
    vi.stubGlobal("fetch", transport);
    const product = products[0]!;
    render(<SearchForm value="li" suggestions={[product]} />);
    const input = screen.getByRole("combobox") as HTMLInputElement;
    await act(async () => { input.focus(); });
    input.value = "li";
    await act(async () => { fireEvent.input(input); });
    await act(async () => { await vi.advanceTimersByTimeAsync(200); });
    const option = screen.getByRole("option", { name: "linen shirt" });
    await act(async () => { fireEvent.click(option); });
    expect(input.value).toBe("linen shirt");
  });
});

describe("search suggestions keyboard contract", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
  });

  async function openSuggestions(onPick: (query: string, position: number) => void) {
    const transport = vi.fn(async () => json({
      data: [{ query: "linen shirt", submits: 3, clicks: 1 }, { query: "linen bag", submits: 1, clicks: 0 }],
      products: [{ product_id: "p-1", variant_id: "v-1", name: "Linen bag", slug: "linen bag", price_minor: 1000, currency: "USD", image: "" }],
    }));
    vi.stubGlobal("fetch", transport);
    render(<><input id="search-input" /><SearchSuggest inputId="search-input" onPick={onPick} /></>);
    const input = document.getElementById("search-input") as HTMLInputElement;
    await act(async () => { input.focus(); });
    input.value = "li";
    await act(async () => { fireEvent.input(input); });
    await act(async () => { await vi.advanceTimersByTimeAsync(200); });
    return input;
  }

  it("moves the active option, selects with Enter and exposes ARIA state", async () => {
    const onPick = vi.fn();
    const input = await openSuggestions(onPick);
    expect(input).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByRole("listbox")).toBeInTheDocument();

    fireEvent.keyDown(input, { key: "ArrowDown" });
    const first = screen.getAllByRole("option")[0]!;
    expect(first).toHaveAttribute("aria-selected", "true");

    fireEvent.keyDown(input, { key: "ArrowDown" });
    expect(screen.getAllByRole("option")[1]).toHaveAttribute("aria-selected", "true");

    fireEvent.keyDown(input, { key: "ArrowUp" });
    expect(screen.getAllByRole("option")[0]).toHaveAttribute("aria-selected", "true");

    fireEvent.keyDown(input, { key: "Enter" });
    expect(onPick).toHaveBeenCalledWith("linen shirt", 0);
  });

  it("wraps upward from the first option and closes on Escape or blur", async () => {
    const onPick = vi.fn();
    const input = await openSuggestions(onPick);
    fireEvent.keyDown(input, { key: "ArrowUp" });
    expect(screen.getAllByRole("option")[1]).toHaveAttribute("aria-selected", "true");
    fireEvent.keyDown(input, { key: "ArrowDown" });
    expect(screen.getAllByRole("option")[2]).toHaveAttribute("aria-selected", "true");
    fireEvent.keyDown(input, { key: "ArrowDown" });
    expect(screen.getAllByRole("option")[0]).toHaveAttribute("aria-selected", "true");
    fireEvent.keyDown(input, { key: "ArrowUp" });
    expect(screen.getAllByRole("option")[2]).toHaveAttribute("aria-selected", "true");

    fireEvent.keyDown(input, { key: "Escape" });
    expect(screen.queryByRole("listbox")).toBeNull();
    expect(input).toHaveAttribute("aria-expanded", "false");

    await act(async () => { fireEvent.focus(input); });
    await act(async () => { await vi.advanceTimersByTimeAsync(200); });
    expect(screen.getByRole("listbox")).toBeInTheDocument();
    fireEvent.blur(input, { relatedTarget: document.body });
    expect(screen.queryByRole("listbox")).toBeNull();
  });

  it("ignores arrow keys before any suggestion exists and clears on a short query", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => json({ data: [], products: [] })));
    render(<><input id="empty-input" /><SearchSuggest inputId="empty-input" onPick={vi.fn()} /></>);
    const input = document.getElementById("empty-input") as HTMLInputElement;
    await act(async () => { input.focus(); });
    input.value = "l";
    await act(async () => { fireEvent.input(input); });
    await act(async () => { await vi.advanceTimersByTimeAsync(200); });
    fireEvent.keyDown(input, { key: "ArrowDown" });
    expect(screen.queryByRole("listbox")).toBeNull();
    expect(input).toHaveAttribute("aria-expanded", "false");
  });
});
