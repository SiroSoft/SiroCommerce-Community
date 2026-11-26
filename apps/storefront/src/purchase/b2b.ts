import type { Money } from "./contracts";

export type OrganizationContext = {
  organization: { id: string; name: string; role?: "member" | "buyer" | "approver" | "admin" };
  customer: { id: string; name?: string; email?: string };
  store: { id: string; name: string; currency: string; locale?: string };
  vendor?: { id: string; name: string };
};

export type CustomerPrice = { price: Money; source: "customer" | "tier" | "catalog"; label?: string };
export type QuantityTier = { minimum: number; unitPrice: Money; label?: string };
export type OrderPolicy = { minimumOrder?: Money; minimumQuantity?: number; message?: string };
export type MarketplaceOwnership = { ownerType: "store" | "vendor" | "marketplace"; ownerId: string; ownerName: string; commissionMessage: string };
export type FinancialCapability = { available: false; reason: string };

export type StorefrontCommerceContext = OrganizationContext & {
  pricing: { customer?: CustomerPrice; tiers?: QuantityTier[] };
  orderPolicy?: OrderPolicy;
  credit: FinancialCapability;
  payout: FinancialCapability;
  payment: FinancialCapability;
  marketplace?: MarketplaceOwnership;
};

export type QuoteRequestStatus = "draft" | "requested" | "submitted" | "under_review" | "approved" | "accepted" | "converted" | "rejected" | "expired";
export type QuoteRequest = { id: string; status: QuoteRequestStatus; requestedAt: string; expiresAt?: string; approverMessage?: string };

export function financialUnavailable(reason: string): FinancialCapability {
  return { available: false, reason };
}

export function validateCommerceContext(context: StorefrontCommerceContext): StorefrontCommerceContext {
  if (!context.organization?.id || !context.store?.id || !/^[A-Z]{3}$/.test(context.store.currency)) throw new Error("Invalid commerce context");
  if (context.credit.available || context.payout.available || context.payment.available) throw new Error("Unavailable financial capabilities must fail closed");
  if (context.pricing.tiers?.some((tier) => !Number.isInteger(tier.minimum) || tier.minimum < 1 || tier.unitPrice.currency !== context.store.currency)) throw new Error("Invalid quantity pricing");
  return context;
}
