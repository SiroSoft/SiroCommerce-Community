import { PurchaseError, isValidMoney, sameCurrency, type Cart, type Checkout, type CheckoutInput, type CustomerAccount, type CustomerSession, type GiftTender, type Order, type Payment, type PaymentMethod, type PlaceOrderInput, type ProductHandoff, type PurchaseOperations, type Quote, type QuoteInput, type Subscription, type Voucher } from "./contracts";
import type { QuoteRequest, StorefrontCommerceContext } from "./b2b";

export class PurchaseService {
  private readonly placeRequests = new Map<string, Promise<Order>>();
  private readonly paymentRequests = new Map<string, Promise<Payment>>();

  constructor(private readonly operations: PurchaseOperations) {}

  addProduct(product: ProductHandoff): Promise<Cart> {
    if (!product.product_id.trim() || !product.slug.trim() || !product.name.trim()) {
      return Promise.reject(new PurchaseError("validation", "Product details are incomplete", { status: 422, fieldErrors: { product_id: ["A product is required"] } }));
    }
    if (!Number.isInteger(product.quantity) || product.quantity < 1) {
      return Promise.reject(new PurchaseError("validation", "Quantity must be a positive integer", { status: 422, fieldErrors: { quantity: ["Must be at least 1"] } }));
    }
    if (!isValidMoney(product.unit_price) || !Number.isSafeInteger(product.unit_price.amount_minor * product.quantity)) {
      return Promise.reject(new PurchaseError("validation", "Price must be a non-negative minor-unit integer", { status: 422, fieldErrors: { unit_price: ["Use integer minor units"] } }));
    }
    if (!/^[A-Z]{3}$/.test(product.unit_price.currency)) {
      return Promise.reject(new PurchaseError("validation", "Currency must be an ISO 4217 code", { status: 422, fieldErrors: { currency: ["Use a three-letter currency code"] } }));
    }
    return this.operations.addToCart(product);
  }
  getCart(cartId: string): Promise<Cart> { return this.operations.getCart(cartId); }
  async previewVoucher(cart: Cart, code: string): Promise<Voucher> {
    if (!this.operations.previewVoucher) throw new PurchaseError("forbidden", "Voucher application is unavailable for this store", { status: 403 });
    if (!code.trim()) throw new PurchaseError("validation", "Enter a voucher code", { status: 422, fieldErrors: { voucher: ["A voucher code is required"] } });
    const voucher = await this.operations.previewVoucher({ code, subtotal: cart.subtotal, lines: cart.lines });
    if (voucher.eligibility !== "eligible") throw new PurchaseError("conflict", voucher.message ?? "This voucher cannot be applied", { status: 409 });
    if (!isValidMoney(voucher.discount) || !sameCurrency(voucher.discount, cart.subtotal) || voucher.discount.amount_minor > cart.subtotal.amount_minor) throw new PurchaseError("server_error", "The service returned an invalid voucher", { status: 500 });
    if (voucher.expires_at && Date.parse(voucher.expires_at) <= Date.now()) throw new PurchaseError("conflict", "This voucher has expired", { status: 409 });
    return voucher;
  }
  async previewGift(cart: Cart, code: string): Promise<GiftTender> {
    if (!this.operations.previewGift) throw new PurchaseError("forbidden", "Gift tender is unavailable for this store", { status: 403 });
    if (!code.trim()) throw new PurchaseError("validation", "Enter a gift code", { status: 422, fieldErrors: { gift: ["A gift code is required"] } });
    const gift = await this.operations.previewGift({ code, subtotal: cart.subtotal });
    if (!isValidMoney(gift.usable) || !isValidMoney(gift.balance) || !sameCurrency(gift.usable, gift.balance, cart.subtotal) || gift.usable.amount_minor <= 0 || gift.usable.amount_minor > cart.subtotal.amount_minor || gift.usable.amount_minor > gift.balance.amount_minor) throw new PurchaseError("server_error", "The service returned an invalid gift tender", { status: 500 });
    return gift;
  }
  async quote(input: QuoteInput): Promise<Quote> {
    if (!input.cart_id?.trim() || !input.shipping_method?.trim() || !input.shipping_address || Object.values(input.shipping_address).some((value) => typeof value !== "string" || !value.trim())) {
      return Promise.reject(new PurchaseError("validation", "Shipping details are incomplete", { status: 422, fieldErrors: { shipping_address: ["Complete address is required"] } }));
    }
    const quote = await this.operations.createQuote(input);
    const dealAmount = quote.member_deal?.discount.amount_minor ?? 0;
    if (quote.member_deal && (!isValidMoney(quote.member_deal.discount) || dealAmount <= 0 || !Number.isInteger(quote.member_deal.percent_off) || quote.member_deal.percent_off < 1 || quote.member_deal.percent_off > 90)) throw new PurchaseError("server_error", "The service returned an invalid member deal", { status: 500 });
    const expected = quote.subtotal.amount_minor + quote.shipping.amount_minor + quote.tax.amount_minor - (quote.discount?.amount_minor ?? 0) - (quote.gift_discount?.amount_minor ?? 0) - (quote.member_discount?.amount_minor ?? 0) - dealAmount;
    if (!Number.isSafeInteger(expected) || (quote.discount !== undefined && !isValidMoney(quote.discount)) || (quote.gift_discount !== undefined && !isValidMoney(quote.gift_discount)) || (quote.member_discount !== undefined && !isValidMoney(quote.member_discount)) || ![quote.subtotal, quote.shipping, quote.tax, quote.total].every(isValidMoney) || !sameCurrency(quote.subtotal, quote.shipping, quote.tax, quote.total, ...(quote.discount ? [quote.discount] : []), ...(quote.gift_discount ? [quote.gift_discount] : []), ...(quote.member_discount ? [quote.member_discount] : []), ...(quote.member_deal ? [quote.member_deal.discount] : [])) || quote.total.amount_minor !== expected) {
      throw new PurchaseError("server_error", "The service returned inconsistent quote totals", { status: 500 });
    }
    if (quote.expires_at && (Number.isNaN(Date.parse(quote.expires_at)) || Date.parse(quote.expires_at) <= Date.now())) {
      throw new PurchaseError("conflict", "This quote has expired. Request a new quote.", { status: 409 });
    }
    return quote;
  }
  checkout(input: CheckoutInput): Promise<Checkout> {
    if (!input.cart_id?.trim() || !input.shipping_method?.trim() || !input.shipping_address || Object.values(input.shipping_address).some((value) => typeof value !== "string" || !value.trim())) {
      return Promise.reject(new PurchaseError("validation", "Shipping details are incomplete", { status: 422, fieldErrors: { shipping_address: ["Complete address is required"] } }));
    }
    if (typeof input.email !== "string" || !/^\S+@\S+\.\S+$/.test(input.email.trim())) {
      return Promise.reject(new PurchaseError("validation", "Email is invalid", { status: 422, fieldErrors: { email: ["Enter a valid email"] } }));
    }
    return this.operations.createCheckout(input);
  }
  async pay(checkoutId: string, method: PaymentMethod, key: string): Promise<Payment> {
    if (!checkoutId?.trim() || !key?.trim() || !["card", "bank_transfer", "cash_on_delivery"].includes(method)) throw new PurchaseError("validation", "Payment details are incomplete", { status: 422 });
    const existing = this.paymentRequests.get(key);
    if (existing) return existing;
    const request = this.operations.authorizePayment(checkoutId, method, key);
    this.paymentRequests.set(key, request);
    void request.then(() => this.paymentRequests.delete(key), () => this.paymentRequests.delete(key));
    return request;
  }

