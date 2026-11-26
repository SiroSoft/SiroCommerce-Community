"use client";

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import type { Product } from "@/types";
import { fill } from "@/lib/i18n";
import { useCopy } from "@/lib/i18n-client";
import { hasStoredSession } from "@/lib/session-hint";
import { formatMinor } from "@/lib/money";
import { storefrontLocale } from "@/lib/locale";
import { customer, type Address, type CustomerReturn, type Loyalty as CustomerLoyalty, type NotificationPreferences } from "@/services/customer";
import { useWishlist } from "@/components/wishlist-store";

export { WishlistButton } from "./WishlistButton";

type LoyaltyState = { balance: number; earned: number; redeemed: number };
const initialLoyalty: LoyaltyState = { balance: 240, earned: 0, redeemed: 0 };

export function BuyerEngagement() {
  return <div className="engagement-stack">
      <AccountPreferences />
  </div>;
}

export function ReviewSummary({ product }: { product: Product }) {
  const copy = useCopy();
  const [reviews, setReviews] = useState<{ rating: number; body: string }[]>([]);
  const [reviewState, setReviewState] = useState<"loading" | "ready" | "unavailable">("loading");
  useEffect(() => { let active = true; customer().getReviews(product.id).then((value) => { if (active) { setReviews(value); setReviewState("ready"); } }).catch(() => { if (active) setReviewState("unavailable"); }); return () => { active = false; }; }, [product.id]);
  if (product.reviewCount <= 0 || product.rating <= 0) return null;
  const roundedRating = Math.round(product.rating);
  return <section className="review-panel" aria-labelledby="reviews-title"><div className="account-section-heading"><p className="eyebrow">{copy.reviews.eyebrow}</p><h2 id="reviews-title">{copy.reviews.title}</h2></div><div className="rating-summary"><p className="rating-score">{product.rating.toFixed(1)}</p><div><span className="review-stars" aria-label={fill(copy.product.ratingOutOf, { rating: product.rating.toFixed(1) })}>{ "★".repeat(roundedRating)}{"☆".repeat(Math.max(0, 5 - roundedRating))}</span><p className="rating-caption">{copy.reviews.averageFrom} {product.reviewCount} {product.reviewCount === 1 ? copy.product.review : copy.product.reviews}</p></div></div>{reviewState === "loading" && <p className="muted" role="status">{copy.reviews.loading}</p>}{reviewState === "unavailable" && <p className="account-unavailable" role="status">{copy.reviews.unavailable}</p>}{reviewState === "ready" && reviews.length === 0 && <p className="muted">{copy.reviews.none}</p>}{reviews.map((review, index) => <p key={index}><strong>{review.rating}/5</strong> {review.body}</p>)}</section>;
}


export function AccountPreferences() {
  const copy = useCopy();
  const [addresses, setAddresses] = useState<Address[]>([]);
  const [preferences, setPreferences] = useState<NotificationPreferences>();
  const [state, setState] = useState<"loading" | "ready" | "unavailable">("loading");
  const [addressForm, setAddressForm] = useState({ line1: "", city: "", phone: "" });
  const [addressMessage, setAddressMessage] = useState("");
  useEffect(() => {
    let active = true;
    let settled = false;
    const load = () => {
      if (!active || settled) return;
      settled = true;
      setState("loading");
      Promise.all([customer().getAddresses(), customer().getNotificationPreferences()]).then(([nextAddresses, nextPreferences]) => { if (!active) return; setAddresses(nextAddresses); setPreferences(nextPreferences); setState("ready"); }).catch(() => { if (active) setState("unavailable"); });
    };
    // Without any stored session a request is doomed to 401. A reload
    // restores the session asynchronously, and a same-page sign-in can arrive
    // at any time — wait for the session event instead of erroring noisily,
    // and show the signed-out state only when no session can be coming.
    if (!hasStoredSession()) setState("unavailable");
    const fallback = hasStoredSession() ? window.setTimeout(load, 2500) : undefined;
    window.addEventListener("siro:session-updated", load);
    return () => { active = false; settled = true; if (fallback !== undefined) window.clearTimeout(fallback); window.removeEventListener("siro:session-updated", load); };
  }, []);
  async function toggleMarketing() { if (!preferences) return; try { setPreferences(await customer().updateNotificationPreferences({ marketing_email: !preferences.marketing_email })); } catch { setState("unavailable"); } }
  async function addAddress(event: FormEvent) { event.preventDefault(); setAddressMessage(""); try { const address = await customer().addAddress(addressForm); setAddresses((current) => [...current, address]); setAddressForm({ line1: "", city: "", phone: "" }); setAddressMessage(copy.prefs.saved); } catch { setAddressMessage(copy.prefs.saveFailed); } }
  return <section className="account-panel" id="settings" aria-labelledby="preferences-title"><div className="account-section-heading"><h2 id="preferences-title">{copy.prefs.title}</h2><span className="muted">{copy.prefs.settings}</span></div>{state === "loading" && <p className="muted">{copy.prefs.loading}</p>}{state === "unavailable" && <p className="account-unavailable" role="note">{copy.prefs.unavailable}</p>}{state === "ready" && <><p>{addresses.length ? fill(copy.prefs[addresses.length === 1 ? "savedAddress" : "savedAddresses"], { count: addresses.length }) : copy.prefs.noAddresses}</p>{addresses.map((address) => <p key={String(address.id)} className="muted">{address.label}: {address.line1}, {address.city} · {address.phone}</p>)}<form onSubmit={addAddress}><label htmlFor="account-address-line1">{copy.prefs.street}<input id="account-address-line1" value={addressForm.line1} onChange={(event) => setAddressForm({ ...addressForm, line1: event.target.value })} required /></label><label htmlFor="account-address-city">{copy.prefs.city}<input id="account-address-city" value={addressForm.city} onChange={(event) => setAddressForm({ ...addressForm, city: event.target.value })} required /></label><label htmlFor="account-address-phone">{copy.prefs.phone}<input id="account-address-phone" value={addressForm.phone} onChange={(event) => setAddressForm({ ...addressForm, phone: event.target.value })} required /></label><button className="text-button" type="submit">{copy.prefs.save}</button>{addressMessage && <p className="form-notice" role="status">{addressMessage}</p>}</form>{preferences && <label className="preference-toggle"><input type="checkbox" checked={preferences.marketing_email} onChange={() => void toggleMarketing()} /> {copy.prefs.marketing}</label>}<p className="muted">{copy.prefs.transactional}</p></>}</section>;
}

export function WishlistPreview({ products }: { products: Product[] }) {
  const copy = useCopy();
  const wishlist = useWishlist();
  const items = products.filter((product) => wishlist.isSaved(product));
  return <section className="account-panel sf-wish-panel" aria-labelledby="saved-title"><div className="account-section-heading"><h2 id="saved-title">{copy.wishlistPanel.savedLater}</h2><span className="muted">{items.length} {copy.wishlistPanel.items}</span></div>{items.length ? items.map((item) => <p key={item.slug}><a className="text-link" href={`/product/${item.slug}`}>{item.name}</a> · {formatMinor(item.price, item.currency, storefrontLocale())}</p>) : <p className="muted sf-wish-status">{copy.wishlistPanel.emptySession}</p>}</section>;
}
