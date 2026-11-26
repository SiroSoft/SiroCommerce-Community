import { ApiClientError, RequestCoordinator, type ApiEnvelope } from "@sirocommerce/api-client";
import type { CommerceScope } from "@sirocommerce/scope";
import {
  PurchaseError,
  type Cart,
  type CartLine,
  type Checkout,
  type CheckoutInput,
  type CustomerAccount,
  type CustomerSession,
  type GiftTender,
  type Money,
  type Order,
  type OrderDelivery,
  type OrderNotification,
  type Payment,
  type PaymentMethod,
  type PickupPoint,
  type PlaceOrderInput,
  type ProductHandoff,
  type PurchaseOperations,
  type Quote,
  type QuoteInput,
  type Voucher,
  isValidMoney,
  sameCurrency,
} from "./contracts";
import { validateCommerceContext, type QuoteRequest, type StorefrontCommerceContext } from "./b2b";

type ApiRecord = Record<string, unknown>;
type ApiAdapterOptions = ConstructorParameters<typeof RequestCoordinator>[0] & { scope?: CommerceScope; cartId?: string; cartToken?: string; orderId?: string; orderToken?: string; getOrderToken?: () => string | undefined };

const record = (value: unknown): ApiRecord => (value && typeof value === "object" ? value as ApiRecord : {});
const stringValue = (value: unknown, fallback = "") => typeof value === "string" ? value : fallback;
const numberValue = (value: unknown, fallback = 0) => typeof value === "number" ? value : typeof value === "string" && value.trim() ? Number(value) : fallback;
const money = (value: unknown, currency: string): Money => {
  const item = record(value);
  const amount = item.amount_minor ?? item.minor ?? value;
  if (typeof amount !== "number" || !Number.isSafeInteger(amount) || amount < 0) throw new PurchaseError("server_error", "The service returned invalid money", { status: 500 });
  const resolvedCurrency = stringValue(item.currency, currency);
  if (!/^[A-Z]{3}$/.test(resolvedCurrency)) throw new PurchaseError("server_error", "The service returned invalid money");
  return { amount_minor: amount, currency: resolvedCurrency };
};
const dataOf = <T>(response: { body: ApiEnvelope<T> }): T => response.body.data;
const normalizeVoucher = (value: unknown, currency: string, code: string): Voucher => {
  const item = record(value);
  const eligibility = stringValue(item.eligibility ?? item.status, "unknown");
  const allowed = ["eligible", "ineligible", "expired", "duplicate", "unknown"] as const;
  return { code, eligibility: allowed.includes(eligibility as typeof allowed[number]) ? eligibility as Voucher["eligibility"] : "unknown", discount: money(item.discount ?? item.discount_minor ?? 0, currency), message: stringValue(item.message) || undefined, expires_at: stringValue(item.expires_at) || undefined };
};
const normalizeCommerceContext = (value: unknown): StorefrontCommerceContext => {
  const item = record(value);
  const organization = record(item.organization);
  const customer = record(item.customer);
  const store = record(item.store);
  const unavailable = (value: unknown, fallback: string) => ({ available: false as const, reason: stringValue(value) || fallback });
  const pricing = record(item.pricing);
  const tiers = Array.isArray(pricing.tiers) ? pricing.tiers.map((tier) => { const source = record(tier); return { minimum: numberValue(source.minimum), unitPrice: money(source.unitPrice ?? source.unit_price, stringValue(store.currency, "USD")), label: stringValue(source.label) || undefined }; }) : undefined;
  return validateCommerceContext({
    organization: { id: stringValue(organization.id), name: stringValue(organization.name), role: ["member", "buyer", "approver", "admin"].includes(stringValue(organization.role)) ? organization.role as "member" | "buyer" | "approver" | "admin" : undefined },
    customer: { id: stringValue(customer.id), name: stringValue(customer.name) || undefined, email: stringValue(customer.email) || undefined },
    store: { id: stringValue(store.id), name: stringValue(store.name), currency: stringValue(store.currency, "USD"), locale: stringValue(store.locale) || undefined },
    vendor: record(item.vendor).id ? { id: stringValue(record(item.vendor).id), name: stringValue(record(item.vendor).name) } : undefined,
    pricing: { customer: record(pricing.customer).price || pricing.customer_price ? { price: money(record(pricing.customer).price ?? pricing.customer_price, stringValue(store.currency, "USD")), source: "customer", label: stringValue(record(pricing.customer).label) || undefined } : undefined, tiers },
    orderPolicy: record(item.order_policy).minimum_order || record(item.order_policy).minimum_order_minor ? { minimumOrder: money(record(item.order_policy).minimum_order ?? record(item.order_policy).minimum_order_minor, stringValue(store.currency, "USD")), minimumQuantity: numberValue(record(item.order_policy).minimum_quantity) || undefined, message: stringValue(record(item.order_policy).message) || undefined } : undefined,
    credit: unavailable(record(item.credit).reason, "Provider credit terms are unavailable."), payout: unavailable(record(item.payout).reason, "Vendor payout details are unavailable."), payment: unavailable(record(item.payment).reason, "Payment provider is unavailable."),
    marketplace: record(item.marketplace).ownerId ? { ownerType: ["store", "vendor", "marketplace"].includes(stringValue(record(item.marketplace).ownerType)) ? record(item.marketplace).ownerType as "store" | "vendor" | "marketplace" : "marketplace", ownerId: stringValue(record(item.marketplace).ownerId), ownerName: stringValue(record(item.marketplace).ownerName), commissionMessage: stringValue(record(item.marketplace).commissionMessage, "Marketplace commission is handled by the platform and is not added to your order total.") } : undefined,
  });
};
const normalizeQuoteRequest = (value: unknown): QuoteRequest => { const item = record(value); const statuses = ["draft", "requested", "submitted", "under_review", "approved", "accepted", "converted", "rejected", "expired"] as const; const status = stringValue(item.status ?? item.state); return { id: stringValue(item.id), status: statuses.includes(status as typeof statuses[number]) ? status as QuoteRequest["status"] : "draft", requestedAt: stringValue(item.requested_at ?? item.requestedAt ?? item.created_at), expiresAt: stringValue(item.expires_at ?? item.expiresAt) || undefined, approverMessage: stringValue(item.approver_message ?? item.approverMessage ?? item.reason) || undefined }; };

