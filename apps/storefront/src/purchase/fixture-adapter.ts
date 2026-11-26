import {
  PurchaseError,
  type Address,
  type Cart,
  type Checkout,
  type CheckoutInput,
  type CustomerAccount,
  type CustomerSession,
  type GiftCoverage,
  type GiftTender,
  type PickupPoint,
  type Payment,
  type PaymentMethod,
  type PlaceOrderInput,
  type ProductHandoff,
  type PurchaseOperations,
  type Quote,
  type QuoteInput,
  type Order,
  type Subscription,
  type Voucher,
} from "./contracts";
import { financialUnavailable, validateCommerceContext, type QuoteRequest, type StorefrontCommerceContext } from "./b2b";

type StoredMutation = { fingerprint: string; result: Payment | Order };

const money = (amount_minor: number) => ({ amount_minor, currency: "USD" });
const fingerprint = (value: unknown) => JSON.stringify(value);
const validAddress = (address: Address) => Object.values(address).every((value) => value.trim().length > 0);

export class FixturePurchaseAdapter implements PurchaseOperations {
  private cart: Cart = { id: "cart-fixture-1", token: "cart-fixture-1", lines: [], subtotal: money(0) };
  private quote?: Quote;
  private checkout?: Checkout;
  private readonly mutations = new Map<string, StoredMutation>();
  private order?: Order;
  private payment?: Payment;
  private orderTimeoutOnce = false;
  private paymentFailureOnce = false;
  private paymentUnknownOnce = false;
  private paymentPendingOnce = false;
  private quoteRequest?: QuoteRequest;
  private quoteExpiresAt = "2099-01-01T00:00:00.000Z";
  private readonly inventory = new Map<string, number>([
    ["sku-siro-001", 2],
    ["siro-dao", 2],
    ["siro-chanh", 2],
  ]);

  async getCommerceContext(): Promise<StorefrontCommerceContext> {
    return validateCommerceContext({
      organization: { id: "org-fixture-1", name: "Siro Wholesale", role: "approver" },
      customer: { id: "customer-fixture-1", name: "Nguyen Van A", email: "buyer@example.com" },
      store: { id: "storefront", name: "SiroCommerce Store", currency: "USD", locale: "en" },
      vendor: { id: "vendor-siro", name: "Siro Foods" },
      pricing: { customer: { price: money(125000), source: "customer", label: "Siro Wholesale price" }, tiers: [{ minimum: 10, unitPrice: money(105000), label: "10+ units" }] },
      orderPolicy: { minimumOrder: money(500000), minimumQuantity: 1, message: "Wholesale orders start at $5,000.00." },
      credit: financialUnavailable("Provider credit terms are not available in the storefront."),
      payout: financialUnavailable("Vendor payout details are not available in the storefront."),
      payment: financialUnavailable("Payment provider authorization is unavailable until configured."),
      marketplace: { ownerType: "vendor", ownerId: "vendor-siro", ownerName: "Siro Foods", commissionMessage: "Marketplace commission is handled by the platform. It is not added to your order total." },
    });
  }

  async requestQuote(): Promise<QuoteRequest> {
    this.quoteRequest = { id: "quote-request-fixture-1", status: "submitted", requestedAt: new Date().toISOString() };
    return this.quoteRequest;
  }

  async getQuoteRequest(id: string): Promise<QuoteRequest> {
    if (!this.quoteRequest || id !== this.quoteRequest.id) throw new PurchaseError("not_found", "Quote request not found", { status: 404 });
    return this.quoteRequest;
  }

  async approveQuote(id: string): Promise<QuoteRequest> {
    const request = await this.getQuoteRequest(id);
    this.quoteRequest = { ...request, status: "approved" };
    return this.quoteRequest;
  }

  setInventory(productId: string, available: number): void { this.inventory.set(productId, available); }

  simulateOrderTimeoutOnce(): void { this.orderTimeoutOnce = true; }

  simulatePaymentFailureOnce(): void { this.paymentFailureOnce = true; }

