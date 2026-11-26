"use client";

/**
 * Public purchase-flow entry point. The implementation lives in the smaller
 * `purchase/` modules so each step can be reviewed and tested in isolation;
 * this barrel keeps existing imports and the lazy loader stable.
 */
export { PurchaseFlow, resolvePurchaseMode, CHECKOUT_UNAVAILABLE_NOTICE } from "./purchase/PurchaseFlow";
export type { PurchaseMode } from "./purchase/mode";