function errorFrom(error: unknown, mutation = false): PurchaseError {
  if (error instanceof PurchaseError) return error;
  if (error instanceof ApiClientError) {
    const status = Number.isInteger(error.status) && error.status >= 400 && error.status <= 599 ? error.status : undefined;
    const code = error.status === 401 ? "unauthorized" : error.status === 403 ? "forbidden" : error.status === 404 ? "not_found" : error.status === 409 ? "conflict" : error.status === 422 ? "validation" : error.status === 429 ? "rate_limited" : error.status >= 500 ? "server_error" : mutation ? "unknown_outcome" : "server_error";
    return new PurchaseError(code, error.message, { status, fieldErrors: error.errors });
  }
  return new PurchaseError(mutation ? "unknown_outcome" : "server_error", mutation ? `The request result is unknown (${error instanceof Error ? error.message : String(error)})` : "The service is unavailable");
}

const normalizeCart = (value: unknown, currency: string, fallbackId?: string): Cart => {
  const item = record(value);
  const lines = Array.isArray(item.lines) ? item.lines.map((line) => {
    const source = record(line);
     const unit = money(source.unit_price ?? source.unit_price_minor ?? source.price, currency);
    const quantity = numberValue(source.quantity ?? source.qty, Number.NaN);
    if (!Number.isSafeInteger(quantity) || quantity < 1) throw new PurchaseError("server_error", "The service returned invalid cart quantity");
    return {
      product_id: stringValue(source.product_id ?? source.variant_id), slug: stringValue(source.slug), name: stringValue(source.name),
       unit_price: unit, quantity, line_total: money(source.line_total ?? source.total ?? source.amount_minor ?? unit.amount_minor * quantity, unit.currency),
      ...(typeof source.id === "string" && source.id ? { line_id: source.id } : {}),
      ...(typeof source.image === "string" && source.image ? { image: source.image } : {}),
      ...(typeof source.slot_start === "string" && source.slot_start ? { slot_start: source.slot_start } : {}),
      ...(typeof source.flash_sale_id === "string" && source.flash_sale_id ? { flash_sale_id: source.flash_sale_id } : {}),
    };
  }).reduce<Cart["lines"]>((result, line) => {
    // Lines are unique by line_id; fall back to product+slot so two different
    // booking slots (or flash vs regular) never collapse into one row.
    const key = (item: CartLine) => item.line_id ?? `${item.product_id}::${item.slot_start ?? ""}::${item.flash_sale_id ?? ""}`;
    const index = result.findIndex((item) => key(item) === key(line));
    if (index < 0) result.push(line);
    else result[index] = line;
    return result;
  }, []) : [];
  const subtotalMinor = numberValue(item.subtotal_minor ?? item.subtotal);
  const subtotal = money(item.subtotal ?? subtotalMinor, currency);
   const lineSubtotal = lines.reduce((sum, line) => sum + line.line_total.amount_minor, 0);
   if (!Number.isSafeInteger(lineSubtotal) || !lines.every((line) => isValidMoney(line.unit_price) && isValidMoney(line.line_total) && line.unit_price.currency === line.line_total.currency) || !isValidMoney(subtotal) || lines.some((line) => !Number.isSafeInteger(line.unit_price.amount_minor * line.quantity) || line.line_total.amount_minor !== line.unit_price.amount_minor * line.quantity) || subtotal.amount_minor !== lineSubtotal) {
    throw new PurchaseError("server_error", "The service returned inconsistent cart totals", { status: 500 });
  }
  return { id: stringValue(item.id, fallbackId), token: stringValue(item.cart_token ?? item.token) || undefined, lines, subtotal };
};

