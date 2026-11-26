import type { Metadata } from "next";
import { PurchaseFlowLazy } from "@/components/PurchaseFlowLazy";
import { loadCartSnapshot } from "@/lib/cart-server";
import { toDisplayCart } from "@/lib/cart-session";
import { requestLocale } from "@/lib/request-locale";
import { getCopy } from "@/lib/i18n";
import { getConfiguredStorefrontConfig } from "@/services/storefront-config";
import { Slot } from "@/slots";
export async function generateMetadata(): Promise<Metadata> { const copy = getCopy(await requestLocale()).purchase; return { title: copy.checkoutPageTitle, description: copy.checkoutPageDesc, robots: { index: false, follow: false } }; }
export default async function CheckoutPage() {
  const copy = getCopy(await requestLocale()).purchase;
  const snapshot = await loadCartSnapshot();
  const config = await getConfiguredStorefrontConfig();
  return <><section className="page-intro"><p className="eyebrow">{copy.checkoutPageEyebrow}</p><h1>{copy.checkoutPageTitle}</h1><p>{copy.checkoutPageCopy}</p></section><PurchaseFlowLazy initialStep="checkout" initialCart={snapshot ? toDisplayCart(snapshot) : undefined} quoteMode={config.purchase.quoteMode} /><Slot name="checkout" /></>;
}
