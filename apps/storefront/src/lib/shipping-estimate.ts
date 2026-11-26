import { readScopeCookie } from "@/lib/scope-cookie";

export type ShippingDisclaimer = "estimated" | "live";

export type ShippingMethod = {
  code: string;
  label: string;
  amountMinor: number;
  currency: string;
  etaDaysMin?: number;
  etaDaysMax?: number;
};

export type ShippingEstimate = {
  estimated: boolean;
  provider?: string;
  currency: string;
  origin?: string;
  packages?: number;
  freeShippingThresholdMinor: number | null;
  methods: ShippingMethod[];
  disclaimer: ShippingDisclaimer;
  fallbackReason?: string;
};

export type ShippingDestination = {
  country: string;
  region: string;
  postalCode?: string;
};

export type ShippingEstimateRequest = {
  variantId: string;
  qty?: number;
  destination: ShippingDestination;
};

export type ShippingEstimateResult =
  | { ok: true; data: ShippingEstimate }
  | { ok: false; reason: "validation" | "network" | "unavailable" | "aborted" };

function asRecord(value: unknown): Record<string, unknown> | undefined {
  return value !== null && typeof value === "object" && !Array.isArray(value) ? value as Record<string, unknown> : undefined;
}

function safeText(value: unknown, maxLength: number): string | undefined {
  if (typeof value !== "string") return undefined;
  const text = value.trim();
  return text !== "" && text.length <= maxLength ? text : undefined;
}

function safeAmount(value: unknown): number | undefined {
  return typeof value === "number" && Number.isSafeInteger(value) && value >= 0 ? value : undefined;
}

function safeCurrency(value: unknown): string | undefined {
  if (typeof value !== "string") return undefined;
  const currency = value.trim().toUpperCase();
  return /^[A-Z]{3}$/.test(currency) ? currency : undefined;
}

function parseMethod(value: unknown): ShippingMethod | undefined {
  const record = asRecord(value);
  if (!record) return undefined;
  const code = safeText(record.code, 64);
  const label = safeText(record.label, 160);
  const amountMinor = safeAmount(record.amount_minor);
  const currency = safeCurrency(record.currency);
  if (!code || !label || amountMinor === undefined || !currency) return undefined;
  const etaDaysMin = safeAmount(record.eta_days_min);
  const etaDaysMax = safeAmount(record.eta_days_max);
  return {
    code,
    label,
    amountMinor,
    currency,
    ...(etaDaysMin !== undefined ? { etaDaysMin } : {}),
    ...(etaDaysMax !== undefined ? { etaDaysMax } : {}),
  };
}

/** Validates and normalizes the shipping-estimate response (also unwraps an envelope). */
export function parseShippingEstimate(value: unknown): ShippingEstimate | undefined {
  const root = asRecord(value);
  if (!root) return undefined;
  const record = asRecord(root.data) ?? root;
  const currency = safeCurrency(record.currency);
  if (!currency) return undefined;
  const rawMethods = Array.isArray(record.methods) ? record.methods : [];
  const methods = rawMethods.map(parseMethod).filter((method): method is ShippingMethod => method !== undefined);
  if (methods.length === 0) return undefined;
  const disclaimer: ShippingDisclaimer =
    record.disclaimer === "live" ? "live" : record.disclaimer === "estimated" ? "estimated" : record.estimated === true ? "estimated" : "live";
  const thresholdRaw = record.free_shipping_threshold_minor;
  const threshold = thresholdRaw === null ? null : safeAmount(thresholdRaw) ?? null;
  const provider = safeText(record.provider, 120);
  const origin = safeText(record.origin, 120);
  const packages = safeAmount(record.packages);
  const fallbackReason = safeText(record.fallback_reason, 80);
  return {
    estimated: typeof record.estimated === "boolean" ? record.estimated : disclaimer === "estimated",
    currency,
    freeShippingThresholdMinor: threshold,
    methods,
    disclaimer,
    ...(provider ? { provider } : {}),
    ...(origin ? { origin } : {}),
    ...(packages !== undefined ? { packages } : {}),
    ...(fallbackReason ? { fallbackReason } : {}),
  };
}

export function shippingEstimateUrl(): string {
  const baseUrl = process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "";
  const path = "/api/storefront/shipping-estimate";
  return baseUrl ? `${baseUrl.replace(/\/$/, "")}${path}` : path;
}

/** Calls the live shipping-estimate endpoint; never throws for transport/validation failures. */
export async function requestShippingEstimate(
  input: ShippingEstimateRequest,
  signal?: AbortSignal,
): Promise<ShippingEstimateResult> {
  const payload = {
    variant_id: input.variantId,
    qty: input.qty ?? 1,
    destination: {
      country: input.destination.country,
      region: input.destination.region,
      ...(input.destination.postalCode ? { postal_code: input.destination.postalCode } : {}),
    },
  };
  try {
    const response = await fetch(shippingEstimateUrl(), {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-Store-Id": process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? "default",
        "X-Channel-Id": process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? "web",
        "X-Locale": readScopeCookie().locale ?? process.env.NEXT_PUBLIC_STOREFRONT_LOCALE ?? "en",
        "X-Currency": readScopeCookie().currency ?? process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? "USD",
      },
      cache: "no-store",
      signal,
      body: JSON.stringify(payload),
    });
    if (response.status === 422) return { ok: false, reason: "validation" };
    if (!response.ok) return { ok: false, reason: "unavailable" };
    const body = await response.json().catch(() => null);
    const data = parseShippingEstimate(body);
    return data ? { ok: true, data } : { ok: false, reason: "unavailable" };
  } catch (error) {
    if (signal?.aborted || (error instanceof Error && error.name === "AbortError")) {
      return { ok: false, reason: "aborted" };
    }
    return { ok: false, reason: "network" };
  }
}
