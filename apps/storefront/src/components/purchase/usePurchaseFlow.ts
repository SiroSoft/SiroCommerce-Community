"use client";

import { useEffect, useRef, useState } from "react";
import { browserAuthStorage, isValidJwt } from "@sirocommerce/auth";
import { fixtureProduct } from "@/fixtures/purchase";
import {
  PurchaseError,
  type Address,
  type Cart,
  type GiftTender,
  type Order,
  type PaymentMethod,
  type PickupPoint,
  type ProductHandoff,
  type PurchaseOperations,
  type Quote,
  type Subscription,
  type Voucher,
} from "@/purchase/contracts";
import { ApiPurchaseAdapter } from "@/purchase/api-adapter";
import { FixturePurchaseAdapter } from "@/purchase/fixture-adapter";
import { stableIdempotencyKey } from "@/purchase/idempotency";
import { PurchaseService } from "@/purchase/service";
import { fill } from "@/lib/i18n";
import { useCopy, useLocale } from "@/lib/i18n-client";
import { readCartCookies } from "@/lib/cart-session";
import type { QuoteRequest, StorefrontCommerceContext } from "@/purchase/b2b";
import { CHECKOUT_ADDRESS_FIELDS, checkoutSteps, promoFeedback, quoteBreakdown, validateCheckoutFields, validateQuantity, type ValidationCopy } from "@/purchase/checkout-ux";
import type { QuoteMode } from "@/services/storefront-config";
import { trackStorefrontEvent } from "@/services/storefront-events";
import { setCustomerAccessToken } from "@/services/customer";
import { parseDeepLink } from "@/lib/deep-links";
import { readCartRecovery, recoveryKey } from "@/lib/pwa";
import { usePurchaseVariant } from "@/presentation/variant-context";
import { CHECKOUT_UNAVAILABLE_NOTICE, resolvePurchaseMode } from "./mode";
import { focusFirstErrorField } from "./AddressStep";
import {
  bankTransferInstructions,
  blankAddress,
  blankHandoff,
  cartLineKey,
  createUnavailableAdapter,
  fixtureAddress,
  formatMoney,
  go,
  parseHandoff,
  persistSession,
  scope,
  sessionGet,
  sessionRemove,
  sessionSet,
  supportEmail,
  testMode,
} from "./helpers";

