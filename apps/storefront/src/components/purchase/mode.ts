export type PurchaseMode = { live: boolean; fixture: boolean; unavailable: boolean };

/**
 * Resolve checkout mode from configuration. Fixtures are a developer/test aid:
 * production must opt in explicitly before any fake buyer or order data is shown.
 */
export function resolvePurchaseMode(
  source = process.env.NEXT_PUBLIC_STOREFRONT_PURCHASE_SOURCE,
  nodeEnv = process.env.NODE_ENV,
  allowFixture = process.env.NEXT_PUBLIC_STOREFRONT_ALLOW_FIXTURE,
): PurchaseMode {
  const live = source === "api";
  const allowed = nodeEnv !== "production" || allowFixture === "1";
  const denied = allowFixture === "0";
  const fixture = !live && allowed && !denied;
  return { live, fixture, unavailable: !live && !fixture };
}

export const CHECKOUT_UNAVAILABLE_NOTICE = "Checkout is unavailable in this environment";