  place(input: PlaceOrderInput): Promise<Order> {
    if (!input.idempotency_key?.trim() || !input.checkout_id?.trim() || !["card", "bank_transfer", "cash_on_delivery"].includes(input.payment_method)) return Promise.reject(new PurchaseError("validation", "Order details are incomplete", { status: 422 }));
    const existing = this.placeRequests.get(input.idempotency_key);
    if (existing) return existing;
    const request = this.placeOnce(input);
    this.placeRequests.set(input.idempotency_key, request);
    void request.then(
      () => this.placeRequests.delete(input.idempotency_key),
      () => this.placeRequests.delete(input.idempotency_key),
    );
    return request;
  }

  private async placeOnce(input: PlaceOrderInput): Promise<Order> {
    try {
      return await this.operations.placeOrder(input);
    } catch (error) {
      if (!(error instanceof PurchaseError) || error.code !== "unknown_outcome") throw error;
      // Reconciliation is best effort. A missing credential or a failed lookup
      // must not replace the original ambiguous outcome with a different one.
      try {
        const reconciled = await this.operations.reconcileOrder(input.idempotency_key);
        if (reconciled) return reconciled;
      } catch {
        // Keep the unknown_outcome so the UI can offer a safe status check.
      }
      throw error;
    }
  }

  async reconcile(input: PlaceOrderInput, options: { attempts?: number; delayMs?: number } = {}): Promise<Order | null> {
    const attempts = Math.max(1, options.attempts ?? 3);
    for (let attempt = 0; attempt < attempts; attempt += 1) {
      const order = await this.operations.reconcileOrder(input.idempotency_key);
      if (order) return order;
      if (attempt + 1 < attempts && (options.delayMs ?? 0) > 0) await new Promise((resolve) => setTimeout(resolve, options.delayMs));
    }
    return null;
  }