const normalizeOrder = (value: unknown, currency: string, paymentMethod: PaymentMethod, fallbackPayment?: Payment): Order => {
  const item = record(value);
  const payment = record(item.payment);
  const total = money(item.total ?? item.grand_total_minor ?? item.total_minor, stringValue(item.currency, currency));
  if (!isValidMoney(total)) throw new PurchaseError("server_error", "The service returned invalid order totals", { status: 500 });
  const delivery = normalizeOrderDelivery(item.delivery ?? item.fulfillment);
  const notifications = normalizeOrderNotifications(item.notifications ?? item.notification_events);
  const vendor = normalizeNamedContext(item.vendor);
  const site = normalizeNamedContext(item.site);
  return {
    id: stringValue(item.id), number: stringValue(item.number ?? item.order_number),
    status: ["placed", "pending_reconciliation", "processing", "ready_to_ship", "shipped", "delivered", "cancelled", "refunded", "partially_refunded", "failed"].includes(stringValue(item.state ?? item.status)) ? stringValue(item.state ?? item.status) as Order["status"] : "placed",
    total,
    payment: Object.keys(payment).length > 0 ? normalizePayment(payment, paymentMethod) : fallbackPayment ?? normalizePayment(payment, paymentMethod),
    guest_access_token: stringValue(item.guest_access_token ?? item.guest_token) || undefined,
    pickup_point_code: stringValue(item.pickup_point_code) || undefined,
    ...(delivery ? { delivery } : {}), ...(notifications ? { notifications } : {}), ...(vendor ? { vendor } : {}), ...(site ? { site } : {}),
  };
};

function normalizeNamedContext(value: unknown): { id: string; name: string } | undefined {
  const item = record(value);
  return typeof item.id === "string" && typeof item.name === "string" ? { id: item.id, name: item.name } : undefined;
}

function normalizeOrderDelivery(value: unknown): OrderDelivery | undefined {
  const item = record(value);
  const modes = ["physical", "virtual", "downloadable", "scheduled", "unknown"] as const;
  const states = ["pending", "ready", "shipped", "delivered", "unavailable", "unknown"] as const;
  const mode = modes.includes(stringValue(item.mode) as typeof modes[number]) ? stringValue(item.mode) as OrderDelivery["mode"] : "unknown";
  const state = states.includes(stringValue(item.state ?? item.status) as typeof states[number]) ? stringValue(item.state ?? item.status) as OrderDelivery["state"] : "unknown";
  return Object.keys(item).length ? { mode, state, ...(typeof item.warehouse === "string" ? { warehouse: item.warehouse } : {}), ...(typeof item.scheduled_for === "string" ? { scheduled_for: item.scheduled_for } : {}) } : undefined;
}

function normalizeOrderNotifications(value: unknown): OrderNotification[] | undefined {
  if (!Array.isArray(value)) return undefined;
  const states = ["queued", "sending", "sent", "failed", "skipped", "unavailable", "unknown"] as const;
  const parsed = value.map((entry) => { const item = record(entry); const stateValue = stringValue(item.state); const eventValue = stringValue(item.event, "unknown"); return states.includes(stateValue as typeof states[number]) ? { state: stateValue as OrderNotification["state"], event: eventValue, ...(typeof item.attempts === "number" ? { attempts: item.attempts } : {}), ...(typeof item.last_error === "string" ? { last_error: item.last_error } : {}) } : null; });
  return parsed.every(Boolean) ? parsed as OrderNotification[] : undefined;
}

function normalizePayment(value: unknown, method: PaymentMethod): Payment {
  const item = record(value);
  const state = stringValue(item.status ?? item.state).toLowerCase();
  const status: Payment["status"] =
    ["pending", "processing", "initiated", "requires_action", "requires_payment_method"].includes(state) ? "pending"
      : ["authorized", "approved", "requires_capture"].includes(state) ? "authorized"
        : ["captured", "paid", "succeeded", "completed", "settled"].includes(state) ? "captured"
          : ["failed", "declined", "rejected", "voided", "cancelled", "canceled"].includes(state) ? "failed"
            : "unknown";
  return {
    id: stringValue(item.id),
    status,
    method: item.method === "bank_transfer" || item.method === "cash_on_delivery" ? item.method : method,
    failure_reason: stringValue(item.failure_reason ?? item.failureReason) || undefined,
  };
}

export class ApiPurchaseAdapter implements PurchaseOperations {
  private readonly client: RequestCoordinator;
  private cart?: Cart;
  private cartId?: string;
  private cartToken?: string;
  private checkout?: CheckoutInput & { id: string; quote_id: string };
  private quote?: Quote;
  private payment?: Payment;
  private readonly payments = new Map<string, { checkoutId: string; method: PaymentMethod; result: Payment }>();
  private readonly scope?: CommerceScope;
  private readonly getAccessToken?: () => string | undefined;
  private readonly getOrderToken?: () => string | undefined;
  private orderId?: string;
  private orderToken?: string;

