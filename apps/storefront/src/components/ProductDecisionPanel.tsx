"use client";

import { useState, useMemo, useEffect } from "react";
import { formatMinor } from "@/lib/money";
import { fill } from "@/lib/i18n";
import { useCopy, useLocale } from "@/lib/i18n-client";
import { StockAlertSignup } from "@/components/StockAlertSignup";
import { ProductInfoAccordion } from "@/components/merchandising/ProductInfoAccordion";
import { customer, getCustomerAccessToken, type CustomerAdapter } from "@/services/customer";

import type { Product, SubscriptionPlan } from "@/types";
import type { ProductDetailVariant } from "@/theme/types";

/** Backend datetimes are naive server (UTC) strings; parse them as UTC so the
 * countdown matches the server window in every browser timezone. */
export function serverDate(value: string): Date {
  const v = value.trim();
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(v)) return new Date(`${v.replace(" ", "T")}Z`);
  return new Date(v);
}

function countdownParts(endsAt: string, now: number): { h: number; m: number; s: number; ended: boolean } {
  const diff = serverDate(endsAt).getTime() - now;
  if (!Number.isFinite(diff) || diff <= 0) return { h: 0, m: 0, s: 0, ended: true };
  const total = Math.floor(diff / 1000);
  return { h: Math.floor(total / 3600), m: Math.floor((total % 3600) / 60), s: total % 60, ended: false };
}

export function FlashCountdown({ endsAt, locale, endedLabel, endsInTemplate }: { endsAt: string; locale: string; endedLabel?: string; endsInTemplate?: string }) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const t = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(t);
  }, []);
  const { h, m, s, ended } = countdownParts(endsAt, now);
  if (ended) return <span className="muted">{endedLabel ?? "Deal ended"}</span>;
  const pad = (n: number) => String(n).padStart(2, "0");
  const label = (endsInTemplate ?? "Deal ends in {h} hours {m} minutes").split("{h}").join(String(h)).split("{m}").join(String(m));
  return <span role="timer" aria-label={label} aria-live="off">{pad(h)}:{pad(m)}:{pad(s)}</span>;
}

export function resolveVariantOption(product: Product, options: Record<string, string>): Product["variantOptions"] extends (infer Item)[] | undefined ? Item | undefined : undefined {
  const frames = product.variantOptions ?? [];
  const exact = frames.filter((frame) => Object.entries(options).every(([name, value]) => frame.options[name] === value));
  if (exact.length === 1) return exact[0];
  if (exact.length > 1) return exact.sort((a, b) => a.price - b.price)[0];
  const partial = frames.filter((frame) => Object.entries(options).some(([name, value]) => frame.options[name] === value));
  return partial.length > 0 ? partial.sort((a, b) => a.price - b.price)[0] : frames.sort((a, b) => a.price - b.price)[0];
}

export function SubscribeAndSave({ plans, adapter = customer(), onNavigate }: { plans: SubscriptionPlan[]; adapter?: CustomerAdapter; onNavigate?: (url: string) => void }) {
  const locale = useLocale();
  const copy = useCopy().product;
  const [busy, setBusy] = useState("");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  if (plans.length === 0) return null;
  const signedIn = getCustomerAccessToken() !== "";
  const start = (plan: SubscriptionPlan) => {
    if (!signedIn) return;
    setBusy(plan.id); setError(""); setNotice("");
    void adapter.startSubscription(plan.id, true)
      .then(() => {
        setNotice(copy.subscribeSuccess);
        const target = "/account#subscriptions";
        if (onNavigate) onNavigate(target);
        else window.location.assign(target);
      })
      .catch((value) => setError(value instanceof Error && value.message ? value.message : copy.subscribeFailed))
      .finally(() => setBusy(""));
  };
  return <section className="decision-section subscribe-save" aria-labelledby="subscribe-save-heading">
    <h2 id="subscribe-save-heading">{copy.subscribeTitle}</h2>
    <p className="muted" role="note">{copy.subscribeNote}</p>
    <ul className="plan-grid" aria-label={copy.subscribeTitle}>
      {plans.map((plan) => <li className="plan-card" key={plan.id}>
        <h3>{plan.name}</h3>
        <p className="plan-price">{formatMinor(plan.price, plan.currency, locale)} <span>/ {fill(copy.subscribeEvery, { count: plan.intervalCount ?? 1, interval: plan.interval })}</span></p>
        {plan.trialPeriod && (plan.trialPeriodCount ?? 0) > 0 && <p className="muted">{fill(copy.subscribeTrial, { count: plan.trialPeriodCount ?? 0, interval: plan.trialPeriod })}</p>}
        {signedIn
          ? <button className="button" type="button" onClick={() => start(plan)} disabled={busy !== ""}>{busy === plan.id ? copy.subscribeStarting : copy.subscribeButton}</button>
          : <a className="button" href="/account">{copy.subscribeSignIn}</a>}
      </li>)}
    </ul>
    {error && <p className="form-error" role="alert">{error}</p>}
    {notice && <p className="form-notice" role="status">{notice}</p>}
  </section>;
}

