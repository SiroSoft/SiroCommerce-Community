import { fill } from "@/lib/i18n";
import type { Order } from "@/purchase/contracts";
import type { PurchaseController } from "./usePurchaseFlow";

export function PaymentStep({ flow }: { flow: PurchaseController }) {
  const {
    copy, paymentMethod, setPaymentMethod, liveMode, submitting, placeOrder, checkoutUnavailable,
    showRetry, authState, history, commerceContext, bankTransferInstructions, supportEmail,
  } = flow;
  return <>
    <fieldset><legend>{copy.purchase.payment}</legend><label><input type="radio" name="payment" value="cash_on_delivery" checked={paymentMethod === "cash_on_delivery"} onChange={() => setPaymentMethod("cash_on_delivery")} /> {copy.purchase.cashPayment}</label><label><input type="radio" name="payment" value="bank_transfer" checked={paymentMethod === "bank_transfer"} onChange={() => setPaymentMethod("bank_transfer")} /> {copy.purchase.bankTransfer}</label><label><input type="radio" name="payment" value="card" checked={paymentMethod === "card"} onChange={() => setPaymentMethod("card")} /> {copy.purchase.cardPayment}</label></fieldset>
    {paymentMethod === "bank_transfer" && bankTransferInstructions ? <p className="form-notice" role="note">{bankTransferInstructions}</p> : null}
    {liveMode && paymentMethod === "card" ? <p className="form-error" role="note"><strong>{copy.purchase.unavailableTitle}</strong> {copy.purchase.unavailableBody}</p> : <p className="form-notice" role="note"><strong>{copy.purchase.readyTitle}</strong> {copy.purchase.readyBody}</p>}
    {commerceContext?.marketplace && <p className="muted" role="note">{commerceContext.marketplace.commissionMessage}</p>}
    <button className={submitting ? "button is-loading" : "button"} type="button" onClick={placeOrder} aria-busy={submitting} disabled={submitting || checkoutUnavailable}>{submitting ? copy.purchase.checkingPayment : copy.purchase.placeOrder}</button>
    {showRetry && <button className="text-button" type="button" onClick={placeOrder} disabled={submitting || checkoutUnavailable}>{copy.purchase.retrySafely}</button>}
    <p role="note">{authState || copy.purchase.guestCheckout}{history.length > 0 ? `, ${fill(copy.purchase.ordersInHistory, { count: history.length })}` : ""}</p>
    <p className="trust-strip" role="note">{copy.purchase.needHelp}{supportEmail ? <> <a className="text-link" href={`mailto:${supportEmail}?subject=Checkout%20help`}>{copy.purchase.contactSupport}</a>.</> : null} {copy.purchase.neverShare}</p>
    <p className="muted">{copy.purchase.secureKeyNote}</p>
  </>;
}

export function BankProofPanel({ flow, order, variant }: { flow: PurchaseController; order?: Order; variant: "result" | "detail" }) {
  const { copy, proofReference, setProofReference, proofUrl, proofUploading, proofError, uploadProofFile, submitProof } = flow;
  if (variant === "detail") {
    return <div className="order-review">
      <h4>{copy.purchase.uploadProofTitle}</h4>
      <label>{copy.purchase.referenceLabel}<input value={proofReference} onChange={(event) => setProofReference(event.target.value)} placeholder="FT123456" /></label>
      <label>{copy.purchase.receiptLabel}<input type="file" accept="image/*,application/pdf" onChange={(event) => void uploadProofFile(event.target.files?.[0], "detail")} /></label>
      {proofUrl && <p className="muted" style={{ overflowWrap: "anywhere" }}>{copy.purchase.proofPrefix} <a href={proofUrl} target="_blank" rel="noreferrer" className="text-link">{proofUrl}</a></p>}
      <button type="button" className="text-button" onClick={() => void submitProof(order, "detail")} disabled={proofUploading}>{proofUploading ? copy.purchase.proofSubmitting : copy.purchase.proofSubmit}</button>
      {proofError && <p className="form-error">{proofError}</p>}
    </div>;
  }
  return <div className="order-review" aria-label={copy.purchase.bankProofTitle}>
    <h3>{copy.purchase.bankProofTitle}</h3>
    <p className="muted">{copy.purchase.bankProofCopy}</p>
    <label htmlFor="proof-ref">{copy.purchase.proofRefLabel}<input id="proof-ref" value={proofReference} onChange={(event) => setProofReference(event.target.value)} placeholder="e.g. FT123456" /></label>
    <label htmlFor="proof-file">{copy.purchase.proofReceiptOptional}<input id="proof-file" type="file" accept="image/*,application/pdf" onChange={(event) => void uploadProofFile(event.target.files?.[0], "result")} disabled={proofUploading} /></label>
    {proofError && <p className="form-error" role="alert">{proofError}</p>} {proofUrl && <p className="muted" style={{ overflowWrap: "anywhere" }}>{copy.purchase.proofPrefix} <a href={proofUrl} target="_blank" rel="noreferrer" className="text-link">{proofUrl}</a>{proofReference ? ` · ${copy.purchase.refPrefix} ${proofReference}` : ""}</p>}
    <button type="button" className="text-button" onClick={() => void submitProof(order, "result")} disabled={proofUploading}>{proofUploading ? copy.purchase.proofSubmitting : copy.purchase.proofSubmit}</button>
  </div>;
}
