import type { Metadata } from "next";
import { Breadcrumbs } from "@/components/Breadcrumbs";
import { ForgotPasswordForm } from "@/components/AuthRecoveryForms";
import { requestLocale } from "@/lib/request-locale";
import { getCopy } from "@/lib/i18n";

export async function generateMetadata(): Promise<Metadata> { const copy = getCopy(await requestLocale()).auth; return { title: copy.forgotTitle, description: copy.forgotCopy, robots: { index: false, follow: false } }; }

export default async function ForgotPasswordPage() {
  const locale = await requestLocale();
  const copy = getCopy(locale).auth;
  return <><Breadcrumbs current={copy.forgotTitle} locale={locale} /><section className="page-intro"><p className="eyebrow">{copy.forgotTitle}</p><h1>{copy.forgotTitle}</h1><p>{copy.forgotCopy}</p></section><div className="auth-layout"><ForgotPasswordForm /></div></>;
}
