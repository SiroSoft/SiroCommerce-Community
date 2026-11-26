import type { Metadata } from "next";
import { Suspense } from "react";
import { Breadcrumbs } from "@/components/Breadcrumbs";
import { ResetPasswordForm } from "@/components/AuthRecoveryForms";
import { requestLocale } from "@/lib/request-locale";
import { getCopy } from "@/lib/i18n";

export async function generateMetadata(): Promise<Metadata> { const copy = getCopy(await requestLocale()).auth; return { title: copy.resetTitle, description: copy.resetCopy, robots: { index: false, follow: false } }; }

export default async function ResetPasswordPage() {
  const locale = await requestLocale();
  const copy = getCopy(locale).auth;
  return <><Breadcrumbs current={copy.resetTitle} locale={locale} /><section className="page-intro"><p className="eyebrow">{copy.resetTitle}</p><h1>{copy.resetTitle}</h1><p>{copy.resetCopy}</p></section><div className="auth-layout"><Suspense><ResetPasswordForm /></Suspense></div></>;
}
