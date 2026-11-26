/**
 * Production-only stand-in for the fixture purchase adapter.
 *
 * `next.config.ts` aliases `@/purchase/fixture-adapter` to this module for
 * production builds so fake buyer/order data is never shipped. Development and
 * test builds keep the real adapter, and callers that need it must use the
 * live API adapter (or the fail-closed adapter) in production.
 */
export class FixturePurchaseAdapter {
  async hydrate(): Promise<void> {}
}
