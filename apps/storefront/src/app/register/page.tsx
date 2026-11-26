import type { Metadata } from "next";
import Link from "next/link";
import { Breadcrumbs } from "@/components/Breadcrumbs";
import { RegisterForm } from "@/components/RegisterForm";
import { requestLocale } from "@/lib/request-locale";
import { getCopy } from "@/lib/i18n";

export async function generateMetadata(): Promise<Metadata> { const copy = getCopy(await requestLocale()).auth; return { title: copy.registerTitle, description: copy.registerDesc, robots: { index: false, follow: false } }; }

export default async function RegisterPage() {
  const locale = await requestLocale();
  const copy = getCopy(locale).auth;
  return <><Breadcrumbs current={copy.registerTitle} locale={locale} /><section className="page-intro"><p className="eyebrow">{copy.joinEyebrow}</p><h1>{copy.registerTitle}</h1><p>{copy.registerCopy}</p></section><div className="auth-layout"><RegisterForm /><aside className="auth-aside" aria-label={copy.benefitsTitle}><h2>{copy.benefitsTitle}</h2><ul><li>{copy.benefit1}</li><li>{copy.benefit2}</li><li>{copy.benefit3}</li></ul><p className="muted">{copy.haveAccount} <Link className="text-link" href="/account">{copy.signIn}</Link></p></aside></div></>;
}
