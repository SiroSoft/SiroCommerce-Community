import { fill } from "@/lib/i18n";
import { AddressFields } from "./AddressStep";
import { FulfillmentControls } from "./QuoteStep";
import type { PurchaseController } from "./usePurchaseFlow";

export function CheckoutQuoteFields({ flow }: { flow: PurchaseController }) {
  const {
    copy, money, address, setAddress, addressFieldLabel, voucher, voucherCode, setVoucherCode,
    voucherSubmitting, applyVoucher, giftCode, setGiftCode, giftSubmitting, applyGift, gifts, removeGift,
  } = flow;
  return <>
    <AddressFields address={address} labelFor={addressFieldLabel} onChange={(field, value) => setAddress((current) => ({ ...current, [field]: value }))} />
    <FulfillmentControls flow={flow} />
    <fieldset>
      <legend>{copy.purchase.voucher}</legend>
      <label htmlFor="voucher">{copy.purchase.code}<input id="voucher" value={voucherCode} onChange={(event) => setVoucherCode(event.target.value)} autoComplete="off" /></label>
      <button className="text-button" type="button" onClick={() => void applyVoucher()} disabled={voucherSubmitting}>{voucherSubmitting ? copy.purchase.checkingVoucher : copy.purchase.applyVoucher}</button>
      {voucher && <p className="form-notice" role="status">{voucher.message ?? fill(copy.purchase.voucherApplied, { code: voucher.code })} · {copy.purchase.discount} {money(voucher.discount.amount_minor)}</p>}
    </fieldset>
    <fieldset>
      <legend>{copy.purchase.gift}</legend>
      <label htmlFor="gift">{copy.purchase.code}<input id="gift" value={giftCode} onChange={(event) => setGiftCode(event.target.value)} autoComplete="off" /></label>
      <button className="text-button" type="button" onClick={() => void applyGift()} disabled={giftSubmitting}>{giftSubmitting ? copy.purchase.checkingGift : copy.purchase.applyGift}</button>
      {gifts.map((tender) => <p key={tender.code} className="form-notice" role="status">{fill(copy.purchase.giftApplied, { code: tender.code })} · {copy.purchase.giftUsable} {money(tender.usable.amount_minor)} <button className="text-button" type="button" onClick={() => removeGift(tender.code)} aria-label={fill(copy.purchase.removeGift, { code: tender.code })}>{copy.purchase.remove}</button></p>)}
    </fieldset>
  </>;
}