  async getCommerceContext(): Promise<StorefrontCommerceContext> { try { const response = await this.client.request<ApiRecord>("/api/storefront/context", { scope: this.scope }); return normalizeCommerceContext(dataOf(response)); } catch (error) { throw errorFrom(error); } }
  async requestQuote(input: { cart_id: string; note?: string }): Promise<QuoteRequest> { try { const company = this.scope?.store_id ?? "storefront"; const response = await this.client.request<ApiRecord>(`/api/v2/b2b/companies/${encodeURIComponent(company)}/quotes`, { method: "POST", body: input, idempotencyKey: `storefront-quote-${input.cart_id}`, scope: this.scope }); return normalizeQuoteRequest(dataOf(response)); } catch (error) { throw errorFrom(error, true); } }
  async getQuoteRequest(id: string): Promise<QuoteRequest> { try { const response = await this.client.request<ApiRecord>(`/api/v2/b2b/quotes/${encodeURIComponent(id)}`, { scope: this.scope }); return normalizeQuoteRequest(dataOf(response)); } catch (error) { throw errorFrom(error); } }
  async approveQuote(id: string): Promise<QuoteRequest> { try { const response = await this.client.request<ApiRecord>(`/api/v2/b2b/quotes/${encodeURIComponent(id)}/approve`, { method: "POST", body: {}, idempotencyKey: `storefront-quote-approve-${id}`, scope: this.scope }); return normalizeQuoteRequest(dataOf(response)); } catch (error) { throw errorFrom(error, true); } }

  constructor(options: ApiAdapterOptions) { this.client = new RequestCoordinator(options); this.scope = options.scope; this.cartId = options.cartId; this.cartToken = options.cartToken; this.orderId = options.orderId; this.orderToken = options.orderToken; this.getAccessToken = options.getAccessToken; this.getOrderToken = options.getOrderToken; }

  /** Adopt a server-rendered bag so later line edits reuse its identity and token. */
  hydrate(cart: Cart): void {
    this.cartId = cart.id;
    if (cart.token) this.cartToken = cart.token;
    this.cart = cart;
  }

  async restoreCart(): Promise<void> {
    if (this.cart || !this.cartId || !this.cartToken) return;
    const response = await this.client.request<ApiRecord>(`/api/carts/${encodeURIComponent(this.cartId)}`, { cartToken: this.cartToken, scope: this.scope });
    const data = record(dataOf(response));
    const currency = stringValue(data.currency, this.scope?.currency ?? "USD");
    this.cart = { ...normalizeCart(data, currency, this.cartId), token: this.cartToken };
  }

  async addToCart(input: ProductHandoff): Promise<Cart> {
    try {
      await this.restoreCart();
      if (this.cart && this.cart.lines.length > 0 && this.cart.subtotal.currency !== input.unit_price.currency) {
        throw new PurchaseError("conflict", "Currency cannot be changed while the cart has items", { status: 409 });
      }
      if (!this.cart) {
        const created = await this.client.request<ApiRecord>("/api/carts", { method: "POST", body: { currency: input.unit_price.currency }, scope: this.scope });
        const createdData = record(dataOf(created));
        this.cartToken = stringValue(createdData.access_token ?? createdData.cart_token ?? createdData.token) || undefined;
        this.cart = { ...normalizeCart(createdData, input.unit_price.currency, stringValue(createdData.id)), token: this.cartToken };
      }
      const line = await this.client.request<ApiRecord>(`/api/carts/${encodeURIComponent(this.cart.id)}/lines`, { method: "POST", body: { variant_id: input.product_id, qty: input.quantity, ...(input.slot_start ? { slot_start: input.slot_start } : {}) }, cartToken: this.cartToken, scope: this.scope });
      const lineData = record(dataOf(line));
      const cartLine = { ...input, ...(typeof lineData.id === "string" && lineData.id ? { line_id: lineData.id } : {}), line_total: money(lineData.line_total ?? input.unit_price.amount_minor * input.quantity, input.unit_price.currency) };
       const lineKey = (item: { line_id?: string; product_id: string; slot_start?: string }) => item.line_id ?? `${item.product_id}::${item.slot_start ?? ""}`;
       const existing = this.cart.lines.findIndex((item) => lineKey(item) === lineKey(cartLine));
       this.cart.lines = existing < 0 ? [...this.cart.lines, cartLine] : this.cart.lines.map((item, index) => index === existing ? cartLine : item);
      this.cart.subtotal = money(lineData.subtotal ?? lineData.subtotal_minor ?? cartLine.line_total.amount_minor, input.unit_price.currency);
      return this.cart;
    } catch (error) { throw errorFrom(error, true); }
  }

  async getCart(cartId: string): Promise<Cart> {
    if (!this.cart || this.cart.id !== cartId) throw new PurchaseError("conflict", "Cart is not available", { status: 409 });
    return this.cart;
  }