export function ProductDecisionPanel({ product, variant = "editorial", subscriptionAdapter, onSubscribeNavigate }: { product: Product; variant?: ProductDetailVariant; subscriptionAdapter?: CustomerAdapter; onSubscribeNavigate?: (url: string) => void }) {
  const [quantity, setQuantity] = useState(1);
  const [adding, setAdding] = useState(false);
  const [options, setOptions] = useState<Record<string, string>>(() => Object.fromEntries((product.options ?? []).map((option) => [option.name, option.values[0] ?? ""])));
  const bookingSlots = product.bookingSlots ?? [];
  const isBooking = product.productType === "booking";
  const [selectedSlot, setSelectedSlot] = useState<string>(() => bookingSlots[0]?.starts_at ?? "");
  const selectedSlotRow = useMemo(() => bookingSlots.find((s) => s.starts_at === selectedSlot), [bookingSlots, selectedSlot]);
  const locale = useLocale();
  const full = useCopy();
  const copy = full.product;
  const resolved = product.variantOptions && product.variantOptions.length > 0 ? resolveVariantOption(product, options) : undefined;
  const bookingVariantId = useMemo(() => selectedSlotRow?.variant_id ?? product.booking?.variant_id ?? product.id, [selectedSlotRow, product]);
  const effectiveVariantId = isBooking ? bookingVariantId : resolved?.variantId ?? product.id;
  const [remaining, setRemaining] = useState<number | null>(null);
  const [sizeGuideOpen, setSizeGuideOpen] = useState(false);
  useEffect(() => {
    if (!isBooking || !selectedSlot) { setRemaining(null); return; }
    let cancelled = false;
    // fixture mode has no backend — keep capacity
    if (process.env.STOREFRONT_CATALOG_SOURCE === "fixture") { setRemaining(selectedSlotRow?.capacity ?? null); return; }
    fetch(`/api/v2/products/${encodeURIComponent(product.id)}/booking-quote?slot_start=${encodeURIComponent(selectedSlot)}`, { cache: "no-store" })
      .then((r) => r.json().catch(() => null))
      .then((j) => { if (!cancelled && j?.success && j?.data?.remaining !== undefined) setRemaining(Number(j.data.remaining)); else if (!cancelled) setRemaining(selectedSlotRow?.capacity ?? null); })
      .catch(() => { if (!cancelled) setRemaining(selectedSlotRow?.capacity ?? null); });
    return () => { cancelled = true; };
  }, [isBooking, selectedSlot, product.id, selectedSlotRow]);
  const unavailable = product.capability === "unavailable";
  const soldOut = product.status === "out_of_stock";
  const variantStock = resolved?.stock;
  const baseMaxQuantity = variantStock !== undefined ? Math.max(1, variantStock) : product.stock === undefined ? 99 : Math.max(1, product.stock);
  const bookingCapacity = selectedSlotRow ? selectedSlotRow.capacity : 0;
  const effectiveCapacity = remaining !== null ? remaining : bookingCapacity;
  const maxQuantity = isBooking ? Math.max(1, effectiveCapacity || 1) : baseMaxQuantity;
  const tier = product.quantityPricing?.filter((item) => item.minimum <= quantity).sort((a, b) => b.minimum - a.minimum)[0];
  const bookingPrice = selectedSlotRow?.price_minor != null ? selectedSlotRow.price_minor : undefined;
  const bookingCurrency = selectedSlotRow?.currency ?? product.currency;
  const unitPrice = isBooking ? bookingPrice ?? product.price : tier?.price ?? resolved?.price ?? product.price;
  const comparePrice = resolved?.comparePrice ?? product.compareAtPrice;
  const lineCurrency = isBooking ? bookingCurrency : tier?.currency ?? product.currency;
  const canPurchaseBase = !unavailable && !soldOut;
  const canPurchase = isBooking ? canPurchaseBase && bookingSlots.length > 0 && !!selectedSlot && bookingCapacity > 0 : canPurchaseBase;
  const surchargeFor = (option: string, value: string) => product.optionPricing?.find((item) => item.option === option && item.value === value);
  const availability = isBooking ? (bookingSlots.length === 0 ? copy.bookingNoSlots : canPurchase ? fill(copy.bookingAvailability, { count: bookingSlots.length }) : copy.unavailableNow) : product.status === "backorder" ? copy.reserve : soldOut ? copy.unavailableNow : unavailable ? copy.catalogOnly : copy.available;
  const onSale = comparePrice !== undefined && comparePrice > unitPrice;
  const flashDeal = useMemo(() => (product.flashSales ?? []).find((f) => f.variant_id === effectiveVariantId) ?? (product.flashSales ?? [])[0], [product.flashSales, effectiveVariantId]);
  const flashUnitPrice = !isBooking && flashDeal ? flashDeal.sale_price_minor : undefined;
  const displayPrice = flashUnitPrice ?? unitPrice;
  const displayCompare = flashUnitPrice ?? comparePrice;
  const memberRows = useMemo(() => {
    const rows = (product.memberPrices ?? []).filter((m) => m.variant_id === effectiveVariantId);
    const best = new Map<string, { tier: string; price_minor: number; currency: string }>();
    for (const r of rows) {
      const prev = best.get(r.tier);
      if (!prev || r.price_minor < prev.price_minor) best.set(r.tier, { tier: r.tier, price_minor: r.price_minor, currency: r.currency });
    }
    return [...best.values()].sort((a, b) => a.price_minor - b.price_minor);
  }, [product.memberPrices, effectiveVariantId]);
  const storeRows = useMemo(() => {
    const srcs = product.stockBySource?.[effectiveVariantId] ?? {};
    return Object.entries(srcs).filter(([src]) => src !== "default").map(([src, qty]) => ({ src, qty })).sort((a, b) => b.qty - a.qty);
  }, [product.stockBySource, effectiveVariantId]);
  const sizeOption = useMemo(() => (product.options ?? []).find((option) => /size|fit|kích cỡ|kich co/i.test(option.name)), [product.options]);
  const sizeAttributes = useMemo(() => (product.attributes ?? []).filter((attribute) => /size|fit|measure|dimension|kích/i.test(attribute.name)), [product.attributes]);
  const showSizeGuide = Boolean(sizeOption) && sizeAttributes.length > 0;

  return <div className="detail-info">
    <p className="eyebrow">{product.categoryName}</p>
    <h1>{product.name}</h1>

    <ul className="detail-meta">
      <li><span>{copy.range}</span>{product.categoryName}</li>
      <li><span>{copy.sku}</span>{product.id}</li>
      {product.vendor && <li><span>{copy.madeBy}</span>{product.vendor.name}</li>}
    </ul>

    <div className="buy-meta">
      {product.reviewCount > 0 && product.rating > 0 && variant !== "split-buybox" && <a className="sf-buy-review-link" href="#reviews-title">{copy.reviewSummary}</a>}
      <p className={`availability-pill${canPurchase ? " is-available" : ""}`} role="status">{availability}</p>
    </div>

    <div className="price-block">
      {flashDeal && !isBooking && <p className="product-badge">⚡ {flashDeal.sale_name} · <FlashCountdown endsAt={flashDeal.ends_at} locale={locale} endedLabel={copy.dealEnded} endsInTemplate={copy.dealEndsIn} />{flashDeal.remaining !== null && ` · ${fill(copy.flashOnlyLeft, { count: flashDeal.remaining })}`}</p>}
      <p className="price" aria-label={copy.priceLabel}>
        {formatMinor(displayPrice, lineCurrency, locale)}
        {flashUnitPrice !== undefined ? <del>{formatMinor(comparePrice ?? unitPrice, lineCurrency, locale)}</del> : onSale && <del>{formatMinor(comparePrice, lineCurrency, locale)}</del>}
        {tier && <small>{fill(copy.perItem, { minimum: tier.minimum })}</small>}
      </p>
    </div>

    {variant !== "split-buybox" && <p className="description">{product.description}</p>}

    {memberRows.length > 0 && <section className="decision-section" aria-labelledby="member-price-heading">
      <h2 id="member-price-heading">{copy.memberTitle}</h2>
      <ul className="tier-prices" aria-label={copy.memberTitle}>{memberRows.map((row) => <li key={row.tier}>{row.tier}: <strong>{formatMinor(row.price_minor, row.currency, locale)}</strong></li>)}</ul>
      <p className="muted" role="note">{copy.memberNote}</p>
    </section>}

    {product.subscriptionPlans && product.subscriptionPlans.length > 0 && <SubscribeAndSave plans={product.subscriptionPlans} adapter={subscriptionAdapter} onNavigate={onSubscribeNavigate} />}

    {storeRows.length > 0 && <section className="decision-section" aria-labelledby="store-stock-heading">
      <h2 id="store-stock-heading">{copy.storeTitle}</h2>
      <ul className="tier-prices" aria-label={copy.storeTitle}>{storeRows.map((row) => <li key={row.src}>{row.src}: <strong>{row.qty > 0 ? fill(copy.storeAvailable, { qty: row.qty }) : full.commerce.outOfStock}</strong></li>)}</ul>
    </section>}

    {isBooking && <section className="decision-section" aria-labelledby="booking-slots-heading">
      <h2 id="booking-slots-heading">{copy.slotTitle}</h2>
      {bookingSlots.length === 0 ? <p className="catalog-capability" role="note">{copy.noSlots}</p> : <div className="booking-slots grid gap-2" role="radiogroup" aria-label={copy.slotsLabel}>
        {bookingSlots.map((slot) => {
          const starts = serverDate(slot.starts_at);
          const ends = serverDate(slot.ends_at);
          const isSelected = slot.starts_at === selectedSlot;
          const price = slot.price_minor != null ? formatMinor(slot.price_minor, slot.currency ?? lineCurrency, locale) : formatMinor(product.price, lineCurrency, locale);
          const remainingText = isSelected && remaining !== null ? ` · ${fill(copy.remainingShort, { count: remaining })}` : "";
          return <label key={slot.id} className={`booking-slot ${isSelected ? "is-selected" : ""}`}><input type="radio" name="booking_slot" value={slot.starts_at} checked={isSelected} onChange={() => setSelectedSlot(slot.starts_at)} aria-label={`${starts.toLocaleString(locale)} to ${ends.toLocaleString(locale)}`} /><span className="booking-slot-main"><strong>{starts.toLocaleDateString(locale, { weekday: "short", month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" })} → {ends.toLocaleTimeString(locale, { hour: "2-digit", minute: "2-digit" })}</strong><span className="muted">{price} · {fill(copy.capacityShort, { count: slot.capacity })}{remainingText}</span></span></label>;
        })}
      </div>}
      {selectedSlotRow && <p className="muted" role="status">{copy.selectedPrefix} {serverDate(selectedSlotRow.starts_at).toLocaleString(locale)} · {formatMinor(unitPrice, lineCurrency, locale)}{remaining !== null ? ` · ${fill(copy.remainingOf, { remaining, capacity: selectedSlotRow.capacity })}` : ` · ${fill(copy.capacityShort, { count: selectedSlotRow.capacity })}`}</p>}
    </section>}

    {product.options && product.options.length > 0 && <section className="decision-section" aria-labelledby="product-options-heading">
      <h2 id="product-options-heading">{copy.optionsTitle}</h2>
      {product.options.map((option) => {
        const useSelect = option.values.length > 8;
        return <fieldset className="sf-option" key={option.name} disabled={unavailable}>
          <legend className="sf-option-legend">{option.name}</legend>
          {useSelect
            ? <label className="option-control">
              <select aria-label={option.name} disabled={unavailable} value={options[option.name] ?? ""} onChange={(event) => setOptions((current) => ({ ...current, [option.name]: event.target.value }))}>
                {option.values.map((value) => { const surcharge = surchargeFor(option.name, value); return <option key={value} value={value}>{value}{surcharge ? ` · +${formatMinor(surcharge.surcharge, surcharge.currency, locale)}` : ""}</option>; })}
              </select>
            </label>
            : <div className="sf-option-values" role="group" aria-label={option.name}>
              {option.values.map((value) => {
                const surcharge = surchargeFor(option.name, value);
                const selected = (options[option.name] ?? "") === value;
                const priceNote = surcharge ? `, +${formatMinor(surcharge.surcharge, surcharge.currency, locale)}` : "";
                return <button key={value} type="button" className={`sf-option-pill${selected ? " is-selected" : ""}`} aria-pressed={selected} aria-label={`${option.name}: ${value}${priceNote}`} disabled={unavailable} onClick={() => setOptions((current) => ({ ...current, [option.name]: value }))}>
                  <span className="sf-option-value">{value}</span>
                  {surcharge && <span className="sf-option-price">+{formatMinor(surcharge.surcharge, surcharge.currency, locale)}</span>}
                </button>;
              })}
            </div>}
        </fieldset>;
      })}
      {unavailable && <p className="catalog-capability" role="note">{copy.contractNote}</p>}
      {showSizeGuide && <div className="sf-size-guide">
        <button type="button" className="sf-size-guide-toggle" aria-expanded={sizeGuideOpen} aria-controls="sf-size-guide-panel" onClick={() => setSizeGuideOpen((open) => !open)}>{copy.sizeGuide}</button>
        {sizeGuideOpen && <div id="sf-size-guide-panel" role="region" aria-label={copy.sizeGuide} className="sf-size-guide-panel">
          <table>
            <caption>{sizeOption?.name}</caption>
            <thead><tr><th scope="col">{sizeOption?.name}</th><th scope="col">{copy.sizeGuideDetails}</th></tr></thead>
            <tbody>{sizeAttributes.map((attribute) => <tr key={`${attribute.name}:${attribute.value}`}><th scope="row">{attribute.name}</th><td>{attribute.value}</td></tr>)}</tbody>
          </table>
        </div>}
      </div>}
    </section>}

    {product.bundleComponents && product.bundleComponents.length > 0 && <section className="bundle-summary" aria-labelledby="bundle-heading">
      <h2 id="bundle-heading">{copy.bundleTitle}</h2>
      <ul>{product.bundleComponents.map((component) => <li key={component.id}><strong>{component.quantity} ×</strong> {component.name}{component.required ? ` · ${copy.required}` : ` · ${copy.optional}`}{component.options?.length ? ` · ${component.options.join(", ")}` : ""}</li>)}</ul>
      <p className="catalog-capability" role="note">{copy.bundleNote}</p>
    </section>}

    {canPurchase && product.productType !== "grouped" && <section className="purchase-controls" aria-labelledby="purchase-heading">
      <h2 id="purchase-heading">{copy.quantityTitle}</h2>
      <div className="quantity-control"><button type="button" aria-label={copy.decreaseQty} onClick={() => setQuantity((value) => Math.max(1, value - 1))} disabled={quantity <= 1}>−</button><input id="product-quantity" aria-label={copy.quantity} type="number" min="1" max={maxQuantity} value={quantity} onChange={(event) => setQuantity(Math.min(maxQuantity, Math.max(1, Number(event.target.value) || 1)))} /><button type="button" aria-label={copy.increaseQty} onClick={() => setQuantity((value) => Math.min(maxQuantity, value + 1))} disabled={quantity >= maxQuantity}>+</button></div>
      {product.quantityPricing && product.quantityPricing.length > 0 && <ul className="tier-prices" aria-label={copy.tierTitle}>{product.quantityPricing.map((tierPrice) => <li key={tierPrice.minimum} aria-current={tier?.minimum === tierPrice.minimum ? "true" : undefined}>{formatMinor(tierPrice.price, tierPrice.currency, locale)} {fill(copy.perItem, { minimum: tierPrice.minimum })}</li>)}</ul>}
      {quantity > 1 && <p className="line-total">{copy.lineTotal} <strong>{formatMinor(displayPrice * quantity, lineCurrency, locale)}</strong></p>}
      {resolved && <p className="muted">{copy.sku}: {resolved.sku || resolved.variantId}</p>}
      <form method="post" action="/cart/add" className="detail-add-form" onSubmit={() => setAdding(true)}>
        <input type="hidden" name="product_id" value={effectiveVariantId} />
        <input type="hidden" name="quantity" value={quantity} />
        {isBooking && selectedSlot && <input type="hidden" name="slot_start" value={selectedSlot} />}
        <button className={adding ? "button detail-add is-loading" : "button detail-add"} type="submit" aria-busy={adding} disabled={adding || (isBooking && !selectedSlot)}>{adding ? copy.addingToBag : product.status === "backorder" ? copy.backorderBtn : copy.addToCart}</button>
      </form>
      <ul className="buy-trust">
        <li>{copy.buyTrust1}</li>
        <li>{copy.buyTrust2}</li>
      </ul>
    </section>}
    {canPurchase && product.productType === "grouped" && product.groupedItems && product.groupedItems.length > 0 && <section className="purchase-controls" aria-labelledby="grouped-heading">
      <h2 id="grouped-heading">{copy.groupedTitle}</h2>
      <ul className="grouped-items">
        {product.groupedItems.map((item) => <li key={item.variantId} className="grouped-item">
          {item.image && <img src={item.image} alt="" width="72" height="72" loading="lazy" decoding="async" />}
          <div className="grouped-item-main"><strong>{item.slug ? <a className="text-link" href={`/product/${item.slug}`}>{item.name}</a> : item.name}</strong><span className="muted">{formatMinor(item.price, item.currency, locale)}</span></div>
          <form method="post" action="/cart/add"><input type="hidden" name="product_id" value={item.variantId} /><input type="hidden" name="quantity" value={quantity} /><button className="button" type="submit" aria-label={`${copy.addToCart}: ${item.name}`} disabled={item.stock !== undefined && item.stock <= 0 && !item.backorderable}>{copy.addToCart}</button></form>
        </li>)}
      </ul>
    </section>}
    {unavailable && <><button className="button" disabled aria-disabled="true">{copy.unavailableBtn}</button><p className="catalog-capability" role="note">{copy.displayOnly}</p></>}
    {soldOut && !unavailable && <><button className="button" disabled aria-disabled="true">{full.commerce.outOfStock}</button><StockAlertSignup productId={product.id} variantId={resolved?.variantId} isPreorder={product.is_preorder === true} priceMinor={product.price} currency={lineCurrency} /></>}
    {variant === "split-buybox" && <ProductInfoAccordion product={product} locale={locale} />}
    <p className="decision-note" role="note">{copy.decisionNote}</p>
    {canPurchase && product.productType !== "grouped" && <div className="mobile-add"><form method="post" action="/cart/add" onSubmit={() => setAdding(true)}>
      <input type="hidden" name="product_id" value={effectiveVariantId} />
      <input type="hidden" name="quantity" value={quantity} />
      {isBooking && selectedSlot && <input type="hidden" name="slot_start" value={selectedSlot} />}
      <button className={adding ? "button is-loading" : "button"} type="submit" aria-busy={adding} disabled={adding || (isBooking && !selectedSlot)} aria-label={copy.stickyAdd}>{adding ? copy.addingToBag : <>{copy.addToCart} · {formatMinor(displayPrice * quantity, lineCurrency, locale)}</>}</button>
    </form></div>}
  </div>;
}
