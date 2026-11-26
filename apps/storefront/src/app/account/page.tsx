import type { Metadata } from "next";
import { PurchaseFlow } from "@/components/PurchaseFlow";
import { BuyerEngagement } from "@/components/BuyerEngagement";
import { AccountPrivacyControls } from "@/components/AccountPrivacyControls";
import { MessageHistory } from "@/components/MessageHistory";
import { SubscriptionExperience } from "@/components/SubscriptionExperience";
import { requestLocale } from "@/lib/request-locale";
import { getCopy } from "@/lib/i18n";
import { getConfiguredStorefrontConfig } from "@/services/storefront-config";
import { Slot } from "@/slots";
export async function generateMetadata(): Promise<Metadata> {
  const copy = getCopy(await requestLocale()).account;
  return { title: copy.pageTitle, robots: { index: false, follow: false } };
}

export default async function AccountPage() {
  const locale = await requestLocale();
  const copy = getCopy(locale).account;
  const config = await getConfiguredStorefrontConfig();
  const quoteEnabled = config.purchase.quoteMode === "required";
  return <>
    <section className="page-intro account-intro">
      <p className="eyebrow">{copy.buyerSpace}</p>
      <h1>{copy.pageTitle}</h1>
      <p>{copy.accountIntro}</p>
      <p className="muted">{copy.accountNote}</p>
    </section>
    <div className="account-layout">
      <nav className="account-sidebar" aria-label={copy.dashboard}>
        <a href="#overview">{copy.navOverview}</a>
        <a href="#orders">{copy.navOrders}</a>
        <a href="#subscriptions">{copy.navSubscriptions}</a>
        <a href="#messages">{copy.navMessages}</a>
        <a href="#engagement">{copy.navEngagement}</a>
        <a href="#privacy">{copy.navPrivacy}</a>
      </nav>
      <div className="account-main">
        <div id="overview"><PurchaseFlow initialStep="account" /></div>
        <div id="subscriptions"><SubscriptionExperience /></div>
        <section id="orders" className="account-help" aria-labelledby="orders-help-title">
          <p className="eyebrow">{copy.ordersHelpEyebrow}</p>
          <h2 id="orders-help-title">{copy.ordersHelpTitle}</h2>
          <p>{copy.ordersHelpCopy} <a className="text-link" href="mailto:hello@sirocommerce.test?subject=Order%20support">{copy.ordersHelpContact}</a>{quoteEnabled ? <>{copy.ordersHelpMiddle}<a className="text-link" href="/quote">{copy.ordersHelpQuote}</a></> : null}{copy.ordersHelpEnd}</p>
        </section>
        <MessageHistory />
        <div id="engagement"><BuyerEngagement /></div>
        <AccountPrivacyControls />
        <Slot name="my-account" locale={locale} />
      </div>
    </div>
  </>;
}