  async setLineQuantity(cartId: string, lineId: string, quantity: number): Promise<Cart> {
    try {
      await this.restoreCart();
      const line = this.cart?.lines.find((item) => item.line_id === lineId);
      if (!this.cart || this.cart.id !== cartId || !line) throw new PurchaseError("conflict", "Cart line is not available", { status: 409 });
      const response = await this.client.request<ApiRecord>(`/api/carts/${encodeURIComponent(cartId)}/lines/${encodeURIComponent(lineId)}`, { method: "PATCH", body: { qty: quantity }, cartToken: this.cartToken, scope: this.scope });
      this.cart = { ...normalizeCart(record(dataOf(response)), this.cart.subtotal.currency, cartId), token: this.cartToken };
      return this.cart;
    } catch (error) { throw errorFrom(error, true); }
  }

  async removeLine(cartId: string, lineId: string): Promise<Cart> {
    try {
      await this.restoreCart();
      if (!this.cart || this.cart.id !== cartId) throw new PurchaseError("conflict", "Cart is not available", { status: 409 });
      const response = await this.client.request<ApiRecord>(`/api/carts/${encodeURIComponent(cartId)}/lines/${encodeURIComponent(lineId)}`, { method: "DELETE", cartToken: this.cartToken, scope: this.scope });
      this.cart = { ...normalizeCart(record(dataOf(response)), this.cart.subtotal.currency, cartId), token: this.cartToken };
      return this.cart;
    } catch (error) { throw errorFrom(error, true); }
  }

  async createQuote(input: QuoteInput): Promise<Quote> {
    try {
      const params = new URLSearchParams();
      if (input.coupon_code) params.set("coupon", input.coupon_code);
      const giftCodes = input.gift_codes ?? (input.gift_code ? [input.gift_code] : []);
      for (const code of giftCodes) params.append("gift[]", code);
      if (input.fulfillment_method) params.set("fulfillment_method", input.fulfillment_method);
      if (input.pickup_point) params.set("pickup_point", input.pickup_point);
      const country = input.shipping_address.country.trim().toUpperCase();
      if (/^[A-Z]{2}$/.test(country)) {
        params.set("destination_country", country);
        const postal = input.shipping_address.postal_code.trim();
        if (postal) params.set("destination_postal_code", postal.slice(0, 16));
      }
      const suffix = params.size > 0 ? `?${params.toString()}` : "";
      const response = await this.client.request<ApiRecord>(`/api/carts/${encodeURIComponent(input.cart_id)}/quote${suffix}`, { method: "GET", cartToken: this.cartToken, scope: this.scope });
      const value = record(dataOf(response));
      const currency = this.cart?.subtotal.currency ?? "USD";
       const codes = input.gift_codes ?? (input.gift_code ? [input.gift_code] : []);
       const rawTenders = Array.isArray(value.gift_tenders) ? value.gift_tenders : [];
       const tenders = rawTenders.map((item) => { const row = record(item); return { code: stringValue(row.code), covered: money(row.covered ?? row.covered_minor ?? 0, currency) }; }).filter((tender) => tender.code !== "");
       const rawPickup = record(value.pickup ?? {});
       const pickup = stringValue(rawPickup.code) ? { code: stringValue(rawPickup.code), name: stringValue(rawPickup.name), ...(stringValue(rawPickup.address_line) ? { address_line: stringValue(rawPickup.address_line) } : {}), ...(stringValue(rawPickup.city) ? { city: stringValue(rawPickup.city) } : {}), sla_hours: typeof rawPickup.sla_hours === "number" ? rawPickup.sla_hours : 24, ready_by: stringValue(rawPickup.ready_by) } : undefined;
       const rawDeal = record(value.member_deal ?? {});
       const memberDeal = stringValue(rawDeal.id) && (typeof rawDeal.discount_minor === "number" || typeof rawDeal.discount === "number") ? { id: stringValue(rawDeal.id), name: stringValue(rawDeal.name), percent_off: typeof rawDeal.percent_off === "number" ? rawDeal.percent_off : 0, discount: money(rawDeal.discount ?? rawDeal.discount_minor ?? 0, currency) } : undefined;
       const memberDiscount = money(value.member_discount ?? value.member_discount_minor ?? 0, currency);
       this.quote = { id: stringValue(value.id, `quote:${input.cart_id}`), cart_id: input.cart_id, subtotal: money(value.subtotal ?? value.subtotal_minor, currency), discount: money(value.discount ?? value.discount_minor ?? 0, currency), member_discount: memberDiscount, ...(memberDeal ? { member_deal: memberDeal } : {}), shipping: money(value.shipping ?? value.shipping_minor ?? 0, currency), tax: money(value.tax ?? value.tax_minor, currency), total: money(value.total ?? value.total_minor, currency), expires_at: stringValue(value.expires_at), ...(input.coupon_code ? { voucher: normalizeVoucher(value.voucher ?? { status: "eligible", discount: value.discount ?? value.discount_minor ?? 0 }, currency, input.coupon_code) } : {}), ...(codes.length > 0 ? { gift_codes: codes.map((code) => code.trim().toUpperCase()), gift_discount: money(value.gift_discount ?? value.gift_discount_minor ?? 0, currency), ...(tenders.length > 0 ? { gift_tenders: tenders } : {}), ...(codes.length === 1 && codes[0] ? { gift_code: codes[0].trim().toUpperCase() } : {}) } : {}), ...(pickup ? { pickup } : {}) };
      return this.quote;
    } catch (error) { throw errorFrom(error); }
  }

