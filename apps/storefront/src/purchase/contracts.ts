export type Money = { amount_minor: number; currency: string };

export function isValidMoney(value: Money): boolean {
  return Boolean(value) && Number.isSafeInteger(value.amount_minor) && value.amount_minor >= 0 && /^[A-Z]{3}$/.test(value.currency);
}

export function sameCurrency(...values: Money[]): boolean {
  return values.length > 0 && values.every((value) => value.currency === values[0]?.currency);
}

export type PurchaseErrorCode =
  | "unauthorized"
  | "forbidden"
  | "not_found"
  | "conflict"
  | "validation"
  | "rate_limited"
  | "server_error"
  | "unknown_outcome";

export class PurchaseError extends Error {
  readonly code: PurchaseErrorCode;
  readonly status: number | undefined;
  readonly fieldErrors: Record<string, string[]>;

  constructor(
    code: PurchaseErrorCode,
    message: string,
    options: { status?: number; fieldErrors?: Record<string, string[]> } = {},
  ) {
    super(message);
    this.name = "PurchaseError";
    this.code = code;
    this.status = options.status;
    this.fieldErrors = options.fieldErrors ?? {};
  }
}

export type ProductHandoff = {
  product_id: string;
  slug: string;
  name: string;
  unit_price: Money;
  quantity: number;
  slot_start?: string;
  customer_price?: Money;
  quantity_tiers?: { minimum: number; unitPrice: Money; label?: string }[];
  ownership?: { ownerType: "store" | "vendor" | "marketplace"; ownerId: string; ownerName: string; commissionMessage: string };
};

export type CartLine = ProductHandoff & { line_total: Money; line_id?: string; image?: string; slot_start?: string; flash_sale_id?: string };
export type Cart = { id: string; token?: string; lines: CartLine[]; subtotal: Money };

export type Address = {
  name: string;
  line1: string;
  city: string;
  country: string;
  postal_code: string;
};

export type QuoteInput = { cart_id: string; shipping_address: Address; shipping_method: string; coupon_code?: string; gift_code?: string; gift_codes?: string[]; fulfillment_method?: string; pickup_point?: string };
export type PickupPoint = { code: string; name: string; address_line?: string; city?: string; sla_hours: number };
export type PickupSelection = { code: string; name: string; address_line?: string; city?: string; sla_hours: number; ready_by: string };
export type VoucherEligibility = "eligible" | "ineligible" | "expired" | "duplicate" | "unknown";
export type Voucher = { code: string; eligibility: VoucherEligibility; discount: Money; message?: string; expires_at?: string };
export type GiftTender = { code: string; usable: Money; balance: Money };
export type GiftCoverage = { code: string; covered: Money };
export type MemberDealView = { id: string; name: string; percent_off: number; discount: Money };
export type Quote = {
  id: string;
  cart_id: string;
  subtotal: Money;
  discount?: Money;
  member_discount?: Money;
  member_deal?: MemberDealView;
  gift_discount?: Money;
  gift_code?: string;
  gift_codes?: string[];
  shipping: Money;
  tax: Money;
  total: Money;
  expires_at: string;
  voucher?: Voucher;
  gift?: GiftTender;
  gift_tenders?: GiftCoverage[];
  pickup?: PickupSelection;
};

