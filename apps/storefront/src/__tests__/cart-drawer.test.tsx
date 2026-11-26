import "@testing-library/jest-dom/vitest";
import { act, cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { CartDrawer } from "@/components/CartDrawer";
import { ProductCard } from "@/components/ProductCard";
import { products } from "@/services/catalog";

vi.mock("next/link", () => ({ default: ({ children, ...props }: React.PropsWithChildren<{ href: string }>) => <a {...props} href={props.href}>{children}</a> }));

describe("cart drawer and quick add", () => {
  afterEach(() => {
    cleanup();
    document.body.style.overflow = "";
    document.cookie = "siro_cart_id=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
    document.cookie = "siro_cart_token=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
    vi.unstubAllGlobals();
  });

  it("opens from cart:open, renders line items and total, and closes on escape", async () => {
    render(<CartDrawer initialLines={[{ productId: "p-1", name: "Linen camp shirt", slug: "linen-camp-shirt", quantity: 2, unitPriceMinor: 6800, lineTotalMinor: 13600 }]} currency="USD" />);
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    act(() => { window.dispatchEvent(new CustomEvent("cart:open")); });
    const dialog = screen.getByRole("dialog");
    expect(dialog).toHaveAttribute("aria-modal", "true");
    expect(screen.getByText("Linen camp shirt")).toBeInTheDocument();
    expect(screen.getByText("× 2")).toBeInTheDocument();
    expect(screen.getAllByText("$136.00")).toHaveLength(2);
    expect(screen.getByRole("link", { name: "View cart" })).toHaveAttribute("href", "/cart");
    expect(screen.getByRole("button", { name: "Close cart" })).toHaveFocus();
    fireEvent.keyDown(document, { key: "Escape" });
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(document.body.style.overflow).toBe("");
  });

  it("refreshes from the cart cookie and changes quantity or removes a line through the API", async () => {
    document.cookie = "siro_cart_id=cart_12345678; path=/";
    document.cookie = "siro_cart_token=token-1; path=/";
    const cart = (lines: Array<Record<string, unknown>>) => ({ success: true, data: { currency: "USD", lines, subtotal: lines.reduce((sum, line) => sum + Number(line.unit_price) * Number(line.quantity), 0) } });
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      if (url.includes("/api/carts/cart_12345678/lines/line-1")) {
        if (init?.method === "DELETE") return { ok: true, json: async () => cart([]) };
        const body = JSON.parse(String(init?.body)) as { qty: number };
        return { ok: true, json: async () => cart([{ id: "line-1", product_id: "p-1", name: "Linen camp shirt", quantity: body.qty, unit_price: 6800, line_total: 6800 * body.qty }]) };
      }
      return { ok: true, json: async () => cart([{ id: "line-1", product_id: "p-1", name: "Linen camp shirt", quantity: 2, unit_price: 6800, line_total: 13600 }]) };
    });
    vi.stubGlobal("fetch", fetchMock);
    render(<CartDrawer currency="USD" />);
    act(() => { window.dispatchEvent(new CustomEvent("cart:open")); });
    expect(await screen.findByText("Linen camp shirt")).toBeInTheDocument();
    expect(screen.getByText("× 2")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /increase quantity: linen camp shirt/i }));
    await waitFor(() => expect(screen.getByText("× 3")).toBeInTheDocument());
    const patch = fetchMock.mock.calls.find((call) => call[1]?.method === "PATCH");
    expect(JSON.parse(String(patch?.[1]?.body))).toEqual({ qty: 3 });

    fireEvent.click(screen.getByRole("button", { name: /remove from bag: linen camp shirt/i }));
    await waitFor(() => expect(screen.getByText(/Your bag is empty/)).toBeInTheDocument());
    expect(screen.getByRole("link", { name: "Continue shopping" })).toHaveAttribute("href", "/search");
    expect(fetchMock.mock.calls.some((call) => call[1]?.method === "DELETE")).toBe(true);
  });

  it("merges a quick-added line from the event detail", () => {
    render(<CartDrawer initialLines={[{ productId: "p-1", name: "Linen camp shirt", quantity: 1, unitPriceMinor: 6800, lineTotalMinor: 6800 }]} currency="USD" />);
    act(() => { window.dispatchEvent(new CustomEvent("cart:open", { detail: { line: { name: "Linen camp shirt", quantity: 1, unitPriceMinor: 6800, currency: "USD" } } })); });
    expect(screen.getByText("× 2")).toBeInTheDocument();
    expect(screen.getAllByText("$136.00")).toHaveLength(2);
  });

  it("quick add posts the default variant, shows loading, and dispatches cart:open without reload", async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, url: "http://localhost:3000/cart?added=1" });
    vi.stubGlobal("fetch", fetchMock);
    const opened = vi.fn();
    window.addEventListener("cart:open", opened);
    render(<ProductCard product={products[0]!} />);
    const button = screen.getByRole("button", { name: /quick add linen camp shirt/i });
    fireEvent.click(button);
    expect(button).toBeDisabled();
    await waitFor(() => expect(fetchMock.mock.calls.filter((call) => String(call[0]).includes("/cart/add"))).toHaveLength(1));
    const [url, init] = fetchMock.mock.calls.find((call) => String(call[0]).includes("/cart/add")) as [string, RequestInit];
    expect(String(url)).toContain("/cart/add");
    expect(init.method).toBe("POST");
    expect((init.body as FormData).get("product_id")).toBe("p-1");
    await waitFor(() => expect(opened).toHaveBeenCalledTimes(1));
    const event = opened.mock.calls[0]![0] as CustomEvent<{ line: { name: string; quantity: number } }>;
    expect(event.detail.line.name).toBe("Linen camp shirt");
    expect(event.detail.line.quantity).toBe(1);
    await waitFor(() => expect(button).not.toBeDisabled());
    window.removeEventListener("cart:open", opened);
  });

  it("hides quick add when no default variant can be resolved", () => {
    render(<ProductCard product={{ ...products[0]!, productType: "variable", variantOptions: undefined }} />);
    expect(screen.queryByRole("button", { name: /quick add/i })).not.toBeInTheDocument();
  });
});
