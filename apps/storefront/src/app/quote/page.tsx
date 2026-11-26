import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { PurchaseFlowLazy } from "@/components/PurchaseFlowLazy";
import { loadCartSnapshot } from "@/lib/cart-server";
import { toDisplayCart } from "@/lib/cart-session";
import { requestLocale } from "@/lib/request-locale";
import { getCopy } from "@/lib/i18n";
import { getConfiguredStorefrontConfig } from "@/services/storefront-config";
const supportEmail = (process.env.NEXT_PUBLIC_STOREFRONT_SUPPORT_EMAIL ?? "").trim();
export async function generateMetadata(): Promise<Metadata> { const copy = getCopy(await requestLocale()).purchase; return { title: copy.quotePageTitle, robots: { index: false, follow: false } }; }
export default async function QuotePage() {
  const copy = getCopy(await requestLocale()).purchase;
  const config = await getConfiguredStorefrontConfig();
  if (config.purchase.quoteMode !== "required") redirect("/checkout");
  const snapshot = await loadCartSnapshot();
  return <>
    <section className="page-intro"><p className="eyebrow">{copy.quotePageEyebrow}</p><h1>{copy.quotePageTitle}</h1><p>{copy.quotePageCopy}</p></section>
    <PurchaseFlowLazy initialStep="quote" initialCart={snapshot ? toDisplayCart(snapshot) : undefined} quoteMode={config.purchase.quoteMode} />
    <p className="muted">{copy.quoteHelp}{supportEmail ? <> <a className="text-link" href={`mailto:${supportEmail}?subject=Shipping%20quote`}>{copy.contactSupport}</a>.</> : null}</p>
  </>;
}
