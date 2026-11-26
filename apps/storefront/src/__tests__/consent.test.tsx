import "@testing-library/jest-dom/vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ConsentProvider } from "@/components/consent/ConsentProvider";
import { CookieSettingsButton } from "@/components/consent/CookieSettingsButton";
import { Footer } from "@/components/Footer";
import { consentStorageKey } from "@/lib/consent";
import { setAnalyticsConsent, trackStorefrontEvent } from "@/services/storefront-events";

const route = vi.hoisted(() => ({ pathname: "/" }));
vi.mock("next/navigation", () => ({ usePathname: () => route.pathname }));

let fetchMock: ReturnType<typeof vi.fn>;
let beacon: ReturnType<typeof vi.fn>;

function policyBody(version = "1") {
  return { data: { version, updated_at: "2026-11-21T00:00:00+00:00", sections: [{ id: "privacy", title: "Privacy", body: "Text" }] } };
}

function storedDecision(version = "1"): Record<string, unknown> {
  const raw = window.localStorage.getItem(consentStorageKey(version));
  return raw ? JSON.parse(raw) as Record<string, unknown> : {};
}

describe("storefront consent experience", () => {
  beforeEach(() => {
    route.pathname = "/";
    window.localStorage.clear();
    window.sessionStorage.clear();
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_STORE_ID", "store-1");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CHANNEL_ID", "web");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_LOCALE", "en");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CURRENCY", "USD");
    beacon = vi.fn(() => true);
    Object.defineProperty(navigator, "sendBeacon", { configurable: true, value: beacon });
    fetchMock = vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes("/api/consent/policy")) {
        return new Response(JSON.stringify(policyBody()), { status: 200, headers: { "Content-Type": "application/json" } });
      }
      return new Response(JSON.stringify({ success: true }), { status: 200, headers: { "Content-Type": "application/json" } });
    });
    vi.stubGlobal("fetch", fetchMock);
  });

  afterEach(() => {
    vi.unstubAllEnvs();
    vi.unstubAllGlobals();
    window.localStorage.clear();
    window.sessionStorage.clear();
  });

  it("renders the banner and persists necessary-only without sending analytics", async () => {
    render(<ConsentProvider><div>content</div></ConsentProvider>);

    expect(await screen.findByRole("region", { name: "Cookie consent" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Necessary only" }));

    await waitFor(() => expect(screen.queryByRole("region", { name: "Cookie consent" })).not.toBeInTheDocument());
    expect(storedDecision().choices).toMatchObject({ necessary: true, analytics: false, marketing: false });
    expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith("/api/consent"))).toBe(true);

    trackStorefrontEvent("product_view", { product_id: "p-1" });
    expect(beacon).not.toHaveBeenCalled();
  });

  it("enables analytics after accepting all and records the API decision", async () => {
    render(<ConsentProvider><div>content</div></ConsentProvider>);

    expect(await screen.findByRole("region", { name: "Cookie consent" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Accept all" }));

    await waitFor(() => expect(storedDecision().choices).toMatchObject({ necessary: true, analytics: true, marketing: true }));
    trackStorefrontEvent("product_view", { product_id: "p-1" });
    expect(beacon).toHaveBeenCalledOnce();
    const consentCall = fetchMock.mock.calls.find(([url]) => String(url).endsWith("/api/consent"));
    expect(consentCall).toBeDefined();
  });

  it("persists preference-center toggles", async () => {
    render(<ConsentProvider><div>content</div></ConsentProvider>);

    await screen.findByRole("region", { name: "Cookie consent" });
    fireEvent.click(screen.getByRole("button", { name: "Preferences" }));
    expect(screen.getByRole("dialog", { name: "Cookie preferences" })).toBeInTheDocument();
    expect(screen.getByRole("checkbox", { name: /Necessary/ })).toBeDisabled();

    fireEvent.click(screen.getByRole("checkbox", { name: /Analytics/ }));
    fireEvent.click(screen.getByRole("button", { name: "Save preferences" }));

    await waitFor(() => expect(storedDecision().choices).toMatchObject({ analytics: true, marketing: false }));
    expect(screen.queryByRole("dialog", { name: "Cookie preferences" })).not.toBeInTheDocument();
  });

  it("opens the preference center from the footer cookie-settings link", async () => {
    render(<ConsentProvider><Footer locale="en" /></ConsentProvider>);

    await screen.findByRole("region", { name: "Cookie consent" });
    fireEvent.click(screen.getByRole("button", { name: "Cookie settings" }));

    expect(await screen.findByRole("dialog", { name: "Cookie preferences" })).toBeInTheDocument();
  });

  it("hides the banner on cart and checkout but keeps footer settings available", async () => {
    route.pathname = "/checkout";
    render(<ConsentProvider><CookieSettingsButton /></ConsentProvider>);

    await waitFor(() => expect(fetchMock).toHaveBeenCalled());
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)); });

    expect(screen.queryByRole("region", { name: "Cookie consent" })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Cookie settings" }));
    expect(screen.getByRole("dialog", { name: "Cookie preferences" })).toBeInTheDocument();
  });

  it("re-prompts and blocks tracking when the policy version changes", async () => {
    window.localStorage.setItem(consentStorageKey("1"), JSON.stringify({ version: "1", choices: { necessary: true, analytics: true, marketing: true }, decided_at: "2026-01-01T00:00:00Z" }));
    setAnalyticsConsent(true);
    fetchMock.mockImplementation(async (input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes("/api/consent/policy")) {
        return new Response(JSON.stringify(policyBody("2")), { status: 200, headers: { "Content-Type": "application/json" } });
      }
      return new Response(JSON.stringify({ success: true }), { status: 200, headers: { "Content-Type": "application/json" } });
    });

    render(<ConsentProvider><div>content</div></ConsentProvider>);

    expect(await screen.findByRole("region", { name: "Cookie consent" })).toBeInTheDocument();
    trackStorefrontEvent("product_view", { product_id: "p-1" });
    expect(beacon).not.toHaveBeenCalled();
  });
});
