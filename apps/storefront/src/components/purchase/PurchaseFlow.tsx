"use client";

import type { Cart } from "@/purchase/contracts";
import type { QuoteMode } from "@/services/storefront-config";
import { CHECKOUT_UNAVAILABLE_NOTICE, resolvePurchaseMode } from "./mode";
import { usePurchaseFlow } from "./usePurchaseFlow";
import { FixtureControls } from "./FixtureControls";
import { CartStep } from "./CartStep";
import { FulfillmentControls, QuoteStep } from "./QuoteStep";
import { CheckoutStep } from "./CheckoutStep";
import { OrderReviewPanel } from "./OrderReviewPanel";
import { AccountStep } from "./AccountStep";
import { OrderDetailStep } from "./OrderDetailStep";
import { OrderResultStep } from "./OrderResultStep";

export { resolvePurchaseMode, CHECKOUT_UNAVAILABLE_NOTICE };
export type { PurchaseMode } from "./mode";

export function PurchaseFlow({ initialStep, initialCart, flash, quoteMode }: { initialStep?: string; initialCart?: Cart; flash?: "added" | "updated" | "removed" | "failed"; quoteMode?: QuoteMode } = {}) {
  const flow = usePurchaseFlow({ initialStep, initialCart, flash, quoteMode });
  const { copy, step, navigate, stepLabel, checkoutUnavailable, submitting, cartSyncing, actionLabel, runPrimaryAction, error, validationSummary, apiError, notice, order, purchaseVariant, steps } = flow;
  const currentIndex = (steps as readonly string[]).indexOf(step);
  return <section className="purchase-flow" data-step={step} data-purchase-variant={purchaseVariant} aria-label={copy.purchase.progress}>
    <nav className="checkout-progress" aria-label={copy.purchase.progress}><ol>{steps.map((item, index) => <li key={item} className={item === step ? "current" : index < currentIndex ? "complete" : ""}><button type="button" onClick={() => (item === "cart" || index <= currentIndex) && navigate(item)} disabled={index > currentIndex && item !== "cart"} aria-current={item === step ? "step" : undefined}>{index + 1}. {stepLabel(item)}</button></li>)}</ol></nav>
    <p className="flow-status" role="status" aria-live="polite">{copy.purchase.flowStep}: {stepLabel(step)}</p>
    {checkoutUnavailable && <p className="form-notice" role="note" data-testid="checkout-unavailable"><strong>{CHECKOUT_UNAVAILABLE_NOTICE}</strong></p>}
    {(step === "cart" || step === "quote") && <div className="purchase-primary-action" aria-label={copy.purchase.primaryAction}><button className={submitting || cartSyncing ? "button is-loading" : "button"} type="button" onClick={runPrimaryAction} aria-busy={submitting || cartSyncing} disabled={submitting || cartSyncing}>{submitting ? copy.purchase.checkingPayment : cartSyncing ? copy.purchase.preparing : actionLabel}</button></div>}
    <FixtureControls flow={flow} />
    {step === "checkout" && <p className="form-notice" role="note"><strong>{copy.purchase.finalCheck}</strong> {copy.purchase.finalCheckCopy}</p>}
    {error && <p className="form-error" role="alert" id="purchase-error"><strong>{copy.purchase.couldNotContinue}</strong> {error}</p>}
    {validationSummary.length > 0 && <div className="form-error" role="group" aria-label={copy.purchase.validationSummary}><strong>{copy.purchase.validationSummary}</strong><ul>{validationSummary.map((message) => <li key={message}>{message}</li>)}</ul></div>}
    {apiError && <p className="form-error" role="alert">API error {apiError}: request was not submitted.</p>}
    {notice && <p className="form-notice" role="status">{notice}</p>}
    {step === "quote" && <FulfillmentControls flow={flow} />}
    {step === "cart" && <CartStep flow={flow} />}
    {step === "quote" && <QuoteStep flow={flow} />}
    {step === "checkout" && <CheckoutStep flow={flow} />}
    {(step === "cart" || step === "quote" || step === "checkout") && <OrderReviewPanel flow={flow} />}
    {step === "account" && <AccountStep flow={flow} />}
    {step === "order-detail" && order && <OrderDetailStep flow={flow} />}
    {step === "order-result" && <OrderResultStep flow={flow} />}
  </section>;
}
