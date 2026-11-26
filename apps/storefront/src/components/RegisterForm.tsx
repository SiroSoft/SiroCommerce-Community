"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { createCommerceScope } from "@sirocommerce/scope";
import { browserAuthStorage, isValidJwt } from "@sirocommerce/auth";
import { ApiPurchaseAdapter } from "@/purchase/api-adapter";
import { FixturePurchaseAdapter } from "@/purchase/fixture-adapter";
import { PurchaseService } from "@/purchase/service";
import { PurchaseError } from "@/purchase/contracts";
import { TurnstileWidget } from "@/components/TurnstileWidget";
import { useCopy } from "@/lib/i18n-client";

const liveMode = process.env.NEXT_PUBLIC_STOREFRONT_PURCHASE_SOURCE === "api";

function adapter() {
  if (!liveMode) return new FixturePurchaseAdapter();
  return new ApiPurchaseAdapter({
    baseUrl: process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "http://127.0.0.1:1",
    scope: createCommerceScope({
      store_id: process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? "default",
      channel_id: process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? "web",
      locale: process.env.NEXT_PUBLIC_STOREFRONT_LOCALE ?? "en",
      currency: process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? "USD",
    }),
  });
}

export function RegisterForm() {
  const copy = useCopy().auth;
  const router = useRouter();
  const [authStorage] = useState(() => browserAuthStorage("siro_storefront_", { validateAccessToken: (token: string) => isValidJwt(token) || (!liveMode && (/^fixture-/.test(token) || token === "customer-token-fixture")) }));
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [turnstileToken, setTurnstileToken] = useState("");
  const [turnstileKey, setTurnstileKey] = useState(0);
  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (submitting) return;
    setError("");
    setSubmitting(true);
    try {
      const service = new PurchaseService(adapter());
      const session = await service.register({ name, email, password, ...(turnstileToken ? { turnstileToken } : {}) });
      try {
        if (session.refresh_token) authStorage.setTokens({ token: session.access_token, refresh_token: session.refresh_token });
        else authStorage.setAccessToken(session.access_token);
      } catch { authStorage.setAccessToken(session.access_token); }
      router.push("/account");
    } catch (value) {
      setTurnstileKey((key) => key + 1);
      setTurnstileToken("");
      setError(value instanceof PurchaseError ? value.message : value instanceof Error ? value.message : "We could not create your account.");
    } finally {
      setSubmitting(false);
    }
  }
  return <form className="auth-form" onSubmit={(event) => void submit(event)}>
    <p>{copy.formIntro}</p>
    <label htmlFor="register-name">{copy.fullName}<input id="register-name" value={name} onChange={(event) => setName(event.target.value)} autoComplete="name" required minLength={2} maxLength={120} /></label>
    <label htmlFor="register-email">{copy.email}<input id="register-email" value={email} onChange={(event) => setEmail(event.target.value)} type="email" autoComplete="email" required maxLength={160} /></label>
    <label htmlFor="register-password">{copy.password}<input id="register-password" value={password} onChange={(event) => setPassword(event.target.value)} type="password" autoComplete="new-password" required minLength={8} maxLength={200} aria-describedby="register-password-help" /></label>
    <p className="muted" id="register-password-help">{copy.passwordHelp}</p>
    <TurnstileWidget key={turnstileKey} onToken={setTurnstileToken} />
    {error && <p className="form-error" role="alert">{error}</p>}
    <button className={submitting ? "button is-loading" : "button"} type="submit" aria-busy={submitting} disabled={submitting}>{submitting ? copy.creating : copy.createButton}</button>
    <p className="muted">{copy.haveAccount} <Link className="text-link" href="/account">{copy.signIn}</Link></p>
  </form>;
}
