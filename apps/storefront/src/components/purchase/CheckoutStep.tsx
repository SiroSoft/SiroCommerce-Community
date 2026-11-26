import { PaymentStep } from "./PaymentStep";
import { CheckoutQuoteFields } from "./CheckoutQuoteFields";
import type { PurchaseController } from "./usePurchaseFlow";

export function CheckoutStep({ flow }: { flow: PurchaseController }) {
  const { copy, address, navigate, email, setEmail, fieldErrors, error, quoteStepEnabled } = flow;
  return <div className="purchase-panel"><h2>{copy.purchase.checkoutTitle}</h2>
    {quoteStepEnabled ? <section aria-labelledby="delivery-review-title"><h3 id="delivery-review-title">{copy.purchase.deliveryTitle}</h3><p>{address.name}, {address.line1}, {address.city}, {address.country} {address.postal_code}</p><button className="text-button" type="button" onClick={() => navigate("quote")}>{copy.purchase.editDelivery}</button></section> : <section aria-label={copy.purchase.deliveryTitle}><h3>{copy.purchase.deliveryTitle}</h3><CheckoutQuoteFields flow={flow} /></section>}
    <label htmlFor="email">{copy.purchase.email}<input id="email" aria-label={copy.purchase.email} aria-invalid={Boolean(fieldErrors.email?.length)} aria-describedby={error ? "purchase-error" : undefined} type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} /></label><PaymentStep flow={flow} /></div>;
}
