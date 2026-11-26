import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const getCustomerAccessToken = vi.hoisted(() => vi.fn(() => ""));

vi.mock("@/services/customer", () => ({ getCustomerAccessToken }));

import { StockAlertSignup } from "@/components/StockAlertSignup";

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

describe("stock alert signup", () => {
  let fetchMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    getCustomerAccessToken.mockReturnValue("");
    fetchMock = vi.fn(async () => json({ success: true }));
    vi.stubGlobal("fetch", fetchMock);
  });

  afterEach(() => {
    vi.unstubAllEnvs();
    vi.unstubAllGlobals();
  });

  it("submits a back-in-stock alert with the customer token", async () => {
    getCustomerAccessToken.mockReturnValue("access-token");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test/");
    render(<StockAlertSignup productId="p-1" variantId="v-1" />);

    fireEvent.change(screen.getByLabelText("Email for the back-in-stock note"), { target: { value: "buyer@example.com" } });
    fireEvent.click(screen.getByRole("button", { name: "Notify me" }));

    expect(await screen.findByRole("status")).toHaveTextContent("Saved. We will email you");
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("https://api.example.test/api/products/p-1/stock-alerts");
    expect((init.headers as Record<string, string>).Authorization).toBe("Bearer access-token");
    expect(JSON.parse(String(init.body))).toEqual({ variant_id: "v-1", email: "buyer@example.com", type: "back_in_stock" });
  });

  it("uses a relative endpoint when no API base is configured", async () => {
    render(<StockAlertSignup productId="p 1" />);
    fireEvent.click(screen.getByRole("button", { name: "Notify me" }));
    await screen.findByRole("status");
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/products/p%201/stock-alerts");
    expect((init.headers as Record<string, string>).Authorization).toBeUndefined();
    expect(JSON.parse(String(init.body))).toEqual({ type: "back_in_stock" });
  });

  it("validates the target price before sending a price-drop alert", async () => {
    render(<StockAlertSignup productId="p-1" currency="USD" priceMinor={2500} />);
    fireEvent.change(screen.getByLabelText("Notify me about"), { target: { value: "price_drop" } });
    const target = screen.getByLabelText("Target price");
    expect(target).toHaveAttribute("placeholder", "25.00");
    fireEvent.click(screen.getByRole("button", { name: "Notify me" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Could not save the alert");
    expect(fetchMock).not.toHaveBeenCalled();

    fireEvent.change(target, { target: { value: "12.50" } });
    fireEvent.click(screen.getByRole("button", { name: "Notify me" }));
    await screen.findByRole("status");
    expect(JSON.parse(String((fetchMock.mock.calls[0]?.[1] as RequestInit).body))).toEqual({ type: "price_drop", target_price_minor: 1250 });
  });

  it("supports preorder alerts and integer-only currencies", async () => {
    render(<StockAlertSignup productId="p-2" isPreorder currency="JPY" priceMinor={1500} />);
    expect(screen.getByLabelText("Notify me about")).toHaveDisplayValue("Preorder availability");
    fireEvent.change(screen.getByLabelText("Notify me about"), { target: { value: "price_drop" } });
    expect(screen.getByLabelText("Target price")).toHaveAttribute("placeholder", "1500");
    fireEvent.change(screen.getByLabelText("Target price"), { target: { value: "1200" } });
    fireEvent.click(screen.getByRole("button", { name: "Notify me" }));
    await screen.findByRole("status");
    expect(JSON.parse(String((fetchMock.mock.calls[0]?.[1] as RequestInit).body))).toEqual({ type: "price_drop", target_price_minor: 1200 });
  });

  it("falls back to two decimal units for unsupported currencies", async () => {
    render(<StockAlertSignup productId="p-3" currency="BTC" priceMinor={9900} />);
    fireEvent.change(screen.getByLabelText("Notify me about"), { target: { value: "price_drop" } });
    expect(screen.getByLabelText("Target price")).toHaveAttribute("placeholder", "99.00");
    fireEvent.change(screen.getByLabelText("Target price"), { target: { value: "10.5" } });
    fireEvent.click(screen.getByRole("button", { name: "Notify me" }));
    await screen.findByRole("status");
    expect(JSON.parse(String((fetchMock.mock.calls[0]?.[1] as RequestInit).body))).toEqual({ type: "price_drop", target_price_minor: 1050 });
  });

  it("treats an unsuccessful envelope and a network failure as errors", async () => {
    fetchMock.mockResolvedValueOnce(json({ success: false, message: "rate limited" }, 429));
    render(<StockAlertSignup productId="p-1" />);
    fireEvent.click(screen.getByRole("button", { name: "Notify me" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Could not save the alert");

    fetchMock.mockRejectedValueOnce(new Error("offline"));
    fireEvent.click(screen.getByRole("button", { name: "Notify me" }));
    await waitFor(() => expect(screen.getByRole("alert")).toBeInTheDocument());
  });

  it("ignores a second submit while the first is in flight", async () => {
    let resolveFetch!: (response: Response) => void;
    fetchMock.mockImplementationOnce(() => new Promise<Response>((resolve) => { resolveFetch = resolve; }));
    render(<StockAlertSignup productId="p-1" />);
    const button = screen.getByRole("button", { name: "Notify me" });
    fireEvent.click(button);
    await waitFor(() => expect(button).toBeDisabled());
    fireEvent.submit(button.closest("form")!);
    expect(fetchMock).toHaveBeenCalledOnce();
    resolveFetch(json({ success: true }));
    expect(await screen.findByRole("status")).toBeInTheDocument();
  });
});

describe("turnstile widget with a configured site key", () => {
  beforeEach(() => {
    vi.resetModules();
    document.head.innerHTML = "";
    delete window.turnstile;
    vi.stubEnv("NEXT_PUBLIC_TURNSTILE_SITE_KEY", "site-key");
  });

  afterEach(() => {
    vi.unstubAllEnvs();
    document.head.innerHTML = "";
    delete window.turnstile;
  });

  it("injects the script, renders the widget and forwards token callbacks", async () => {
    const remove = vi.fn();
    const renderWidget = vi.fn((_element?: HTMLElement, _options?: Record<string, unknown>) => "widget-1");
    const onToken = vi.fn();
    const { TurnstileWidget, turnstileConfigured } = await import("@/components/TurnstileWidget");
    expect(turnstileConfigured()).toBe(true);

    const { container, unmount } = render(<TurnstileWidget onToken={onToken} />);
    expect(container.querySelector(".turnstile-widget")).toHaveAttribute("aria-label", "Human verification");

    const script = document.querySelector<HTMLScriptElement>('script[src*="challenges.cloudflare.com"]');
    expect(script).not.toBeNull();
    window.turnstile = { render: renderWidget, remove };
    fireEvent.load(script!);

    await waitFor(() => expect(renderWidget).toHaveBeenCalledOnce());
    const options = renderWidget.mock.calls[0]?.[1] as unknown as Record<string, (value?: unknown) => void>;
    expect(renderWidget.mock.calls[0]?.[0]).toBe(container.querySelector(".turnstile-widget"));
    options.callback?.("token-1");
    options.callback?.(42);
    options["expired-callback"]?.();
    options["error-callback"]?.();
    expect(onToken.mock.calls.map(([value]) => value)).toEqual(["token-1", "", "", ""]);

    unmount();
    expect(remove).toHaveBeenCalledWith("widget-1");
  });

  it("reuses an existing script element and survives a load failure", async () => {
    const existing = document.createElement("script");
    existing.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
    document.head.appendChild(existing);
    const onToken = vi.fn();
    const { TurnstileWidget } = await import("@/components/TurnstileWidget");
    render(<TurnstileWidget onToken={onToken} />);
    expect(document.querySelectorAll("script")).toHaveLength(1);

    fireEvent.error(existing);
    await waitFor(() => expect(document.querySelector(".turnstile-widget")).toBeInTheDocument());
    expect(onToken).not.toHaveBeenCalled();
  });

  it("does not render a widget without a host element after failure", async () => {
    const onToken = vi.fn();
    const { TurnstileWidget } = await import("@/components/TurnstileWidget");
    const { unmount } = render(<TurnstileWidget onToken={onToken} />);
    const script = document.querySelector<HTMLScriptElement>('script[src*="challenges.cloudflare.com"]');
    unmount();
    if (script) fireEvent.load(script);
    expect(document.querySelector(".turnstile-widget")).toBeNull();
  });
});