  async previewVoucher(input: { code: string; subtotal: Money; lines: Cart["lines"] }): Promise<Voucher> {
    try {
      const response = await this.client.request<ApiRecord>("/api/promotions/preview", { method: "POST", body: { code: input.code.trim(), subtotal_minor: input.subtotal.amount_minor, currency: input.subtotal.currency, lines: input.lines.map((line) => ({ product_id: line.product_id, quantity: line.quantity, amount_minor: line.line_total.amount_minor })) }, scope: this.scope });
      return normalizeVoucher(dataOf(response), input.subtotal.currency, input.code.trim().toUpperCase());
    } catch (error) { throw errorFrom(error, true); }
  }

  async getPickupPoints(): Promise<PickupPoint[]> {
    try {
      const response = await this.client.request<unknown[]>("/api/pickup-points", { scope: this.scope });
      if (!Array.isArray(response.body.data)) throw new PurchaseError("server_error", "The service returned invalid pickup points");
      return response.body.data.map((item) => { const row = record(item); return { code: stringValue(row.code), name: stringValue(row.name), ...(stringValue(row.address_line) ? { address_line: stringValue(row.address_line) } : {}), ...(stringValue(row.city) ? { city: stringValue(row.city) } : {}), sla_hours: typeof row.sla_hours === "number" ? row.sla_hours : 24 }; }).filter((point) => point.code !== "" && point.name !== "");
    } catch (error) { throw errorFrom(error); }
  }

  async previewGift(input: { code: string; subtotal: Money }): Promise<GiftTender> {
    try {
      const response = await this.client.request<ApiRecord>("/api/gift/preview", { method: "POST", body: { code: input.code.trim(), subtotal_minor: input.subtotal.amount_minor, currency: input.subtotal.currency }, scope: this.scope });
      const value = record(dataOf(response));
      const currency = input.subtotal.currency;
      return { code: stringValue(value.code, input.code.trim().toUpperCase()), usable: money(value.usable ?? value.usable_minor ?? 0, currency), balance: money(value.balance ?? value.balance_minor ?? 0, currency) };
    } catch (error) { throw errorFrom(error, true); }
  }

  async createCheckout(input: CheckoutInput): Promise<Checkout> {
    if (!/^\S+@\S+\.\S+$/.test(input.email)) throw new PurchaseError("validation", "Email is invalid", { status: 422, fieldErrors: { email: ["Enter a valid email"] } });
    if (!this.quote || this.quote.cart_id !== input.cart_id) throw new PurchaseError("conflict", "Quote is required", { status: 409 });
    const id = `checkout:${input.cart_id}`;
    this.checkout = { ...input, id, quote_id: this.quote.id };
    const checkoutCodes = input.gift_codes ?? (input.gift_code ? [input.gift_code] : []);
    return { id, quote_id: this.quote.id, email: input.email, status: "needs_payment", ...(checkoutCodes.length > 0 ? { gift_codes: checkoutCodes } : {}), ...(checkoutCodes.length === 1 && checkoutCodes[0] ? { gift_code: checkoutCodes[0] } : {}), ...(input.fulfillment_method ? { fulfillment_method: input.fulfillment_method } : {}), ...(input.pickup_point ? { pickup_point: input.pickup_point } : {}) };
  }

  async authorizePayment(checkoutId: string, method: PaymentMethod, idempotencyKey: string): Promise<Payment> {
    if (!this.checkout || this.checkout.id !== checkoutId) throw new PurchaseError("conflict", "Checkout is not available", { status: 409 });
    const existing = this.payments.get(idempotencyKey);
    if (existing) {
      if (existing.checkoutId !== checkoutId || existing.method !== method) throw new PurchaseError("conflict", "Payment idempotency key was reused with different details", { status: 409 });
      return existing.result;
    }
    // The commerce backend owns authorization and performs it inside
    // POST /api/checkout/place. Before that request returns there is no real
    // provider outcome, so the only truthful local state is "pending"; the
    // placed order carries the authoritative payment status.
    this.payment = { id: checkoutId, status: "pending", method };
    this.payments.set(idempotencyKey, { checkoutId, method, result: this.payment });
    return this.payment;
  }

