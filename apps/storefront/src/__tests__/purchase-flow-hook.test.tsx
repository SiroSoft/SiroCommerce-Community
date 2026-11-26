import "@testing-library/jest-dom/vitest";
import { act, renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { setCustomerAccessToken } from "@/services/customer";
import { usePurchaseFlow } from "@/components/purchase/usePurchaseFlow";
import { CHECKOUT_UNAVAILABLE_NOTICE } from "@/components/purchase/mode";

function mount(path = "/cart", options: { quoteMode?: "required" | "auto" | "off" } = {}) {
  window.history.replaceState({}, "", path);
  return renderHook(() => usePurchaseFlow(options));
}

function setOnline(value: boolean) {
  Object.defineProperty(navigator, "onLine", { configurable: true, value });
}

beforeEach(() => {
  window.localStorage.clear();
  window.sessionStorage.clear();
  setCustomerAccessToken(undefined);
  setOnline(true);
  vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "");
  window.history.replaceState({}, "", "/cart");
});

afterEach(() => {
  setOnline(true);
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("purchase flow hook", () => {
  it("completes cart, quote, voucher, gift, B2B and order placement", async () => {
    const { result } = mount();

    await act(async () => { await result.current.addToCart(); });
    expect(result.current.cart?.lines).toHaveLength(1);
    expect(result.current.step).toBe("quote");
    expect(result.current.subtotal).toBeGreaterThan(0);

    await act(async () => { await result.current.createQuote(); });
    expect(result.current.quote).toBeDefined();
    expect(result.current.step).toBe("checkout");
    expect(result.current.breakdown.total).toBe(result.current.total);

    act(() => result.current.setVoucherCode("SAVE10"));
    await act(async () => { await result.current.applyVoucher(); });
    expect(result.current.voucher).toMatchObject({ code: "SAVE10", eligibility: "eligible" });
    expect(result.current.notice).toContain("10% voucher applied");

    act(() => result.current.setVoucherCode("NOPE"));
    await act(async () => { await result.current.applyVoucher(); });
    expect(result.current.voucher).toBeUndefined();
    expect(result.current.error).toMatch(/not eligible/i);

    act(() => result.current.setGiftCode("GIFT25"));
    await act(async () => { await result.current.applyGift(); });
    expect(result.current.gifts).toHaveLength(1);
    expect(result.current.notice).toContain("GIFT25");

    act(() => result.current.setGiftCode("gift25"));
    await act(async () => { await result.current.applyGift(); });
    expect(result.current.error).toMatch(/already applied/i);
    act(() => result.current.removeGift("GIFT25"));
    expect(result.current.gifts).toHaveLength(0);

    await act(async () => { await result.current.requestB2BQuote(); });
    expect(result.current.quoteRequest?.status).toBe("submitted");
    expect(result.current.notice).toMatch(/quote request submitted/i);
    await act(async () => { await result.current.approveB2BQuote(); });
    expect(result.current.quoteRequest?.status).toBe("approved");

    await act(async () => { await result.current.placeOrder(); });
    expect(result.current.order?.number).toBe("SO-100001");
    expect(result.current.step).toBe("order-result");
    expect(result.current.paymentState).toBe("authorized");
    expect(result.current.showRetry).toBe(false);

    await act(async () => { await result.current.refreshOrder(); });
    expect(result.current.notice).toMatch(/refreshed/i);
  });

  it("skips the quote step when the store config does not require it", async () => {
    const { result } = mount("/cart", { quoteMode: "auto" });
    expect(result.current.quoteStepEnabled).toBe(false);
    expect(result.current.steps).toEqual(["cart", "checkout", "order-result"]);
    await act(async () => { await result.current.addToCart(); });
    expect(result.current.step).toBe("checkout");
    await act(async () => { await result.current.placeOrder(); });
    expect(result.current.quote).toBeDefined();
    expect(result.current.order?.number).toBe("SO-100001");
  });

  it("validates quantity and fails closed while offline", async () => {
    const { result } = mount();
    act(() => result.current.editQuantity("abc"));
    expect(result.current.quantityError).toMatch(/whole-number/i);
    act(() => result.current.editQuantity("0"));
    expect(result.current.quantityError).toMatch(/at least 1/i);
    act(() => result.current.editQuantity("3"));
    expect(result.current.quantity).toBe(3);
    expect(result.current.quantityError).toBe("");

    setOnline(false);
    await act(async () => { window.dispatchEvent(new Event("offline")); });
    expect(result.current.error).toBe("");
    await act(async () => { await result.current.addToCart(); });
    expect(result.current.error).toMatch(/offline/i);
    await act(async () => { await result.current.createQuote(); });
    expect(result.current.error).toMatch(/offline/i);
    await act(async () => { await result.current.placeOrder(); });
    expect(result.current.error).toMatch(/offline/i);
  });

  it("reports checkout validation, payment failures and provider outcomes", async () => {
    {
      const { result } = mount("/checkout");
      act(() => result.current.setEmail("not-an-email"));
      await act(async () => { await result.current.placeOrder(); });
      expect(result.current.error).toMatch(/valid email/i);
      expect(result.current.fieldErrors.email).toBeDefined();
    }

    {
      const { result } = mount("/checkout");
      act(() => result.current.setAddress({ name: "", line1: "", city: "", country: "", postal_code: "" }));
      await act(async () => { await result.current.placeOrder(); });
      expect(result.current.fieldErrors.name).toBeDefined();
      expect(result.current.step).toBe("quote");
    }

    {
      const { result } = mount("/checkout?payment-failure=1");
      await act(async () => { await result.current.placeOrder(); });
      expect(result.current.error).toMatch(/declined/i);
      expect(result.current.paymentState).toBe("failed");
      expect(result.current.showRetry).toBe(true);
    }

    {
      const { result } = mount("/checkout?payment-unknown=1");
      await act(async () => { await result.current.placeOrder(); });
      expect(result.current.error).toMatch(/unknown/i);
      expect(result.current.paymentState).toBe("unknown");
    }

    {
      const { result } = mount("/checkout?payment-pending=1");
      await act(async () => { await result.current.placeOrder(); });
      expect(result.current.paymentState).toBe("pending");
      expect(result.current.step).toBe("order-result");
      expect(result.current.isPending).toBe(true);
    }

    {
      const { result } = mount("/checkout?timeout=1");
      await act(async () => { await result.current.placeOrder(); });
      expect(result.current.order?.number).toBe("SO-100001");
      expect(result.current.notice).toMatch(/reconciled safely/i);
    }

    {
      vi.stubEnv("NEXT_PUBLIC_STOREFRONT_ALLOW_FIXTURE", "0");
      const { result } = mount("/checkout");
      expect(result.current.checkoutUnavailable).toBe(true);
      await act(async () => { await result.current.placeOrder(); });
      expect(result.current.error).toBe(CHECKOUT_UNAVAILABLE_NOTICE);
    }
  });

  it("manages the account session, profile, password and subscriptions", async () => {
    const { result } = mount("/account");

    act(() => {
      result.current.setLoginEmail("buyer@example.com");
      result.current.setPassword("fixture-password");
    });
    await act(async () => { await result.current.login(); });
    expect(result.current.sessionToken).toBe("customer-token-fixture");
    expect(result.current.accountName).toBe("Nguyen Van A");
    expect(result.current.subscriptionsState).toBe("ready");
    expect(result.current.subscriptions).toHaveLength(1);

    act(() => {
      result.current.setDisplayName("New Name");
      result.current.setBirthdate("1990-01-01");
    });
    await act(async () => { await result.current.saveName(); });
    expect(result.current.accountName).toBe("New Name");
    expect(result.current.birthdate).toBe("1990-01-01");
    expect(result.current.nameNotice).toMatch(/saved/i);

    await act(async () => { await result.current.resendVerification(); });
    expect(result.current.resendNotice).toMatch(/verification email sent/i);

    act(() => {
      result.current.setCurrentPw("fixture-password");
      result.current.setNewPw("new-password");
    });
    await act(async () => { await result.current.changePw(); });
    expect(result.current.pwNotice).toMatch(/password changed/i);
    expect(result.current.editingPw).toBe(false);

    await act(async () => { await result.current.loadSubscriptionDetail("subscription-fixture-1"); });
    expect(result.current.subscription?.product_name).toBe("Siro monthly delivery");
    await act(async () => { await result.current.loadSubscriptionDetail("missing"); });
    expect(result.current.error).toMatch(/subscription not found/i);

    await act(async () => { await result.current.loadOrderDetail("missing"); });
    expect(result.current.error).toMatch(/order not found/i);

    act(() => result.current.logout());
    expect(result.current.sessionToken).toBe("");
    expect(result.current.subscriptions).toHaveLength(0);
    expect(result.current.authState).toBe("Signed out");
  });

  it("rejects missing credentials and shows a sign-in failure", async () => {
    {
      const { result } = mount("/account");
      act(() => {
        result.current.setLoginEmail("");
        result.current.setPassword("");
      });
      await act(async () => { await result.current.login(); });
      expect(result.current.error).toMatch(/enter your email and password/i);
    }

    {
      const { result } = mount("/account");
      act(() => {
        result.current.setLoginEmail("buyer@example.com");
        result.current.setPassword("wrong-password");
      });
      await act(async () => { await result.current.login(); });
      expect(result.current.error).toMatch(/incorrect|failed/i);
      expect(result.current.authState).toMatch(/could not sign you in/i);
    }
  });

  it("recovers guest orders and order details", async () => {
    {
      const { result } = mount("/account");
      act(() => result.current.setGuestEmail("buyer@example.com"));
      await act(async () => { await result.current.loadGuestOrder("guest-order-token-fixture"); });
      expect(result.current.order?.number).toBe("SO-100001");
      expect(result.current.step).toBe("order-detail");
      expect(result.current.notice).toMatch(/guest order access verified/i);
      expect(window.location.search).not.toContain("guest_token");
    }

    {
      const { result } = mount("/account");
      await act(async () => { await result.current.loadGuestOrder("bad-token"); });
      expect(result.current.error).toMatch(/guest order link is invalid/i);
    }

    {
      const { result } = mount("/account");
      await act(async () => { await result.current.loadOrderDetail("order-1"); });
      expect(result.current.authState).toMatch(/sign in to view order details/i);
    }

    {
      const { result } = mount("/account");
      await act(async () => { await result.current.refreshOrder(); });
      expect(result.current.notice).toMatch(/no confirmed order/i);
      await act(async () => { await result.current.logout(); });
      expect(result.current.order).toBeUndefined();
    }
  });

  it("handles pickup fulfillment, proof upload and proof submission", async () => {
    const createObjectURL = vi.fn(() => "blob:proof");
    Object.defineProperty(URL, "createObjectURL", { configurable: true, value: createObjectURL });

    const { result } = mount("/quote");
    await act(async () => { await result.current.addToCart(); });
    act(() => result.current.selectFulfillment("pickup"));
    await act(async () => { await Promise.resolve(); });
    expect(result.current.pickupPoints).toHaveLength(1);
    await act(async () => { await result.current.createQuote(); });
    expect(result.current.error).toMatch(/pickup point/i);
    act(() => result.current.setPickupPoint("HN-01"));
    await act(async () => { await result.current.createQuote(); });
    expect(result.current.quote?.pickup?.code).toBe("HN-01");

    await act(async () => { await result.current.submitProof(undefined, "result"); });
    expect(result.current.proofError).toMatch(/enter a reference or upload a receipt/i);

    const file = new File(["receipt"], "receipt.png", { type: "image/png" });
    await act(async () => { await result.current.uploadProofFile(file, "result"); });
    expect(result.current.proofUrl).toBe("blob:proof");
    expect(result.current.notice).toMatch(/proof ready/i);
    await act(async () => { await result.current.uploadProofFile(file, "detail"); });

    await act(async () => { await result.current.placeOrder(); });
    const order = result.current.order!;
    act(() => result.current.setProofReference("REF-1"));
    const transport = vi.fn(async () => new Response(JSON.stringify({ success: true }), { status: 200, headers: { "Content-Type": "application/json" } }));
    vi.stubGlobal("fetch", transport);
    await act(async () => { await result.current.submitProof(order, "result"); });
    expect(result.current.notice).toMatch(/proof submitted/i);
    expect(transport).toHaveBeenCalled();

    vi.stubGlobal("fetch", vi.fn(async () => new Response(JSON.stringify({ message: "rejected" }), { status: 422, headers: { "Content-Type": "application/json" } })));
    await act(async () => { await result.current.submitProof(order, "detail"); });
    expect(result.current.proofError).toMatch(/rejected/);
  });
});
