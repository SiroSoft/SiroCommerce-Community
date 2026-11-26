import type { Metadata } from "next";
import { PurchaseFlow } from "@/components/PurchaseFlow";
import { FinancialDocumentsPanel } from "@/components/FinancialDocumentsPanel";
import { requestLocale } from "@/lib/request-locale";
import { getCopy } from "@/lib/i18n";
import { Slot } from "@/slots";
export async function generateMetadata(): Promise<Metadata> { const copy = getCopy(await requestLocale()).purchase; return { title: copy.resultPageTitle, description: copy.resultPageDesc, robots: { index: false, follow: false } }; }
export default async function OrderResultPage() { const locale = await requestLocale(); const copy = getCopy(locale).purchase; return <><section className="page-intro"><p className="eyebrow">{copy.resultPageEyebrow}</p><h1>{copy.resultPageTitle}</h1><p>{copy.resultPageCopy}</p></section><PurchaseFlow initialStep="order-result" /><div className="flow-tail"><FinancialDocumentsPanel /><Slot name="order-status" locale={locale} /><p className="muted">{copy.needHelp} <a className="text-link" href="mailto:hello@sirocommerce.test?subject=Order%20support">{copy.contactSupport}</a> {copy.resultTail} {copy.neverShare}</p></div></>; }
