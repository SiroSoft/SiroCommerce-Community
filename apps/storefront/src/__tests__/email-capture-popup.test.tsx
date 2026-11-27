import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { EmailCapturePopup } from "@/plugins/email-capture-popup";
import { registerEmailCapturePopup } from "@/plugins/email-capture-popup/register";
import { getSlotRegistry, resetSlotRegistry } from "@/slots";

let fetchMock: ReturnType<typeof vi.fn>;

function configBody(active = true, delayMs = 100) {
  return {
    data: { active, delay_ms: delayMs, coupon_enabled: true, coupon_percent: 10 },
  };
}

function subscribeBody() {
  return {
    data: {
      id: "sub-1",
      email: "shopper@example.com",
      coupon_code: "WELCOME-ABCD1234",
      consented_at: "2026-11-26 00:00:00",
      already_subscribed: false,
      mail_sent: true,
    },
  };
}

describe("email capture popup", () => {
  beforeEach(() => {
    window.localStorage.clear();
    window.sessionStorage.clear();
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_STORE_ID", "default");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CHANNEL_ID", "web");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_LOCALE", "en");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CURRENCY", "VND");
    fetchMock = vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input);
      if (url.endsWith("/api/ext/siro/email-capture-popup/public")) {
        return new Response(JSON.stringify(configBody()), { status: 200, headers: { "Content-Type": "application/json" } });
      }
      if (url.endsWith("/api/consent/marketing")) {
        return new Response(JSON.stringify({ success: true }), { status: 200, headers: { "Content-Type": "application/json" } });
      }
      if (url.endsWith("/api/ext/siro/email-capture-popup/subscribe")) {
        return new Response(JSON.stringify(subscribeBody()), { status: 201, headers: { "Content-Type": "application/json" } });
      }
      return new Response(JSON.stringify({ success: true }), { status: 200, headers: { "Content-Type": "application/json" } });
    });
    vi.stubGlobal("fetch", fetchMock);
    resetSlotRegistry();
  });

  afterEach(() => {
    vi.unstubAllEnvs();
    vi.unstubAllGlobals();
    window.localStorage.clear();
    window.sessionStorage.clear();
    resetSlotRegistry();
  });

  it("registers the footer slot explicitly and has no import side effects", () => {
    expect(getSlotRegistry()).toEqual({});
    registerEmailCapturePopup();
    expect(getSlotRegistry().footer).toHaveLength(1);
    expect(getSlotRegistry().footer?.[0]?.id).toBe("siro/email-capture-popup");
    expect(getSlotRegistry().footer?.[0]?.priority).toBe(95);
  });

  it("renders nothing before the trigger and shows the dialog after the delay", async () => {
    render(<EmailCapturePopup />);
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).includes("/public"))).toBe(true));
    expect(await screen.findByRole("dialog", { name: /10% off/ })).toBeInTheDocument();
    expect(screen.getByTestId("email-capture-popup-email")).toBeInTheDocument();
  });

  it("renders nothing when the extension is inactive", async () => {
    fetchMock = vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input);
      if (url.endsWith("/public")) {
        return new Response(JSON.stringify(configBody(false)), { status: 200, headers: { "Content-Type": "application/json" } });
      }
      return new Response(JSON.stringify({ success: true }), { status: 200, headers: { "Content-Type": "application/json" } });
    });
    vi.stubGlobal("fetch", fetchMock);
    render(<EmailCapturePopup />);
    await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).includes("/public"))).toBe(true));
    await new Promise((resolve) => setTimeout(resolve, 300));
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
  });

  it("closes on ESC and shows only once per session", async () => {
    render(<EmailCapturePopup />);
    expect(await screen.findByRole("dialog")).toBeInTheDocument();
    fireEvent.keyDown(document, { key: "Escape" });
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(window.sessionStorage.getItem("siro-email-capture-popup:shown")).toBe("1");
  });

  it("posts consent first, then subscribes, and shows the welcome coupon", async () => {
    render(<EmailCapturePopup />);
    const input = await screen.findByTestId("email-capture-popup-email");
    fireEvent.change(input, { target: { value: "shopper@example.com" } });
    fireEvent.click(screen.getByRole("button", { name: "Claim my discount" }));
    expect(await screen.findByText(/You're in!/)).toBeInTheDocument();
    expect(screen.getByTestId("email-capture-popup-coupon")).toHaveTextContent("WELCOME-ABCD1234");
    const urls = fetchMock.mock.calls.map(([url]) => String(url));
    const consentIndex = urls.findIndex((url) => url.endsWith("/api/consent/marketing"));
    const subscribeIndex = urls.findIndex((url) => url.endsWith("/subscribe"));
    expect(consentIndex).toBeGreaterThanOrEqual(0);
    expect(subscribeIndex).toBeGreaterThan(consentIndex);
    expect(window.sessionStorage.getItem("siro-email-capture-popup:subscribed")).toBe("1");
  });

  it("rejects an invalid email without calling the API", async () => {
    render(<EmailCapturePopup />);
    const input = await screen.findByTestId("email-capture-popup-email");
    fireEvent.change(input, { target: { value: "not-an-email" } });
    fireEvent.click(screen.getByRole("button", { name: "Claim my discount" }));
    expect(await screen.findByRole("status")).toHaveTextContent(/valid email/);
    expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith("/subscribe"))).toBe(false);
  });
});
