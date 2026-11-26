import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { LocaleProvider } from "@/lib/i18n-client";
import { DeliveryEstimator } from "@/components/DeliveryEstimator";

const locales = [
  {
    locale: "de" as const,
    title: "Lieferkostenschätzung",
    province: "Provinz/Stadt",
    district: "Bezirk",
    live: "Live-Preis",
    eta: "2–4 Tage",
  },
  {
    locale: "zh" as const,
    title: "配送费用估算",
    province: "省/市",
    district: "区/县",
    live: "实时价格",
    eta: "2–4 天",
  },
  {
    locale: "ja" as const,
    title: "配送料の目安",
    province: "都道府県/市",
    district: "区/市町村",
    live: "実際の料金",
    eta: "2〜4日",
  },
];

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

function estimate() {
  return {
    estimated: false,
    provider: "GHN",
    currency: "VND",
    origin: "HCM",
    packages: 1,
    free_shipping_threshold_minor: null,
    methods: [
      { code: "standard", label: "Standard delivery", amount_minor: 30000, currency: "VND", eta_days_min: 2, eta_days_max: 4 },
    ],
    disclaimer: "live",
    fallback_reason: null,
  };
}

describe("DeliveryEstimator locale labels", () => {
  beforeEach(() => {
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SHIPPING_FLAT_RATE_MINOR", "");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SHIPPING_FREE_OVER_MINOR", "");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SHIPPING_ETA", "");
    vi.stubGlobal("fetch", vi.fn(async () => jsonResponse(estimate())));
  });

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.unstubAllEnvs();
  });

  for (const labels of locales) {
    it(`renders ${labels.locale} labels with Vietnamese province data intact`, async () => {
      render(
        <LocaleProvider locale={labels.locale}>
          <DeliveryEstimator priceMinor={100000} currency="VND" variantId="v-1" />
        </LocaleProvider>,
      );

      expect(screen.getByRole("heading", { name: labels.title })).toBeInTheDocument();
      expect(screen.getByRole("combobox", { name: labels.province })).toBeInTheDocument();
      expect(screen.getByRole("combobox", { name: labels.district })).toBeInTheDocument();
      expect(screen.getByRole("option", { name: "Hà Nội" })).toBeInTheDocument();

      fireEvent.change(screen.getByRole("combobox", { name: labels.province }), { target: { value: "Hà Nội" } });
      expect(screen.getByRole("option", { name: "Hoàn Kiếm" })).toBeInTheDocument();
      fireEvent.change(screen.getByRole("combobox", { name: labels.district }), { target: { value: "Hoàn Kiếm" } });

      expect(await screen.findByText(labels.live)).toBeInTheDocument();
      expect(await screen.findByText(labels.eta)).toBeInTheDocument();
    });
  }
});
