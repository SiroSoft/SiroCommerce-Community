import Link from "next/link";
import { cartLineKey } from "./helpers";
import type { PurchaseController } from "./usePurchaseFlow";

const FLASH_COPY = { added: "addedToBag", updated: "updatedBag", removed: "removedFromBag" } as const;

export function CartStep({ flow }: { flow: PurchaseController }) {
  const {
    copy, locale, money, cart, cartFlash, setCartFlash, lineBusy, changeLineQuantity, removeCartLine,
    product, quantity, setQuantity, editQuantity, quantityError, purchaseVariant, commerceContext, subtotal,
  } = flow;
  const flashKey = cartFlash === "added" || cartFlash === "updated" || cartFlash === "removed" ? cartFlash : undefined;
  return <div className="purchase-panel cart-panel"><h2>{copy.purchase.cartItems}</h2>
    {flashKey && <p className="form-notice cart-flash" role="status">{copy.purchase[FLASH_COPY[flashKey]]} <button className="text-button" type="button" onClick={() => setCartFlash(undefined)} aria-label={copy.purchase.dismissNotice}>×</button></p>}
    {cartFlash === "failed" && <p className="form-error cart-flash" role="alert">{copy.purchase.addFailed} <button className="text-button" type="button" onClick={() => setCartFlash(undefined)} aria-label={copy.purchase.dismissNotice}>×</button></p>}
    {commerceContext && (commerceContext.organization.name !== "default" || commerceContext.store.name !== "default") && <p className="context-strip" role="note"><strong>{commerceContext.organization.name}</strong> · {commerceContext.store.name}{commerceContext.vendor ? ` · supplied by ${commerceContext.vendor.name}` : ""}</p>}
    {cart && cart.lines.length > 0
      ? <ul className="cart-items" aria-label={copy.purchase.cartItems}>{cart.lines.map((line) => {
        const key = cartLineKey(line);
        const busy = lineBusy[key] ?? false;
        return <li className="cart-item" key={key}>
          {line.image ? <img className="cart-item-image" src={line.image} alt="" width="96" height="96" loading="lazy" /> : <span className="cart-item-image cart-item-image-missing" aria-hidden="true" />}
          <div className="cart-item-main">
            <strong>{line.slug ? <Link className="text-link" href={`/product/${line.slug}`}>{line.name}</Link> : line.name}</strong>
            <span className="muted">{money(line.unit_price.amount_minor, line.unit_price.currency)} {copy.purchase.each}</span>
            {line.slot_start && <span className="muted"> · {new Date(line.slot_start.length === 19 ? `${line.slot_start.replace(" ", "T")}Z` : line.slot_start).toLocaleString(locale)}</span>}
            {line.flash_sale_id && <span className="product-badge">⚡ Flash deal</span>}
            <div className="cart-line-controls">
              <div className="quantity-stepper" role="group" aria-label={`${line.name}: ${copy.purchase.quantity}`}>
                <button type="button" aria-label={copy.purchase.decreaseLine} onClick={() => void changeLineQuantity(key, -1)} disabled={busy || line.quantity <= 1}>−</button>
                <span aria-live="polite" aria-label={copy.purchase.quantity}>{line.quantity}</span>
                <button type="button" aria-label={copy.purchase.increaseLine} onClick={() => void changeLineQuantity(key, 1)} disabled={busy || line.quantity >= 99}>+</button>
              </div>
              <button type="button" className="text-button" aria-label={`${copy.purchase.removeLine}: ${line.name}`} onClick={() => void removeCartLine(key)} disabled={busy}>{busy ? copy.purchase.updatingLine : copy.purchase.removeLine}</button>
            </div>
          </div>
          <strong className="cart-item-total">{money(line.line_total.amount_minor, line.line_total.currency)}</strong>
        </li>;
      })}</ul>
      : <p className="cart-empty">{copy.purchase.cartEmpty}</p>}
    {(!cart || cart.lines.length === 0) && product.name && <p className="muted">{copy.purchase.selectedItem} <strong>{product.name}</strong></p>}
    {(!cart || cart.lines.length === 0) && <>
      <label htmlFor="quantity">{copy.purchase.quantity}<input id="quantity" aria-label={copy.purchase.quantity} type="number" min="1" max="99" value={quantity} onChange={(event) => editQuantity(event.target.value)} aria-invalid={Boolean(quantityError)} aria-describedby={quantityError ? "quantity-error" : undefined} /></label>
      {quantityError && <p id="quantity-error" className="form-error" role="alert">{quantityError}</p>}
    </>}
    {commerceContext?.pricing.customer && <p className="muted" data-testid="customer-price">{commerceContext.pricing.customer.label}: {money(commerceContext.pricing.customer.price.amount_minor)} · {copy.purchase.customerSpecific}</p>}
    <p className="cart-subtotal">{copy.purchase.subtotal} <strong data-testid="subtotal">{money(subtotal)}</strong></p>
    {purchaseVariant !== "compact" && <p className="trust-strip" role="note">{copy.purchase.cartTrust}</p>}
  </div>;
}
