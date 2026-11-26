import "@testing-library/jest-dom/vitest";
import { act, cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { DeliveryEstimator } from "@/components/DeliveryEstimator";

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

function estimate(overrides: Record<string, unknown> = {}) {
  return {
    estimated: false,
    provider: "GHN",
    currency: "VND",
    origin: "HCM",
    packages: 1,
    free_shipping_threshold_minor: 500000,
    methods: [
      { code: "standard", label: "Standard delivery", amount_minor: 30000, currency: "VND", eta_days_min: 2, eta_days_max: 4 },
    ],
    disclaimer: "live",
    fallback_reason: null,
    ...overrides,
  };
}

function selectDestination(province = "Hà Nội", district = "Hoàn Kiếm") {
  fireEvent.change(screen.getByRole("combobox", { name: "Province/City" }), { target: { value: province } });
  fireEvent.change(screen.getByRole("combobox", { name: "District" }), { target: { value: district } });
}

describe("DeliveryEstimator live shipping", () => {
  beforeEach(() => {
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SHIPPING_FLAT_RATE_MINOR", "");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SHIPPING_FREE_OVER_MINOR", "");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SHIPPING_ETA", "");
  });

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.unstubAllEnvs();
  });

  it("renders live methods with formatted price, ETA, badge and free-shipping hint", async () => {
    const fetchMock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => jsonResponse(estimate()));
    vi.stubGlobal("fetch", fetchMock);

    render(<DeliveryEstimator priceMinor={100000} currency="VND" variantId="v-1" />);
    selectDestination();

    expect(await screen.findByText("Standard delivery")).toBeInTheDocument();
    expect(screen.getByText(/30,000/)).toBeInTheDocument();
    expect(screen.getByText("2–4 days")).toBeInTheDocument();
    expect(screen.getByText("Live price")).toBeInTheDocument();
    expect(screen.getByText(/Free shipping on orders from/)).toBeInTheDocument();

    const [, init] = fetchMock.mock.calls[0]!;
    expect(init?.method).toBe("POST");
    expect(JSON.parse(String(init?.body))).toEqual({
      variant_id: "v-1",
      qty: 1,
      destination: { country: "VN", region: "Hà Nội" },
    });
  });

  it("labels estimated results and explains the fallback rate card", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => jsonResponse(estimate({
      disclaimer: "estimated",
      estimated: true,
      free_shipping_threshold_minor: null,
      fallback_reason: "default_table",
    }))));

    render(<DeliveryEstimator priceMinor={100000} currency="VND" variantId="v-1" />);
    selectDestination();

    expect(await screen.findByText("Estimate")).toBeInTheDocument();
    expect(screen.getByText("Using the default rate card.")).toBeInTheDocument();
    expect(screen.queryByText("Live price")).not.toBeInTheDocument();
  });

  it("shows a friendly validation message for 422 responses", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => jsonResponse({ error: "invalid_destination" }, 422)));

    render(<DeliveryEstimator priceMinor={100000} currency="VND" variantId="v-1" />);
    selectDestination();

    expect(await screen.findByText(/delivery details aren't valid/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Retry" })).not.toBeInTheDocument();
  });

  it("falls back to configured estimates on a network error and retries the live call", async () => {
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SHIPPING_FLAT_RATE_MINOR", "30000");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SHIPPING_FREE_OVER_MINOR", "500000");
    let calls = 0;
    const fetchMock = vi.fn(async () => {
      calls += 1;
      if (calls === 1) throw new TypeError("network down");
      return jsonResponse(estimate());
    });
    vi.stubGlobal("fetch", fetchMock);

    render(<DeliveryEstimator priceMinor={100000} currency="VND" variantId="v-1" />);
    selectDestination();

    expect(await screen.findByText(/Estimated shipping:/)).toBeInTheDocument();
    expect(screen.getByText("Estimate")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Retry" }));
    expect(await screen.findByText("Live price")).toBeInTheDocument();
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("aborts the in-flight request when the destination changes and ignores the stale response", async () => {
    let firstSignal: AbortSignal | undefined;
    let resolveFirst: ((response: Response) => void) | undefined;
    const fetchMock = vi.fn((_input: RequestInfo | URL, init?: RequestInit) => {
      if (fetchMock.mock.calls.length === 1) {
        firstSignal = init?.signal ?? undefined;
        return new Promise<Response>((resolve) => { resolveFirst = resolve; });
      }
      return Promise.resolve(jsonResponse(estimate({
        methods: [
          { code: "fast", label: "Second method", amount_minor: 45000, currency: "VND", eta_days_min: 1, eta_days_max: 2 },
        ],
      })));
    });
    vi.stubGlobal("fetch", fetchMock);

    render(<DeliveryEstimator priceMinor={100000} currency="VND" variantId="v-1" />);
    selectDestination("Hà Nội", "Hoàn Kiếm");
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

    selectDestination("Hà Nội", "Cầu Giấy");
    expect(await screen.findByText("Second method")).toBeInTheDocument();
    expect(firstSignal?.aborted).toBe(true);

    await act(async () => {
      resolveFirst?.(jsonResponse(estimate({
        methods: [
          { code: "stale", label: "First method", amount_minor: 1, currency: "VND", eta_days_min: 1, eta_days_max: 1 },
        ],
      })));
    });

    expect(screen.queryByText("First method")).not.toBeInTheDocument();
    expect(screen.getByText("Second method")).toBeInTheDocument();
  });
});