  simulatePaymentUnknownOnce(): void { this.paymentUnknownOnce = true; }

  simulatePaymentPendingOnce(): void { this.paymentPendingOnce = true; }

  setQuoteExpiry(expiresAt: string): void { this.quoteExpiresAt = expiresAt; }

  async addToCart(input: ProductHandoff): Promise<Cart> {
    if (this.cart.lines.length > 0 && this.cart.subtotal.currency !== input.unit_price.currency) {
      throw new PurchaseError("conflict", "Currency cannot be changed while the cart has items", { status: 409 });
    }
    if (input.quantity < 1 || !Number.isInteger(input.quantity)) {
      throw new PurchaseError("validation", "Quantity must be a positive integer", { status: 422, fieldErrors: { quantity: ["Must be at least 1"] } });
    }
    const available = this.inventory.get(input.product_id);
    if (available !== undefined && input.quantity > available) throw new PurchaseError("conflict", "Product is unavailable in the requested quantity", { status: 409, fieldErrors: { quantity: ["Insufficient inventory"] } });
    if (!Number.isInteger(input.unit_price.amount_minor) || input.unit_price.amount_minor < 0) {
      throw new PurchaseError("validation", "Price must be a non-negative minor-unit integer", { status: 422 });
    }
    const line = { ...input, line_total: money(input.unit_price.amount_minor * input.quantity) };
    const existing = this.cart.lines.findIndex((item) => item.product_id === input.product_id);
    this.cart.lines = existing === -1 ? [...this.cart.lines, line] : this.cart.lines.map((item, index) => index === existing ? line : item);
    this.cart.subtotal = money(this.cart.lines.reduce((total, item) => total + item.line_total.amount_minor, 0));
    this.quote = undefined;
    this.checkout = undefined;
    this.payment = undefined;
    return this.cart;
  }

  async getCart(cartId: string): Promise<Cart> {
    if (cartId !== this.cart.id) throw new PurchaseError("server_error", "Cart not found", { status: 500 });
    return this.cart;
  }

  async createQuote(input: QuoteInput): Promise<Quote> {
    if (input.cart_id !== this.cart.id) throw new PurchaseError("conflict", "Cart is not available", { status: 409 });
    if (this.cart.lines.length === 0) throw new PurchaseError("validation", "Cart is empty", { status: 422 });
    if (!validAddress(input.shipping_address) || !input.shipping_method) {
      throw new PurchaseError("validation", "Shipping details are incomplete", { status: 422, fieldErrors: { shipping_address: ["Complete address is required"] } });
    }
    const pickup = input.fulfillment_method === "pickup"
      ? (input.pickup_point === "HN-01"
        ? { code: "HN-01", name: "Hoan Kiem Store", address_line: "1 Trang Tien", city: "Hanoi", sla_hours: 24, ready_by: new Date(Date.now() + 24 * 3600 * 1000).toISOString() }
        : null)
      : undefined;
    if (input.fulfillment_method === "pickup" && !pickup) throw new PurchaseError("validation", "Select a pickup point", { status: 422 });
    if (input.fulfillment_method && input.fulfillment_method !== "pickup") throw new PurchaseError("validation", "Unsupported fulfillment method", { status: 422 });
    const shipping = money(input.fulfillment_method === "pickup" ? 0 : input.shipping_method === "express" ? 50000 : 25000);
    const tax = money(Math.round(this.cart.subtotal.amount_minor * 0.1));
    const discount = input.coupon_code ? await this.previewVoucher({ code: input.coupon_code, subtotal: this.cart.subtotal, lines: this.cart.lines }) : { code: "", eligibility: "eligible" as const, discount: money(0) };
    const codes = input.gift_codes ?? (input.gift_code ? [input.gift_code] : []);
    if (new Set(codes.map((code) => code.trim().toUpperCase())).size !== codes.length) throw new PurchaseError("validation", "Duplicate gift code", { status: 422 });
    if (codes.length > 5) throw new PurchaseError("validation", "Too many gift codes", { status: 422 });
    let remaining = this.cart.subtotal.amount_minor - discount.discount.amount_minor;
    const tenders: GiftCoverage[] = [];
    for (const code of codes) {
      const tender = await this.previewGift({ code, subtotal: { amount_minor: Math.max(0, remaining), currency: this.cart.subtotal.currency } });
      const covered = Math.min(tender.usable.amount_minor, Math.max(0, remaining));
      if (covered <= 0) throw new PurchaseError("validation", "This gift code is unknown or empty", { status: 422 });
      remaining -= covered;
      tenders.push({ code: tender.code, covered: money(covered) });
    }
    const giftAmount = tenders.reduce((total, tender) => total + tender.covered.amount_minor, 0);
    const upper = codes.map((code) => code.trim().toUpperCase());
    this.quote = { id: "quote-fixture-1", cart_id: input.cart_id, subtotal: this.cart.subtotal, discount: discount.discount, voucher: discount, shipping, tax, total: money(this.cart.subtotal.amount_minor + shipping.amount_minor + tax.amount_minor - discount.discount.amount_minor - giftAmount), expires_at: this.quoteExpiresAt, ...(upper.length > 0 ? { gift_codes: upper, gift_discount: money(giftAmount), gift_tenders: tenders, ...(upper.length === 1 && upper[0] ? { gift_code: upper[0] } : {}) } : {}), ...(pickup ? { pickup } : {}) };
    return this.quote;
  }