export type CheckoutInput = QuoteInput & { email: string };
export type Checkout = { id: string; quote_id: string; email: string; status: "ready" | "needs_payment"; gift_code?: string; gift_codes?: string[]; fulfillment_method?: string; pickup_point?: string };
export type PaymentMethod = "card" | "bank_transfer" | "cash_on_delivery";
export type Payment = { id: string; status: "pending" | "authorized" | "captured" | "failed" | "unknown"; method: PaymentMethod; failure_reason?: string };
export type OrderDelivery = { mode: "physical" | "virtual" | "downloadable" | "scheduled" | "unknown"; state: "pending" | "ready" | "shipped" | "delivered" | "unavailable" | "unknown"; warehouse?: string; scheduled_for?: string };
export type OrderNotification = { state: "queued" | "sending" | "sent" | "failed" | "skipped" | "unavailable" | "unknown"; event: string; attempts?: number; last_error?: string; sent_at?: string; created_at?: string };
export type Order = { id: string; number: string; status: "placed" | "pending_reconciliation" | "processing" | "ready_to_ship" | "shipped" | "delivered" | "cancelled" | "refunded" | "partially_refunded" | "failed"; total: Money; payment: Payment; guest_access_token?: string; pickup_point_code?: string; delivery?: OrderDelivery & { carrier?: string; tracking?: string }; notifications?: OrderNotification[]; returns?: Array<{ id: string; state: string; refund_state?: string }>; vendor?: { id: string; name: string }; site?: { id: string; name: string } };
export type CustomerSession = { access_token: string; customer_id: string; refresh_token?: string };
export type CustomerAccount = { id: string; email: string; name: string; email_verified?: boolean; birthdate?: string };
export type Subscription = { id: string; status: "active" | "paused" | "cancelled" | "unknown"; product_name: string; interval: string; next_charge_at?: string; price: Money };

export type { CustomerPrice, FinancialCapability, MarketplaceOwnership, OrganizationContext, OrderPolicy, QuantityTier, QuoteRequest, QuoteRequestStatus, StorefrontCommerceContext } from "./b2b";

export type PlaceOrderInput = { checkout_id: string; payment_method: PaymentMethod; idempotency_key: string };

export interface PurchaseOperations {
  addToCart(input: ProductHandoff): Promise<Cart>;
  getCart(cartId: string): Promise<Cart>;
  setLineQuantity?(cartId: string, lineId: string, quantity: number): Promise<Cart>;
  removeLine?(cartId: string, lineId: string): Promise<Cart>;
  hydrate?(cart: Cart): void;
  createQuote(input: QuoteInput): Promise<Quote>;
  previewVoucher?(input: { code: string; subtotal: Money; lines: CartLine[] }): Promise<Voucher>;
  previewGift?(input: { code: string; subtotal: Money }): Promise<GiftTender>;
  getPickupPoints?(): Promise<PickupPoint[]>;
  createCheckout(input: CheckoutInput): Promise<Checkout>;
  authorizePayment(checkoutId: string, method: PaymentMethod, idempotencyKey: string): Promise<Payment>;
  getPaymentStatus(paymentId: string): Promise<Payment>;
  placeOrder(input: PlaceOrderInput): Promise<Order>;
  reconcileOrder(idempotencyKey: string): Promise<Order | null>;
  login(email: string, password: string, turnstileToken?: string): Promise<CustomerSession>;
  register?(input: { name: string; email: string; password: string; turnstileToken?: string }): Promise<CustomerSession>;
  refreshSession?(refreshToken: string): Promise<CustomerSession>;
  logout?(accessToken: string): Promise<void>;
  getAccount(accessToken: string): Promise<CustomerAccount>;
  updateAccount(accessToken: string, input: { name: string; birthdate?: string }): Promise<CustomerAccount>;
  requestPasswordReset(email: string, turnstileToken?: string): Promise<void>;
  resetPassword(token: string, password: string): Promise<void>;
  verifyEmail(token: string): Promise<void>;
  resendVerification(accessToken: string): Promise<void>;
  changePassword(accessToken: string, currentPassword: string, newPassword: string): Promise<void>;
  getOrderHistory(accessToken: string): Promise<Order[]>;
  getOrder(accessToken: string, orderId: string): Promise<Order>;
  getGuestOrder(orderNumber: string, email?: string): Promise<Order>;
  getGuestOrderByToken?(orderId: string, token: string): Promise<Order>;
  getSubscriptions?(accessToken: string): Promise<Subscription[]>;
  getSubscription?(accessToken: string, subscriptionId: string): Promise<Subscription>;
  getCommerceContext?(): Promise<import("./b2b").StorefrontCommerceContext>;
  requestQuote?(input: { cart_id: string; note?: string }): Promise<import("./b2b").QuoteRequest>;
  getQuoteRequest?(id: string): Promise<import("./b2b").QuoteRequest>;
  approveQuote?(id: string): Promise<import("./b2b").QuoteRequest>;
}