export function usePurchaseFlow({ initialStep, initialCart, flash, quoteMode = "required" }: { initialStep?: string; initialCart?: Cart; flash?: "added" | "updated" | "removed" | "failed"; quoteMode?: QuoteMode } = {}) {
  const { live: liveMode, fixture: fixtureMode, unavailable: checkoutUnavailable } = resolvePurchaseMode();
  const quoteStepEnabled = quoteMode === "required";
  const steps = quoteStepEnabled ? checkoutSteps : checkoutSteps.filter((item) => item !== "quote");
  const defaultAddress = fixtureMode ? fixtureAddress : blankAddress;
  const [step, setStep] = useState(initialStep === "quote" && !quoteStepEnabled ? "checkout" : initialStep ?? "cart");
  const [quantity, setQuantity] = useState(1);
  const [quantityError, setQuantityError] = useState("");
  const [variant, setVariant] = useState(fixtureMode ? "siro-dao" : "");
  const [selectedHandoff, setSelectedHandoff] = useState<ProductHandoff>(() => fixtureMode ? fixtureProduct : blankHandoff(process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? "USD"));
  useEffect(() => { trackStorefrontEvent("checkout_started"); }, []);
  const [selectedOptions, setSelectedOptions] = useState<Record<string, string>>({});
  const [email, setEmail] = useState(fixtureMode ? "buyer@example.com" : "");
  const [loginEmail, setLoginEmail] = useState(fixtureMode ? "buyer@example.com" : "");
  const [password, setPassword] = useState("");
  const [turnstileToken, setTurnstileToken] = useState("");
  const [turnstileKey, setTurnstileKey] = useState(0);
  const [error, setError] = useState("");
  const [handoffError, setHandoffError] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [notice, setNotice] = useState("");
  const [order, setOrder] = useState<Order>();
  const [authState, setAuthState] = useState("");
  const [fixtureStatus, setFixtureStatus] = useState("");
  const [history, setHistory] = useState<Order[]>([]);
  const [orderQuery, setOrderQuery] = useState("");
  const [orderStatus, setOrderStatus] = useState("all");
  // My Account returns: order preselected from the order-detail "Request return" entry point.
  const [returnOrderId, setReturnOrderId] = useState("");
  const [subscriptions, setSubscriptions] = useState<Subscription[]>([]);
  const [subscription, setSubscription] = useState<Subscription>();
  const [subscriptionsState, setSubscriptionsState] = useState<"idle" | "loading" | "ready" | "unavailable">("idle");
  const [accountName, setAccountName] = useState("");
  const [editingName, setEditingName] = useState(false);
  const [displayName, setDisplayName] = useState("");
  const [birthdate, setBirthdate] = useState("");
  const [nameNotice, setNameNotice] = useState("");
  const [emailVerified, setEmailVerified] = useState(true);
  const [resendNotice, setResendNotice] = useState("");
  const [editingPw, setEditingPw] = useState(false);
  const [currentPw, setCurrentPw] = useState("");
  const [newPw, setNewPw] = useState("");
  const [pwNotice, setPwNotice] = useState("");
  const [cart, setCart] = useState<Cart | undefined>(initialCart);
  const [cartFlash, setCartFlash] = useState<"added" | "updated" | "removed" | "failed" | undefined>(flash);
  const [lineBusy, setLineBusy] = useState<Record<string, boolean>>({});
  useEffect(() => {
    if (initialCart) {
      adapter.hydrate?.(initialCart);
      publishBagCount(initialCart);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  const [quote, setQuote] = useState<Quote>();
  const [voucherCode, setVoucherCode] = useState("");
  const [voucher, setVoucher] = useState<Voucher>();
  const [voucherSubmitting, setVoucherSubmitting] = useState(false);
  const [giftCode, setGiftCode] = useState("");
  const [gifts, setGifts] = useState<GiftTender[]>([]);
  const [giftSubmitting, setGiftSubmitting] = useState(false);
  const giftCodes = gifts.map((tender) => tender.code);
  const [cartSyncing, setCartSyncing] = useState(false);
  const [address, setAddress] = useState<Address>(defaultAddress);
  const locale = useLocale();
  const copy = useCopy();
  const money = (amount: number | undefined, currency: string = scope.currency) => formatMoney(amount, currency, locale, copy.purchase.calculatedByStore);
  const validationCopy: ValidationCopy = {
    wholeNumber: copy.validation.wholeNumber,
    atLeast: copy.validation.atLeast,
    validEmail: copy.validation.validEmail,
    fieldRequired: copy.validation.fieldRequired,
    promoApplied: copy.validation.promoApplied,
    promoFailed: copy.validation.promoFailed,
  };
  const [shippingMethod, setShippingMethod] = useState("standard");
  const [fulfillmentMethod, setFulfillmentMethod] = useState("delivery");
  const [pickupPoint, setPickupPoint] = useState("");
  const [pickupPoints, setPickupPoints] = useState<PickupPoint[]>([]);
  const isPickup = fulfillmentMethod === "pickup";
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>("cash_on_delivery");
  const [paymentState, setPaymentState] = useState("idle");
  const [proofReference, setProofReference] = useState("");
  const [proofUrl, setProofUrl] = useState("");
  const [proofUploading, setProofUploading] = useState(false);
  const [proofError, setProofError] = useState("");
  const [refreshing, setRefreshing] = useState(false);
  const [apiError, setApiError] = useState("");
  const [sessionToken, setSessionToken] = useState("");
  const [guestToken, setGuestToken] = useState("");
  const [guestEmail, setGuestEmail] = useState(fixtureMode ? "buyer@example.com" : "");
  const [submitting, setSubmitting] = useState(false);
  const [commerceContext, setCommerceContext] = useState<StorefrontCommerceContext>();
  const [quoteRequest, setQuoteRequest] = useState<QuoteRequest>();
  const [paymentAttempt, setPaymentAttempt] = useState(0);
  const [orderKey, setOrderKey] = useState("");
  const [checkoutId, setCheckoutId] = useState("");
  const [isOnline, setIsOnline] = useState(true);
  const actionInFlight = useRef(false);
  const userNavigated = useRef(false);
  const checkoutQuoteKey = useRef("");
  const authStorage = useRef(browserAuthStorage("siro_storefront_", { validateAccessToken: (token: string) => isValidJwt(token) || (fixtureMode && (/^fixture-/.test(token) || token === "customer-token-fixture")) })).current;
  const flowGeneration = useRef(0);
  const injected = useRef({ failure: false, unknown: false, pending: false, timeout: false });
  const adapter = useRef<PurchaseOperations>(liveMode
    ? new ApiPurchaseAdapter({
      baseUrl: process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "http://127.0.0.1:1",
      getAccessToken: () => authStorage.getAccessToken() ?? undefined,
      scope,
      ...readCartCookies(),
    })
    : fixtureMode ? new FixturePurchaseAdapter() : createUnavailableAdapter()).current;
  const service = useRef(new PurchaseService(adapter)).current;
  const product = { ...selectedHandoff, product_id: selectedHandoff.product_id || variant, quantity };
  // A handoff price is only input for the server request, never a displayed commerce truth.
  const subtotal = cart?.subtotal.amount_minor ?? (product.unit_price.amount_minor ?? 0) * quantity;
  const currency = quote?.total.currency ?? cart?.subtotal.currency ?? scope.currency;
  const shipping = quote?.shipping.amount_minor;
  const tax = quote?.tax.amount_minor;
  const total = quote?.total.amount_minor;
  const addressComplete = CHECKOUT_ADDRESS_FIELDS.every((field) => address[field]?.trim());
  const giftKey = giftCodes.join(",");

  useEffect(() => {
    const generation = flowGeneration.current;
    setIsOnline(navigator.onLine);
    const onConnectionChange = () => {
      setIsOnline(navigator.onLine);
      if (!navigator.onLine) return;
      const recovered = readCartRecovery(sessionGet(recoveryKey));
      if (recovered && typeof recovered.product_id === "string" && recovered.product_id.trim()) void adapter.addToCart(recovered as typeof product).then((next) => { if (flowGeneration.current === generation) setCart(next); }).catch(() => undefined);
    };
    window.addEventListener("online", onConnectionChange);
    window.addEventListener("offline", onConnectionChange);
    const currentStep = window.location.pathname.slice(1) || "cart";
    const startingFreshCart = currentStep === "cart";
    // Do not let the mount reconciliation overwrite a click that already changed the URL.
    if (!userNavigated.current && (window.location.pathname.slice(1) || "cart") === currentStep) setStep(currentStep === "quote" && !quoteStepEnabled ? "checkout" : currentStep);
    setApiError(new URLSearchParams(window.location.search).get("api") ?? "");
    const parsedHandoff = parseHandoff(window.location.search, copy.handoff, { live: liveMode, fixture: fixtureMode, unavailable: checkoutUnavailable });
    if (parsedHandoff.handoff) { setSelectedHandoff(parsedHandoff.handoff); setSelectedOptions(parsedHandoff.options); setQuantity(parsedHandoff.handoff.quantity); setVariant(parsedHandoff.handoff.product_id); }
    if (parsedHandoff.invalid) { setHandoffError(parsedHandoff.invalid); setError(parsedHandoff.invalid); }
    const hasScopedLinkParameters = ["store", "channel", "locale", "currency"].some((key) => new URLSearchParams(window.location.search).has(key));
    const deepLink = hasScopedLinkParameters ? parseDeepLink(window.location.href, scope) : {};
    if (deepLink.error === "scope_mismatch" || deepLink.error === "invalid") setHandoffError(copy.handoff.staleLink);
    const token = authStorage.getAccessToken() ?? "";
    setSessionToken(token);
    setCustomerAccessToken(token || undefined);
    if (token) void loadSession(token, generation);
    else {
      // A reload or navigation drops the memory-only access token. The
      // persisted refresh token restores the session without asking twice.
      const refresh = authStorage.getRefreshToken();
      if (refresh && service.refreshSession) {
        void service.refreshSession(refresh).then((session) => {
          if (flowGeneration.current !== generation) return;
          setCustomerAccessToken(session.access_token);
          persistSession(authStorage, session);
          setSessionToken(session.access_token);
          void loadSession(session.access_token, flowGeneration.current);
        }).catch(() => undefined);
      }
    }
    if (adapter.getCommerceContext) void adapter.getCommerceContext().then((next) => { if (flowGeneration.current === generation) setCommerceContext(next); }).catch(() => undefined);
    const guest = deepLink.link?.guestToken ?? new URLSearchParams(window.location.search).get("guest_token");
    if (guest && deepLink.link?.orderId && adapter.getGuestOrderByToken) { setGuestToken(guest); void loadGuestOrderByToken(deepLink.link.orderId, guest, generation); }
    else if (guest) { setGuestToken(guest); void loadGuestOrder(guest, generation); }
    const savedKey = startingFreshCart ? null : sessionGet("siro.purchase.order-key");
    const savedCheckout = startingFreshCart ? null : sessionGet("siro.purchase.checkout-id");
    if (startingFreshCart) {
      sessionRemove("siro.purchase.order-key");
      sessionRemove("siro.purchase.checkout-id");
      sessionRemove("siro.purchase.payment-status");
      sessionRemove("siro.purchase.order");
    }
    const onPopState = () => setStep(window.location.pathname.slice(1) || "cart");
    window.addEventListener("popstate", onPopState);
    if (savedKey) setOrderKey(savedKey);
    if (savedCheckout) setCheckoutId(savedCheckout);
    const savedCart = sessionGet(recoveryKey);
    const savedProduct = readCartRecovery(savedCart);
    const savedProductId = typeof savedProduct?.product_id === "string" ? savedProduct.product_id.trim() : "";
    if (savedProduct && savedProductId && navigator.onLine) {
      const restoredProduct = savedProduct as typeof product;
      if (!parsedHandoff.handoff) { setVariant(restoredProduct.product_id); setQuantity(restoredProduct.quantity); setSelectedHandoff(restoredProduct); }
      void adapter.addToCart(restoredProduct).then((next) => { if (flowGeneration.current === generation) setCart(next); }).catch(() => undefined);
    } else if (savedCart && !savedProductId) {
      sessionRemove(recoveryKey);
    }
    const onAuthStorage = (event: StorageEvent) => {
      if (event.key !== "siro_storefront_refresh_token" && event.key !== "siro_storefront_access_token") return;
      const next = authStorage.getAccessToken() ?? "";
      if (!next) { flowGeneration.current += 1; setSessionToken(""); setHistory([]); setAuthState(copy.account.signedOutState); }
      else if (next !== sessionToken) { setSessionToken(next); void loadSession(next, flowGeneration.current); }
    };
    window.addEventListener("storage", onAuthStorage);
    return () => { flowGeneration.current += 1; window.removeEventListener("popstate", onPopState); window.removeEventListener("storage", onAuthStorage); window.removeEventListener("online", onConnectionChange); window.removeEventListener("offline", onConnectionChange); authStorage.dispose?.(); };
  }, []);

  useEffect(() => {
    if (quoteStepEnabled || step !== "checkout" || !cart || !addressComplete || (isPickup && !pickupPoint)) return;
    const generation = flowGeneration.current;
    const timer = window.setTimeout(() => {
      if (actionInFlight.current) return;
      void service.quote({
        cart_id: cart.id,
        shipping_address: address,
        shipping_method: shippingMethod,
        ...(voucher ? { coupon_code: voucher.code } : {}),
        ...(giftCodes.length > 0 ? { gift_codes: giftCodes } : {}),
        ...(isPickup ? { fulfillment_method: "pickup" as const, pickup_point: pickupPoint } : {}),
      }).then((next) => { if (flowGeneration.current === generation) setQuote(next); }).catch(() => undefined);
    }, 400);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [quoteStepEnabled, step, cart, addressComplete, address.name, address.line1, address.city, address.country, address.postal_code, shippingMethod, isPickup, pickupPoint, voucher?.code, giftKey]);

  function navigate(next: string) {
    userNavigated.current = true;
    setError(""); setFieldErrors({}); setNotice(""); setStep(next); go(next);
  }

  function editQuantity(value: string | number) {
    const validation = validateQuantity(value, 1, validationCopy);
    if (!validation.valid) { setQuantityError(validation.message); return; }
    setQuantityError("");
    setQuantity(validation.value);
    if (cart) void syncCart({ ...product, quantity: validation.value }).catch((reason) => fail(reason, "Unable to update quantity."));
  }

  function fail(value: unknown, fallback: string) {
    const purchaseError = value instanceof PurchaseError ? value : undefined;
    if (purchaseError?.code === "unauthorized") {
      authStorage.clear();
      setSessionToken("");
      setHistory([]);
      setAuthState(copy.account.sessionExpired);
    }
    setError(purchaseError?.code === "unknown_outcome" && purchaseError.message === "The request result is unknown" ? copy.purchase.networkFailed : (purchaseError?.message ?? (value instanceof Error ? value.message : fallback)));
    const errors = purchaseError?.fieldErrors ?? {};
    setFieldErrors(errors);
    // Address inputs live on the quote step; checkout only renders the email field.
    focusFirstErrorField(errors, step, () => { if (!quoteStepEnabled) return; setStep("quote"); go("quote"); });
  }

  async function syncCart(nextProduct = product) {
    if (!navigator.onLine) throw new Error("You are offline. Reconnect before checking cart availability.");
    // Never POST an empty or placeholder variant to the bag endpoint.
    if (!nextProduct.product_id.trim() || !Number.isSafeInteger(nextProduct.quantity) || nextProduct.quantity < 1) {
      throw new PurchaseError("validation", copy.purchase.incompleteLink, { status: 422, fieldErrors: { product_id: [copy.purchase.incompleteLink] } });
    }
    const generation = flowGeneration.current;
    const next = await service.addProduct(nextProduct);
    if (flowGeneration.current !== generation) return next;
    setCart(next);
    sessionSet(recoveryKey, JSON.stringify({ ...nextProduct, cartQuantity: next.lines.reduce((sum, line) => sum + line.quantity, 0), savedAt: Date.now() }));
    return next;
  }

  function publishBagCount(next: Cart | undefined) {
    try {
      const count = next?.lines.reduce((sum, line) => sum + line.quantity, 0) ?? 0;
      document.cookie = `siro_bag_count=${count}; path=/; max-age=${60 * 60 * 24 * 30}; samesite=lax`;
      window.dispatchEvent(new CustomEvent("siro:bag-updated", { detail: count }));
    } catch { /* The badge is advisory; a stale count never blocks the bag. */ }
  }

  async function changeLineQuantity(lineId: string, delta: -1 | 1) {
    if (!cart || actionInFlight.current || !adapter.setLineQuantity) return;
    const line = cart.lines.find((item) => cartLineKey(item) === lineId);
    if (!line) return;
    const nextQuantity = line.quantity + delta;
    if (nextQuantity < 1) { await removeCartLine(lineId); return; }
    if (nextQuantity > 99) return;
    const previous = cart;
    const key = cartLineKey(line);
    actionInFlight.current = true;
    setLineBusy((current) => ({ ...current, [key]: true }));
    setCartFlash(undefined);
    setCart({ ...cart, lines: cart.lines.map((item) => cartLineKey(item) === lineId ? { ...item, quantity: nextQuantity, line_total: { ...item.line_total, amount_minor: item.unit_price.amount_minor * nextQuantity } } : item) });
    try {
      const next = await adapter.setLineQuantity(cart.id, lineId, nextQuantity);
      setCart(next);
      publishBagCount(next);
      setCartFlash("updated");
    } catch (value) { setCart(previous); fail(value, copy.purchase.updateFailed); }
    finally { actionInFlight.current = false; setLineBusy((current) => ({ ...current, [key]: false })); }
  }

  async function removeCartLine(lineId: string) {
    if (!cart || actionInFlight.current || !adapter.removeLine) return;
    const previous = cart;
    const key = lineId;
    actionInFlight.current = true;
    setLineBusy((current) => ({ ...current, [key]: true }));
    setCartFlash(undefined);
    setCart({ ...cart, lines: cart.lines.filter((item) => cartLineKey(item) !== lineId) });
    try {
      const next = await adapter.removeLine(cart.id, lineId);
      setCart(next);
      publishBagCount(next);
      setCartFlash("removed");
    } catch (value) { setCart(previous); fail(value, copy.purchase.removeFailed); }
    finally { actionInFlight.current = false; setLineBusy((current) => ({ ...current, [key]: false })); }
  }

  async function addToCart() {
    if (!isOnline) { setError(copy.purchase.offlineCart); return; }
    if (handoffError) { setError(handoffError); return; }
    // A bag restored from the server cookie already has priced lines; nothing to add.
    if (cart && cart.lines.length > 0 && !product.product_id) { navigate(quoteStepEnabled ? "quote" : "checkout"); return; }
    if (actionInFlight.current) return;
    actionInFlight.current = true;
    setCartSyncing(true);
    try { await syncCart(); navigate(quoteStepEnabled ? "quote" : "checkout"); } catch (value) { fail(value, copy.purchase.addFailed); }
    finally { setCartSyncing(false); actionInFlight.current = false; }
  }

  async function createQuote() {
    if (!isOnline) { setError("You are offline. Reconnect before requesting a delivery quote."); return; }
    if (actionInFlight.current) return;
    actionInFlight.current = true;
    try {
      const next = cart ?? await syncCart();
      if (isPickup && !pickupPoint) { setError(copy.purchase.pointRequired); return; }
      const nextQuote = await service.quote({ cart_id: next.id, shipping_address: address, shipping_method: shippingMethod, ...(voucher ? { coupon_code: voucher.code } : {}), ...(giftCodes.length > 0 ? { gift_codes: giftCodes } : {}), ...(isPickup ? { fulfillment_method: "pickup", ...(pickupPoint ? { pickup_point: pickupPoint } : {}) } : {}) });
      setQuote(nextQuote);
      navigate("checkout");
    } catch (value) { fail(value, copy.purchase.quoteFailed); }
    finally { actionInFlight.current = false; }
  }

  async function requestB2BQuote() {
    if (!cart || !service.requestQuote) return;
    try { setError(""); setQuoteRequest(await service.requestQuote(cart.id)); setNotice(copy.purchase.b2bSubmitted); } catch (value) { fail(value, copy.purchase.b2bSubmitFailed); }
  }

  async function approveB2BQuote() {
    if (!quoteRequest) return;
    try { setError(""); setQuoteRequest(await service.approveQuote(quoteRequest.id)); setNotice(copy.purchase.b2bApproved); } catch (value) { fail(value, copy.purchase.b2bApproveFailed); }
  }

  async function applyVoucher() {
    if (voucherSubmitting) return;
    setVoucherSubmitting(true); setError("");
    try {
      const next = cart ?? await syncCart();
      const applied = await service.previewVoucher(next, voucherCode);
      setVoucher(applied); setNotice(applied.message ?? promoFeedback(applied.code, applied.discount.amount_minor, validationCopy));
    } catch (value) { setVoucher(undefined); fail(value, copy.purchase.voucherFailed); }
    finally { setVoucherSubmitting(false); }
  }

  async function applyGift() {
    if (giftSubmitting) return;
    if (gifts.length >= 5) { setError(copy.purchase.tooManyGifts); return; }
    if (gifts.some((tender) => tender.code === giftCode.trim().toUpperCase())) { setError(copy.purchase.duplicateGift); return; }
    setGiftSubmitting(true); setError("");
    try {
      const next = cart ?? await syncCart();
      const applied = await service.previewGift(next, giftCode);
      setGifts((current) => [...current, applied]); setGiftCode(""); setNotice(fill(copy.purchase.giftApplied, { code: applied.code }));
    } catch (value) { fail(value, copy.purchase.giftFailed); }
    finally { setGiftSubmitting(false); }
  }

  function removeGift(code: string) {
    setGifts((current) => current.filter((tender) => tender.code !== code));
  }

  async function placeOrder() {
    if (checkoutUnavailable) { setError(CHECKOUT_UNAVAILABLE_NOTICE); return; }
    if (submitting || actionInFlight.current) return;
    if (!isOnline) { setError(copy.purchase.offlineCheckout); return; }
    const checkoutErrors = validateCheckoutFields(address, email, validationCopy);
    if (Object.keys(checkoutErrors).length > 0) {
      const message = checkoutErrors.email?.[0] ?? copy.purchase.invalidCheckout;
      fail(new PurchaseError("validation", message, { status: 422, fieldErrors: checkoutErrors }), message);
      return;
    }
    const generation = flowGeneration.current;
    actionInFlight.current = true;
    setSubmitting(true); setError("");
    try {
      const next = cart ?? await syncCart();
      const nextQuote = await service.quote({ cart_id: next.id, shipping_address: address, shipping_method: shippingMethod, ...(voucher ? { coupon_code: voucher.code } : {}), ...(giftCodes.length > 0 ? { gift_codes: giftCodes } : {}), ...(isPickup ? { fulfillment_method: "pickup", ...(pickupPoint ? { pickup_point: pickupPoint } : {}) } : {}) });
      setQuote(nextQuote);
      const checkout = await service.checkout({ cart_id: next.id, shipping_address: address, shipping_method: shippingMethod, email, ...(giftCodes.length > 0 ? { gift_codes: giftCodes } : {}), ...(isPickup ? { fulfillment_method: "pickup", ...(pickupPoint ? { pickup_point: pickupPoint } : {}) } : {}) });
      if (flowGeneration.current !== generation) return;
      setCheckoutId(checkout.id);
      sessionSet("siro.purchase.checkout-id", checkout.id);
      const nextAttempt = paymentAttempt + 1;
      setPaymentAttempt(nextAttempt);
      const expectedKey = stableIdempotencyKey("place-order", { checkout_id: checkout.id, payment_method: paymentMethod, lines: next.lines.map(({ product_id, quantity }) => ({ product_id, quantity })) });
      const key = orderKey === expectedKey ? orderKey : expectedKey;
      setOrderKey(key);
      sessionSet("siro.purchase.order-key", key);
      const params = new URLSearchParams(window.location.search);
      if (testMode && adapter instanceof FixturePurchaseAdapter) {
        if (params.has("payment-failure") && !injected.current.failure) { adapter.simulatePaymentFailureOnce(); injected.current.failure = true; }
        if (params.has("payment-unknown") && !injected.current.unknown) { adapter.simulatePaymentUnknownOnce(); injected.current.unknown = true; }
        if (params.has("payment-pending") && !injected.current.pending) { adapter.simulatePaymentPendingOnce(); injected.current.pending = true; }
        if (params.has("timeout") && !injected.current.timeout) { adapter.simulateOrderTimeoutOnce(); injected.current.timeout = true; }
      }
      const payment = await service.pay(checkout.id, paymentMethod, `${key}-payment-${nextAttempt}`);
      if (flowGeneration.current !== generation) return;
      setPaymentState(payment.status);
      // Fixture "pending" is a simulated provider hold. In live mode a local
      // pending state only means authorization happens inside place, so the
      // order must still be submitted and its payment state becomes truth.
      if (payment.status === "pending" && !liveMode) { navigate("order-result"); if (!testMode) setNotice(copy.purchase.processingNote); return; }
      if (payment.status === "failed") throw new PurchaseError("conflict", payment.failure_reason ?? copy.purchase.authRequiredNote, { status: 409 });
      if (payment.status === "unknown") throw new PurchaseError("unknown_outcome", copy.purchase.unknownPayment);
      const result = await service.place({ checkout_id: checkout.id, payment_method: paymentMethod, idempotency_key: key });
      if (flowGeneration.current !== generation) return;
      setOrder(result); setPaymentState(result.payment.status); if (sessionToken) setHistory((current) => current.some((item) => item.id === result.id) ? current : [result, ...current]); navigate("order-result");
      if (params.has("timeout")) setNotice(testMode ? copy.purchase.reconciledFixture : copy.purchase.reconciledLive);
    } catch (value) { if (flowGeneration.current === generation) fail(value, copy.purchase.unknownOutcome); }
    finally { actionInFlight.current = false; if (flowGeneration.current === generation) setSubmitting(false); }
  }

  async function saveName() {
    if (!sessionToken || !displayName.trim()) return;
    try {
      const account = await service.updateAccount(sessionToken, { name: displayName.trim(), ...(birthdate ? { birthdate } : {}) });
      setAccountName(account.name || account.email);
      if (account.birthdate) setBirthdate(account.birthdate);
      setAuthState(fill(copy.account.signedInAs, { name: account.name || account.email }));
      setEditingName(false);
      setNameNotice(copy.account.nameSaved);
    } catch {
      setNameNotice(copy.account.nameSaveFailed);
    }
  }

  async function resendVerification() {
    if (!sessionToken) return;
    try {
      await service.resendVerification(sessionToken);
      setResendNotice(copy.auth.verificationSent);
    } catch (value) {
      if (value instanceof Error) setResendNotice(value.message);
    }
  }

  async function changePw() {
    if (!sessionToken) return;
    try {
      await service.changePassword(sessionToken, currentPw, newPw);
      setCurrentPw("");
      setNewPw("");
      setEditingPw(false);
      setPwNotice(copy.auth.passwordChanged);
    } catch (value) {
      if (value instanceof Error) setPwNotice(value.message);
    }
  }

  async function loadSession(token: string, generation = flowGeneration.current) {
    try {
      const account = await service.account(token);
      if (flowGeneration.current !== generation || authStorage.getAccessToken() !== token) return;
      setAccountName(account.name || account.email);
      if (account.email) setEmail((current) => current.trim() ? current : account.email);
      if (account.birthdate) setBirthdate(account.birthdate);
      setEmailVerified(account.email_verified ?? true);
      setAuthState(fill(copy.account.signedInAs, { name: account.name || account.email }));
      const orders = await service.orderHistory(token);
      if (flowGeneration.current !== generation || authStorage.getAccessToken() !== token) return;
      setHistory(orders);
      setSubscriptionsState("loading");
      try { setSubscriptions(await service.subscriptions(token)); setSubscriptionsState("ready"); } catch (value) { if (value instanceof PurchaseError && value.code === "forbidden") setSubscriptionsState("unavailable"); else throw value; }
    } catch (value) {
      if (flowGeneration.current !== generation || authStorage.getAccessToken() !== token) return;
      // Only an authentication failure ends the session. A missing order list
      // or an unavailable subscription read must never sign the customer out.
      if (value instanceof PurchaseError && value.code === "unauthorized") {
        authStorage.clear(); setSessionToken(""); fail(value, copy.account.sessionExpired);
      } else {
        fail(value, copy.account.sessionLoadFailed);
      }
    }
  }

  async function login(overridePassword?: string) {
    const generation = ++flowGeneration.current;
    try {
      const submittedPassword = password || overridePassword || (fixtureMode ? "fixture-password" : "");
      if (!loginEmail || !submittedPassword) throw new PurchaseError("validation", copy.account.missingCredentials, { status: 422, fieldErrors: { loginEmail: [copy.account.missingCredentials] } });
      const session = await service.login(loginEmail, submittedPassword, turnstileToken || undefined);
      if (flowGeneration.current !== generation) return;
      setCustomerAccessToken(session.access_token); persistSession(authStorage, session); setSessionToken(session.access_token); setPassword(""); setTurnstileToken("");
      await loadSession(session.access_token, generation);
    } catch (value) { setTurnstileKey((key) => key + 1); setTurnstileToken(""); fail(value, copy.account.authFailedNotice); setAuthState(copy.account.signInFailedNotice); }
  }

  async function authenticateFixtureCustomer() {
    await login("fixture-password");
    setAuthState(copy.account.signedIn);
  }

  function selectFixtureVariant(value: string) {
    const name = value === "siro-chanh" ? "Siro chanh" : "Siro dao";
    setVariant(value);
    setSelectedHandoff({ ...fixtureProduct, product_id: value, slug: value, name });
    setSelectedOptions({});
  }

  function logout() {
    // Revoke server-side before dropping local tokens so a stolen refresh
    // token cannot keep refreshing for up to 7 days after sign-out.
    const token = sessionToken;
    if (token) void service.logout(token).catch(() => undefined);
    flowGeneration.current += 1; authStorage.clear();
    try { window.dispatchEvent(new Event("siro:session-updated")); } catch { /* Non-browser renderers have no session listeners. */ }
    setCustomerAccessToken(undefined); setSessionToken(""); setHistory([]); setSubscriptions([]); setSubscription(undefined); setSubscriptionsState("idle"); setAuthState(copy.account.signedOutState); setOrder(undefined); setAccountName(""); setEditingName(false); setDisplayName(""); setBirthdate(""); setNameNotice(""); setTurnstileToken(""); setEmailVerified(true); setResendNotice(""); setEditingPw(false); setCurrentPw(""); setNewPw(""); setPwNotice("");
  }

  async function refreshOrder() {
    if (!orderKey) { setNotice(copy.purchase.noConfirmedOrder); return; }
    const generation = flowGeneration.current;
    const key = orderKey;
    setRefreshing(true);
    try {
      const refreshed = await service.reconcile({ checkout_id: checkoutId || "checkout-fixture-1", payment_method: "card", idempotency_key: key }, { attempts: 2 });
      if (flowGeneration.current !== generation || orderKey !== key) return;
      if (refreshed) { setOrder(refreshed); setHistory((current) => current.some((item) => item.id === refreshed.id) ? current : [refreshed, ...current]); setPaymentState(refreshed.payment.status); }
      setNotice(refreshed ? copy.account.orderRefreshed : copy.purchase.processingNote);
    } catch (value) { fail(value, copy.purchase.refreshFailed); }
    finally { if (flowGeneration.current === generation) setRefreshing(false); }
  }

  async function loadGuestOrder(token: string, generation = ++flowGeneration.current) {
    if (!token.trim()) { fail(new PurchaseError("validation", copy.account.guestTokenPrompt, { status: 422, fieldErrors: { guestToken: [copy.account.guestTokenRequired] } }), copy.account.guestRecoveryFailed); return; }
    try {
      const next = await service.guestOrder(token.trim(), guestEmail);
      if (flowGeneration.current !== generation) return;
      setOrder(next); setGuestToken(""); setNotice(copy.account.guestLoaded); setStep("order-detail");
      const url = new URL(window.location.href); url.searchParams.delete("guest_token"); window.history.replaceState({}, "", `${url.pathname}${url.search}`); go("order-detail");
    } catch (value) { if (flowGeneration.current === generation) fail(value, copy.account.guestLinkInvalid); }
  }

  async function loadGuestOrderByToken(orderId: string, token: string, generation = ++flowGeneration.current) {
    try {
      const next = await adapter.getGuestOrderByToken?.(orderId, token);
      if (!next || flowGeneration.current !== generation) return;
      setOrder(next); setGuestToken(""); setNotice(copy.account.guestLoaded); setStep("order-detail");
      const url = new URL(window.location.href); url.searchParams.delete("guest_token"); window.history.replaceState({}, "", `${url.pathname}${url.search}`); go("order-detail");
    } catch (value) { if (flowGeneration.current === generation) fail(value, copy.account.guestLinkInvalid); }
  }

  async function loadOrderDetail(orderId: string) {
    if (!sessionToken) { setAuthState(copy.account.signInToView); return; }
    const token = sessionToken;
    try { const next = await service.order(token, orderId); if (authStorage.getAccessToken() !== token) return; setOrder(next); setNotice(copy.account.orderLoaded); setStep("order-detail"); go("order-detail"); } catch (value) { if (authStorage.getAccessToken() === token) fail(value, copy.account.loadOrderFailed); }
  }

  async function refreshOrderDetail() {
    if (!order?.id || !sessionToken) return;
    const token = sessionToken;
    try { const next = await service.order(token, order.id); if (authStorage.getAccessToken() !== token) return; setOrder(next); setNotice(copy.account.orderRefreshed); } catch (value) { if (authStorage.getAccessToken() === token) fail(value, copy.account.refreshOrderFailed); }
  }

  async function loadSubscriptionDetail(subscriptionId: string) {
    if (!sessionToken) return;
    try { setSubscription(await service.subscription(sessionToken, subscriptionId)); } catch (value) { fail(value, copy.account.loadSubFailed); }
  }

  function selectFulfillment(value: string) {
    setFulfillmentMethod(value); setPickupPoint(""); setQuote(undefined);
    if (value === "pickup" && adapter.getPickupPoints) {
      void adapter.getPickupPoints().then(setPickupPoints).catch(() => setPickupPoints([]));
    }
  }

  async function uploadProofFile(file: File | undefined, variant: "result" | "detail") {
    if (!file) return;
    setProofUploading(true);
    if (variant === "result") setProofError("");
    try {
      if (sessionToken) {
        const fd = new FormData(); fd.append("file", file);
        const response = await fetch("/api/upload", { method: "POST", headers: { Authorization: `Bearer ${sessionToken}` }, body: fd });
        const json = await response.json().catch(() => ({} as Record<string, unknown>));
        const url = (json as { data?: { url?: string } }).data?.url;
        setProofUrl(url ?? URL.createObjectURL(file));
      } else {
        setProofUrl(URL.createObjectURL(file));
      }
      if (variant === "result") setNotice(copy.purchase.proofReady);
    } catch (value) {
      if (variant === "result") setProofError(value instanceof Error ? value.message : copy.purchase.proofUploadFailed);
    } finally {
      setProofUploading(false);
    }
  }

  async function submitProof(target: Order | undefined, variant: "result" | "detail") {
    if (!proofReference.trim() && !proofUrl) {
      setProofError(variant === "result" ? copy.purchase.proofNeedReferenceOrUpload : copy.purchase.proofNeedReference);
      return;
    }
    const paymentId = target?.payment.id ?? "";
    if (!paymentId) {
      setProofError(variant === "result" ? copy.purchase.proofPaymentNotFoundRefresh : copy.purchase.proofPaymentNotFound);
      return;
    }
    setProofUploading(true); setProofError("");
    try {
      const base = process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "";
      const headers: Record<string, string> = {
        "Content-Type": "application/json",
        "Idempotency-Key": `proof-${paymentId}-${(proofReference.trim() || "receipt").slice(0, 20)}`,
        "X-Store-Id": scope.store_id,
        "X-Channel-Id": scope.channel_id,
        "X-Locale": scope.locale,
        "X-Currency": scope.currency,
      };
      if (sessionToken) headers["Authorization"] = `Bearer ${sessionToken}`;
      if (guestToken) headers["X-Order-Token"] = guestToken;
      const response = await fetch(`${base}/api/v2/payments/${encodeURIComponent(paymentId)}/proof`, { method: "POST", headers, body: JSON.stringify({ reference: proofReference.trim() || "receipt", file_url: proofUrl || undefined }) });
      const json = await response.json().catch(() => ({} as Record<string, unknown>));
      if (!response.ok) throw new Error(String((json as Record<string, unknown>).message ?? copy.purchase.proofFailed));
      setNotice(variant === "result"
        ? (proofReference ? fill(copy.purchase.proofSubmittedRef, { ref: proofReference }) : copy.purchase.proofSubmitted)
        : fill(copy.purchase.proofSubmittedOrder, { order: target?.number ?? "", ref: proofReference || copy.purchase.receiptWord }));
    } catch (value) {
      setProofError(value instanceof Error ? value.message : copy.purchase.proofFailed);
    } finally {
      setProofUploading(false);
    }
  }

  const validationSummary = Object.entries(fieldErrors).flatMap(([field, messages]) => messages.map((message) => `${field}: ${message}`));
  const breakdown = quoteBreakdown(quote, { subtotal });
  const isPending = order?.status === "pending_reconciliation" || paymentState === "pending";
  const isUnknown = order?.payment.status === "unknown" || paymentState === "unknown";
  const showRetry = Boolean(error) || paymentState === "failed" || paymentState === "unknown";
  const visibleHistory = history.filter((item) => {
    const query = orderQuery.trim().toLowerCase();
    return (!query || item.number.toLowerCase().includes(query) || item.id.toLowerCase().includes(query)) && (orderStatus === "all" || item.status === orderStatus);
  });
  const actionLabel = step === "cart" ? copy.purchase.primaryCart : step === "quote" ? copy.purchase.primaryQuote : step === "checkout" ? (checkoutUnavailable || liveMode ? copy.purchase.unavailableTitle : copy.purchase.primaryCheckout) : copy.purchase.primaryDefault;
  const runPrimaryAction = () => { if (step === "cart") void addToCart(); else if (step === "quote") void createQuote(); else if (step === "checkout") void placeOrder(); };
  const stepLabel = (name: string) => copy.purchase.stepNames[name as keyof typeof copy.purchase.stepNames] ?? name.replace("-", " ");
  const addressFieldLabel = (field: string) => field === "name" ? copy.purchase.addressName : field === "line1" ? copy.purchase.addressLine1 : field === "city" ? copy.purchase.addressCity : field === "country" ? copy.purchase.addressCountry : field === "postal_code" ? copy.purchase.addressPostal : field.replace("_", " ");
  const purchaseVariant = usePurchaseVariant();

  return {
    copy, locale, money, step, setStep, navigate, actionLabel, runPrimaryAction, stepLabel, addressFieldLabel,
    quoteStepEnabled, steps,
    checkoutUnavailable, liveMode, fixtureMode, testMode,
    error, setError, fieldErrors, validationSummary, apiError, notice, setNotice,
    quantity, setQuantity, quantityError, editQuantity, product, selectedOptions, variant, selectFixtureVariant,
    cart, setCart, cartFlash, setCartFlash, lineBusy, changeLineQuantity, removeCartLine, subtotal,
    quote, setQuote, currency, shipping, tax, total, breakdown,
    address, setAddress,
    fulfillmentMethod, selectFulfillment, shippingMethod, setShippingMethod, pickupPoint, setPickupPoint, pickupPoints, isPickup,
    voucherCode, setVoucherCode, voucher, voucherSubmitting, applyVoucher,
    giftCode, setGiftCode, giftSubmitting, applyGift, gifts, removeGift,
    quoteRequest, requestB2BQuote, approveB2BQuote, createQuote, addToCart,
    email, setEmail,
    paymentMethod, setPaymentMethod, paymentState, placeOrder, submitting, showRetry,
    authState, history, visibleHistory, sessionToken,
    commerceContext, purchaseVariant,
    fixtureStatus, setFixtureStatus, authenticateFixtureCustomer,
    accountName, editingName, setEditingName, displayName, setDisplayName, birthdate, setBirthdate, saveName, nameNotice, setNameNotice,
    emailVerified, resendVerification, resendNotice,
    editingPw, setEditingPw, currentPw, setCurrentPw, newPw, setNewPw, changePw, pwNotice, setPwNotice,
    subscriptions, subscription, subscriptionsState, loadOrderDetail, loadSubscriptionDetail,
    guestToken, setGuestToken, guestEmail, setGuestEmail, loadGuestOrder,
    loginEmail, setLoginEmail, password, setPassword, turnstileKey, setTurnstileToken, login,
    logout, returnOrderId, setReturnOrderId,
    order, isPending, isUnknown, refreshing, refreshOrder, refreshOrderDetail,
    proofReference, setProofReference, proofUrl, setProofUrl, proofUploading, proofError, setProofError, uploadProofFile, submitProof,
    cartSyncing, supportEmail, bankTransferInstructions, fail,
  };
}

export type PurchaseController = ReturnType<typeof usePurchaseFlow>;