  async previewGift(input: { code: string; subtotal: { amount_minor: number; currency: string } }): Promise<GiftTender> {
    const code = input.code.trim().toUpperCase();
    if (!code) throw new PurchaseError("validation", "Enter a gift code", { status: 422, fieldErrors: { gift: ["A gift code is required"] } });
    const balance = code === "GIFT50" ? 50000 : code === "GIFT25" ? 25000 : 0;
    if (balance <= 0) throw new PurchaseError("validation", "This gift code is unknown or empty", { status: 422 });
    const usable = Math.min(balance, input.subtotal.amount_minor);
    return { code, usable: money(usable), balance: money(balance) };
  }

  async previewVoucher(input: { code: string; subtotal: { amount_minor: number; currency: string }; lines: Cart["lines"] }): Promise<Voucher> {
    const code = input.code.trim().toUpperCase();
    if (!code) throw new PurchaseError("validation", "Enter a voucher code", { status: 422, fieldErrors: { voucher: ["A voucher code is required"] } });
    if (code === "EXPIRED") throw new PurchaseError("conflict", "This voucher has expired", { status: 409 });
    if (code === "DUPLICATE") throw new PurchaseError("conflict", "This voucher is already applied", { status: 409 });
    if (code !== "SAVE10") throw new PurchaseError("validation", "This voucher is not eligible for this cart", { status: 422 });
    return { code, eligibility: "eligible", discount: money(Math.floor(input.subtotal.amount_minor * 0.1)), message: "10% voucher applied" };
  }

  async getPickupPoints(): Promise<PickupPoint[]> {
    return [{ code: "HN-01", name: "Hoan Kiem Store", address_line: "1 Trang Tien", city: "Hanoi", sla_hours: 24 }];
  }

  async createCheckout(input: CheckoutInput): Promise<Checkout> {
    if (!/^\S+@\S+\.\S+$/.test(input.email)) {
      throw new PurchaseError("validation", "Email is invalid", { status: 422, fieldErrors: { email: ["Enter a valid email"] } });
    }
    if (!this.quote || this.quote.cart_id !== input.cart_id) throw new PurchaseError("conflict", "Quote is required", { status: 409 });
    const checkoutCodes = input.gift_codes ?? (input.gift_code ? [input.gift_code] : []);
    this.checkout = { id: "checkout-fixture-1", quote_id: this.quote.id, email: input.email, status: "needs_payment", ...(checkoutCodes.length > 0 ? { gift_codes: checkoutCodes } : {}), ...(checkoutCodes.length === 1 && checkoutCodes[0] ? { gift_code: checkoutCodes[0] } : {}), ...(input.fulfillment_method ? { fulfillment_method: input.fulfillment_method } : {}), ...(input.pickup_point ? { pickup_point: input.pickup_point } : {}) };
    return this.checkout;
  }

