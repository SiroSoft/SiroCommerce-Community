import type { Metadata } from "next";
import { Suspense } from "react";
import { Breadcrumbs } from "@/components/Breadcrumbs";
import { VerifyEmailForm } from "@/components/AuthRecoveryForms";
import { requestLocale } from "@/lib/request-locale";
import { getCopy } from "@/lib/i18n";

export async function generateMetadata(): Promise<Metadata> { const copy = getCopy(await requestLocale()).auth; return { title: copy.verifyTitle, description: copy.verifyTitle, robots: { index: false, follow: false } }; }

export default async function VerifyEmailPage() {
  const locale = await requestLocale();
  const copy = getCopy(locale).auth;
  return <><Breadcrumbs current={copy.verifyTitle} locale={locale} /><section className="page-intro"><p className="eyebrow">{copy.verifyTitle}</p><h1>{copy.verifyTitle}</h1></section><div className="auth-layout"><Suspense><VerifyEmailForm /></Suspense></div></>;
}