  login(email: string, password: string, turnstileToken?: string): Promise<CustomerSession> { return this.operations.login(email, password, turnstileToken); }
  logout(accessToken: string): Promise<void> {
    if (!this.operations.logout) return Promise.resolve();
    return this.operations.logout(accessToken);
  }
  refreshSession(refreshToken: string): Promise<CustomerSession> {
    if (!this.operations.refreshSession) return Promise.reject(new PurchaseError("forbidden", "Session restore is unavailable for this store", { status: 403 }));
    return this.operations.refreshSession(refreshToken);
  }
  async register(input: { name: string; email: string; password: string; turnstileToken?: string }): Promise<CustomerSession> {
    if (!this.operations.register) throw new PurchaseError("forbidden", "Account registration is unavailable for this store", { status: 403 });
    if (!input.name.trim() || !/^\S+@\S+\.\S+$/.test(input.email.trim()) || input.password.length < 8) {
      return Promise.reject(new PurchaseError("validation", "Enter your name, a valid email, and a password with at least 8 characters", { status: 422, fieldErrors: { email: ["Check the highlighted fields"] } }));
    }
    return this.operations.register({ name: input.name.trim(), email: input.email.trim(), password: input.password, ...(input.turnstileToken ? { turnstileToken: input.turnstileToken } : {}) });
  }
  account(accessToken: string): Promise<CustomerAccount> { return this.operations.getAccount(accessToken); }
  updateAccount(accessToken: string, input: { name: string; birthdate?: string }): Promise<CustomerAccount> {
    if (input.birthdate !== undefined && !/^\d{4}-\d{2}-\d{2}$/.test(input.birthdate)) return Promise.reject(new PurchaseError("validation", "Birthdate must be YYYY-MM-DD", { status: 422, fieldErrors: { birthdate: ["Use YYYY-MM-DD"] } }));
    return this.operations.updateAccount(accessToken, input);
  }
  requestPasswordReset(email: string, turnstileToken?: string): Promise<void> { return this.operations.requestPasswordReset(email.trim(), turnstileToken); }
  resetPassword(token: string, password: string): Promise<void> {
    if (!token) return Promise.reject(new PurchaseError("validation", "Reset link is invalid", { status: 422 }));
    if (password.length < 8) return Promise.reject(new PurchaseError("validation", "Password must be at least 8 characters", { status: 422 }));
    return this.operations.resetPassword(token, password);
  }
  verifyEmail(token: string): Promise<void> {
    if (!token) return Promise.reject(new PurchaseError("validation", "Verification link is invalid", { status: 422 }));
    return this.operations.verifyEmail(token);
  }
  resendVerification(accessToken: string): Promise<void> { return this.operations.resendVerification(accessToken); }
  changePassword(accessToken: string, currentPassword: string, newPassword: string): Promise<void> {
    if (!currentPassword || newPassword.length < 8) return Promise.reject(new PurchaseError("validation", "Enter the current password and a new password with at least 8 characters", { status: 422 }));
    return this.operations.changePassword(accessToken, currentPassword, newPassword);
  }
  orderHistory(accessToken: string): Promise<Order[]> { return this.operations.getOrderHistory(accessToken); }
  order(accessToken: string, orderId: string): Promise<Order> { return this.operations.getOrder(accessToken, orderId); }
  guestOrder(orderNumber: string, email?: string): Promise<Order> { return this.operations.getGuestOrder(orderNumber, email); }
  subscriptions(accessToken: string): Promise<Subscription[]> {
    if (!this.operations.getSubscriptions) return Promise.reject(new PurchaseError("forbidden", "Subscriptions are unavailable for this store", { status: 403 }));
    return this.operations.getSubscriptions(accessToken);
  }
  subscription(accessToken: string, subscriptionId: string): Promise<Subscription> {
    if (!this.operations.getSubscription) return Promise.reject(new PurchaseError("forbidden", "Subscription details are unavailable for this store", { status: 403 }));
    return this.operations.getSubscription(accessToken, subscriptionId);
  }
  commerceContext(): Promise<StorefrontCommerceContext> {
    if (!this.operations.getCommerceContext) return Promise.reject(new PurchaseError("forbidden", "Organization context is unavailable for this store", { status: 403 }));
    return this.operations.getCommerceContext();
  }
  requestQuote(cartId: string, note?: string): Promise<QuoteRequest> {
    if (!this.operations.requestQuote) return Promise.reject(new PurchaseError("forbidden", "Quote requests are unavailable for this store", { status: 403 }));
    if (!cartId.trim()) return Promise.reject(new PurchaseError("validation", "A cart is required for a quote request", { status: 422 }));
    return this.operations.requestQuote({ cart_id: cartId, note });
  }
  quoteRequest(id: string): Promise<QuoteRequest> {
    if (!this.operations.getQuoteRequest) return Promise.reject(new PurchaseError("forbidden", "Quote status is unavailable for this store", { status: 403 }));
    return this.operations.getQuoteRequest(id);
  }
  approveQuote(id: string): Promise<QuoteRequest> {
    if (!this.operations.approveQuote) return Promise.reject(new PurchaseError("forbidden", "Quote approval is unavailable for this store", { status: 403 }));
    return this.operations.approveQuote(id);
  }
}