  async authorizePayment(checkoutId: string, method: PaymentMethod, idempotencyKey: string): Promise<Payment> {
    if (!this.checkout || checkoutId !== this.checkout.id) throw new PurchaseError("conflict", "Checkout is not available", { status: 409 });
    const payload = { checkoutId, method };
    const existing = this.mutations.get(`payment:${idempotencyKey}`);
    if (existing) {
      if (existing.fingerprint !== fingerprint(payload)) throw new PurchaseError("conflict", "Idempotency key was reused with a different payment", { status: 409 });
      return existing.result as Payment;
    }
    this.payment = this.paymentPendingOnce
      ? { id: "payment-fixture-1", status: "pending", method }
      : this.paymentUnknownOnce
      ? { id: "payment-fixture-1", status: "unknown", method }
      : this.paymentFailureOnce
      ? { id: "payment-fixture-1", status: "failed", method, failure_reason: "Fixture payment was declined" }
      : { id: "payment-fixture-1", status: "authorized", method };
    this.paymentFailureOnce = false;
    this.paymentUnknownOnce = false;
    this.paymentPendingOnce = false;
    this.mutations.set(`payment:${idempotencyKey}`, { fingerprint: fingerprint(payload), result: this.payment });
    return this.payment;
  }

  async placeOrder(input: PlaceOrderInput): Promise<Order> {
    if (!this.checkout || input.checkout_id !== this.checkout.id || !this.payment) throw new PurchaseError("conflict", "Payment is required", { status: 409 });
    if (this.payment.status !== "authorized") throw new PurchaseError("conflict", this.payment.failure_reason ?? "Payment was not authorized", { status: 409 });
    const payload = { checkout_id: input.checkout_id, payment_method: input.payment_method };
    const existing = this.mutations.get(input.idempotency_key);
    if (existing) {
      if (existing.fingerprint !== fingerprint(payload)) throw new PurchaseError("conflict", "Idempotency key was reused with a different order", { status: 409 });
      return existing.result as Order;
    }
    this.order = { id: "order-fixture-1", number: "SO-100001", status: "placed", total: this.quote?.total ?? money(0), payment: this.payment, guest_access_token: "guest-order-token-fixture" };
    this.mutations.set(input.idempotency_key, { fingerprint: fingerprint(payload), result: this.order });
    if (this.orderTimeoutOnce) {
      this.orderTimeoutOnce = false;
      throw new PurchaseError("unknown_outcome", "Order result is not yet confirmed");
    }
    return this.order;
  }

  async getPaymentStatus(paymentId: string): Promise<Payment> {
    if (!this.payment || this.payment.id !== paymentId) throw new PurchaseError("not_found", "Payment not found", { status: 404 });
    return this.payment;
  }

  async reconcileOrder(idempotencyKey: string): Promise<Order | null> {
    return (this.mutations.get(idempotencyKey)?.result as Order | undefined) ?? null;
  }

  async login(email: string, password: string): Promise<CustomerSession> {
    if (email === "rate-limit@example.com") throw new PurchaseError("rate_limited", "Too many attempts", { status: 429 });
    if (email !== "buyer@example.com" || password !== "fixture-password") throw new PurchaseError("unauthorized", "Email or password is incorrect", { status: 401 });
    return { access_token: "customer-token-fixture", customer_id: "customer-fixture-1" };
  }

  async logout(accessToken: string): Promise<void> {
    this.requireCustomer(accessToken);
  }

  async register(input: { name: string; email: string; password: string }): Promise<CustomerSession> {
    if (!input.name.trim() || !/^\S+@\S+\.\S+$/.test(input.email) || input.password.length < 8) {
      throw new PurchaseError("validation", "Enter your name, a valid email, and a password with at least 8 characters", { status: 422 });
    }
    if (input.email === "buyer@example.com") throw new PurchaseError("conflict", "An account with this email already exists", { status: 409 });
    return { access_token: "customer-token-fixture", customer_id: "customer-fixture-1" };
  }