  async getPaymentStatus(paymentId: string): Promise<Payment> {
    try {
      const response = await this.client.request<ApiRecord>(`/api/v2/payments/${encodeURIComponent(paymentId)}/status`, { scope: this.scope });
      return normalizePayment(dataOf(response), this.payment?.method ?? "card");
    } catch (error) { throw errorFrom(error); }
  }

  async placeOrder(input: PlaceOrderInput): Promise<Order> {
    if (!this.checkout || input.checkout_id !== this.checkout.id || !this.payment) throw new PurchaseError("conflict", "Payment is required", { status: 409 });
    try {
      const placeCodes = this.checkout.gift_codes ?? (this.checkout.gift_code ? [this.checkout.gift_code] : []);
      const response = await this.client.request<ApiRecord>("/api/checkout/place", { method: "POST", body: { cart_id: this.checkout.cart_id, guest_email: this.checkout.email, payment_method: input.payment_method, ...(placeCodes.length === 1 && placeCodes[0] ? { gift_code: placeCodes[0] } : {}), ...(placeCodes.length > 1 ? { gift_codes: placeCodes } : {}), ...(this.checkout.fulfillment_method ? { fulfillment_method: this.checkout.fulfillment_method } : {}), ...(this.checkout.pickup_point ? { pickup_point_code: this.checkout.pickup_point } : {}) }, idempotencyKey: input.idempotency_key, cartToken: this.cartToken, scope: this.scope });
       const order = normalizeOrder(dataOf(response), this.cart?.subtotal.currency ?? "USD", input.payment_method, this.payment);
       this.orderId = order.id;
       if (order.guest_access_token) this.orderToken = order.guest_access_token;
       return order;
    } catch (error) { throw errorFrom(error, true); }
  }

  async reconcileOrder(idempotencyKey: string): Promise<Order | null> {
    const accessToken = this.getAccessToken?.();
    const orderToken = this.orderToken ?? this.getOrderToken?.();
    // Order lookup is credential-bound. Never probe an authenticated endpoint
    // anonymously: without a session or guest order token the caller gets a
    // controlled error and no network request is made.
    if (!accessToken && !orderToken) throw new PurchaseError("unauthorized", "Sign in or use the guest order link to check this order", { status: 401 });
    try {
      if (accessToken) {
        const response = await this.client.request<unknown[]>("/api/account/orders", { accessToken, scope: this.scope });
        if (!Array.isArray(response.body.data)) throw new PurchaseError("server_error", "The service returned invalid order data");
        const match = response.body.data.find((item) => record(item).idempotency_key === idempotencyKey || record(item).idempotencyKey === idempotencyKey);
        return match ? normalizeOrder(match, this.cart?.subtotal.currency ?? "USD", this.payment?.method ?? "card") : null;
      }
      if (!this.orderId) throw new PurchaseError("unauthorized", "The guest order link does not identify an order", { status: 401 });
      const response = await this.client.request<ApiRecord>(`/api/orders/${encodeURIComponent(this.orderId)}`, { headers: { "X-Order-Token": orderToken as string }, scope: this.scope });
      return normalizeOrder(dataOf(response), this.cart?.subtotal.currency ?? this.scope?.currency ?? "USD", this.payment?.method ?? "card");
    } catch (error) { throw errorFrom(error); }
  }

  async login(email: string, password: string, turnstileToken?: string): Promise<CustomerSession> {
    try {
      const response = await this.client.request<ApiRecord>("/api/auth/login", { method: "POST", body: { email, password, ...(turnstileToken ? { "cf-turnstile-response": turnstileToken } : {}) }, scope: this.scope });
      const value = record(dataOf(response));
      return { access_token: stringValue(value.access_token ?? value.token), customer_id: stringValue(value.customer_id ?? value.user_id ?? value.id), ...(typeof value.refresh_token === "string" && value.refresh_token ? { refresh_token: value.refresh_token } : {}) };
    } catch (error) { throw errorFrom(error); }
  }

  async logout(accessToken: string): Promise<void> {
    try {
      await this.client.request<ApiRecord>("/api/auth/logout", { method: "POST", accessToken, scope: this.scope });
    } catch (error) { throw errorFrom(error); }
  }

  async refreshSession(refreshToken: string): Promise<CustomerSession> {
    try {
      const response = await this.client.request<ApiRecord>("/api/auth/refresh", { method: "POST", body: { refresh_token: refreshToken }, scope: this.scope });
      const value = record(dataOf(response));
      return { access_token: stringValue(value.access_token ?? value.token ?? value.access), customer_id: stringValue(value.customer_id ?? value.user_id ?? value.id), ...(typeof value.refresh_token === "string" && value.refresh_token ? { refresh_token: value.refresh_token } : {}) };
    } catch (error) { throw errorFrom(error); }
  }

  async register(input: { name: string; email: string; password: string; turnstileToken?: string }): Promise<CustomerSession> {
    try {
      const { turnstileToken, ...profile } = input;
      await this.client.request<ApiRecord>("/api/auth/register", { method: "POST", body: { ...profile, ...(turnstileToken ? { "cf-turnstile-response": turnstileToken } : {}) }, scope: this.scope });
      return this.login(input.email, input.password, turnstileToken);
    } catch (error) { throw errorFrom(error, true); }
  }