  async getAccount(accessToken: string): Promise<CustomerAccount> {
    this.requireCustomer(accessToken);
    return { id: "customer-fixture-1", email: "buyer@example.com", name: "Nguyen Van A", email_verified: true };
  }

  async requestPasswordReset(email: string): Promise<void> {
    if (!/^\S+@\S+\.\S+$/.test(email.trim())) throw new PurchaseError("validation", "Enter a valid email", { status: 422 });
  }

  async resetPassword(token: string, password: string): Promise<void> {
    if (!token) throw new PurchaseError("validation", "Reset link is invalid", { status: 422 });
    if (password.length < 8) throw new PurchaseError("validation", "Password must be at least 8 characters", { status: 422 });
  }

  async verifyEmail(token: string): Promise<void> {
    if (!token) throw new PurchaseError("validation", "Verification link is invalid", { status: 422 });
  }

  async resendVerification(accessToken: string): Promise<void> {
    this.requireCustomer(accessToken);
  }

  async changePassword(accessToken: string, currentPassword: string, newPassword: string): Promise<void> {
    this.requireCustomer(accessToken);
    if (currentPassword !== "fixture-password") throw new PurchaseError("validation", "Current password is incorrect", { status: 422 });
    if (newPassword.length < 8) throw new PurchaseError("validation", "Password must be at least 8 characters", { status: 422 });
  }
  async updateAccount(accessToken: string, input: { name: string; birthdate?: string }): Promise<CustomerAccount> {
    const account = await this.getAccount(accessToken);
    if (!input.name.trim()) throw new PurchaseError("validation", "Name is required", { status: 422 });
    if (input.birthdate !== undefined && !/^\d{4}-\d{2}-\d{2}$/.test(input.birthdate)) throw new PurchaseError("validation", "Birthdate must be YYYY-MM-DD", { status: 422 });
    return { ...account, name: input.name.trim(), ...(input.birthdate ? { birthdate: input.birthdate } : {}) };
  }

  async getOrderHistory(accessToken: string): Promise<Order[]> {
    this.requireCustomer(accessToken);
    return this.order ? [this.order] : [];
  }

  async getOrder(accessToken: string, orderId: string): Promise<Order> {
    this.requireCustomer(accessToken);
    if (!this.order || this.order.id !== orderId) throw new PurchaseError("server_error", "Order not found", { status: 404 });
    return this.order;
  }

  async getGuestOrder(token: string): Promise<Order> {
    if (token !== "guest-order-token-fixture") throw new PurchaseError("server_error", "Guest order link is invalid or expired", { status: 404 });
    return this.order ?? { id: "order-fixture-1", number: "SO-100001", status: "placed", total: money(0), payment: { id: "payment-fixture-1", status: "authorized", method: "card" }, guest_access_token: token };
  }

  async getSubscriptions(accessToken: string): Promise<Subscription[]> {
    this.requireCustomer(accessToken);
    return [{ id: "subscription-fixture-1", status: "active", product_name: "Siro monthly delivery", interval: "monthly", next_charge_at: "2099-02-01", price: money(125000) }];
  }

  async getSubscription(accessToken: string, subscriptionId: string): Promise<Subscription> {
    const subscription = (await this.getSubscriptions(accessToken)).find((item) => item.id === subscriptionId);
    if (!subscription) throw new PurchaseError("not_found", "Subscription not found", { status: 404 });
    return subscription;
  }

  private requireCustomer(accessToken: string): void {
    if (!accessToken) throw new PurchaseError("unauthorized", "Login required", { status: 401 });
    if (accessToken === "forbidden-token") throw new PurchaseError("forbidden", "Account access is not allowed", { status: 403 });
    if (accessToken !== "customer-token-fixture") throw new PurchaseError("unauthorized", "Session is invalid", { status: 401 });
  }
}