  async getAccount(accessToken: string): Promise<CustomerAccount> {
    try { const response = await this.client.request<ApiRecord>("/api/auth/me", { accessToken }); const value = record(dataOf(response)); return { id: stringValue(value.id), email: stringValue(value.email), name: stringValue(value.name), email_verified: value.email_verified_at !== null && value.email_verified_at !== undefined && value.email_verified_at !== "" }; } catch (error) { throw errorFrom(error); }
  }

  async requestPasswordReset(email: string, turnstileToken?: string): Promise<void> {
    try {
      await this.client.request<ApiRecord>("/api/auth/forgot-password", { method: "POST", body: { email, ...(turnstileToken ? { "cf-turnstile-response": turnstileToken } : {}) }, scope: this.scope });
    } catch (error) { throw errorFrom(error, true); }
  }

  async resetPassword(token: string, password: string): Promise<void> {
    try {
      await this.client.request<ApiRecord>("/api/auth/reset-password", { method: "POST", body: { token, password, password_confirmation: password }, scope: this.scope });
    } catch (error) { throw errorFrom(error, true); }
  }

  async verifyEmail(token: string): Promise<void> {
    try {
      await this.client.request<ApiRecord>("/api/auth/verify-email", { method: "POST", body: { token }, scope: this.scope });
    } catch (error) { throw errorFrom(error, true); }
  }

  async resendVerification(accessToken: string): Promise<void> {
    try {
      await this.client.request<ApiRecord>("/api/auth/verify-email/resend", { method: "POST", accessToken, scope: this.scope });
    } catch (error) { throw errorFrom(error, true); }
  }

  async changePassword(accessToken: string, currentPassword: string, newPassword: string): Promise<void> {
    try {
      await this.client.request<ApiRecord>("/api/profile/password", { method: "PUT", body: { current_password: currentPassword, new_password: newPassword }, accessToken, scope: this.scope });
    } catch (error) { throw errorFrom(error, true); }
  }

  async updateAccount(accessToken: string, input: { name: string; birthdate?: string }): Promise<CustomerAccount> {
    try { const response = await this.client.request<ApiRecord>("/api/auth/me", { method: "PUT", body: input, accessToken }); const value = record(dataOf(response)); return { id: stringValue(value.id), email: stringValue(value.email), name: stringValue(value.name), ...(typeof value.birthdate === "string" && value.birthdate ? { birthdate: value.birthdate } : {}) }; } catch (error) { throw errorFrom(error, true); }
  }

  async getOrderHistory(accessToken: string): Promise<Order[]> {
    try { const response = await this.client.request<unknown[]>("/api/account/orders", { accessToken, scope: this.scope }); if (!Array.isArray(response.body.data)) throw new PurchaseError("server_error", "The service returned invalid order data"); return response.body.data.map((item) => normalizeOrder(item, this.cart?.subtotal.currency ?? "USD", this.payment?.method ?? "card")); } catch (error) { throw errorFrom(error); }
  }

  async getOrder(accessToken: string, orderId: string): Promise<Order> {
    try {
      const response = await this.client.request<ApiRecord>(`/api/account/orders/${encodeURIComponent(orderId)}`, { accessToken, scope: this.scope });
      return normalizeOrder(dataOf(response), this.cart?.subtotal.currency ?? "USD", this.payment?.method ?? "card");
    } catch (error) { throw errorFrom(error); }
  }

  async getGuestOrder(orderNumber: string, email?: string): Promise<Order> {
    try {
      if (!email?.trim()) throw new PurchaseError("validation", "Email is required for guest order recovery", { status: 422, fieldErrors: { guestEmail: ["Enter the email used for the order"] } });
      const response = await this.client.request<ApiRecord>("/api/orders/recover", { method: "POST", body: { order_number: orderNumber, email: email.trim() }, scope: this.scope });
      return normalizeOrder(dataOf(response), this.cart?.subtotal.currency ?? "USD", this.payment?.method ?? "card");
    } catch (error) { throw errorFrom(error); }
  }

  async getGuestOrderByToken(orderId: string, token: string): Promise<Order> {
    try {
      if (!/^[A-Za-z0-9_-]{1,128}$/.test(orderId) || !/^[A-Za-z0-9_-]{16,256}$/.test(token)) throw new PurchaseError("validation", "The guest order link is invalid", { status: 422 });
      const response = await this.client.request<ApiRecord>(`/api/orders/${encodeURIComponent(orderId)}`, { headers: { "X-Order-Token": token }, scope: this.scope });
      return normalizeOrder(dataOf(response), this.cart?.subtotal.currency ?? this.scope?.currency ?? "USD", this.payment?.method ?? "card");
    } catch (error) { throw errorFrom(error); }
  }
}
