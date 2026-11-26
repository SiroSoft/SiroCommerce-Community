"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { createCommerceScope } from "@sirocommerce/scope";
import { ApiPurchaseAdapter } from "@/purchase/api-adapter";
import { FixturePurchaseAdapter } from "@/purchase/fixture-adapter";
import { PurchaseService } from "@/purchase/service";
import { PurchaseError } from "@/purchase/contracts";
import { TurnstileWidget } from "@/components/TurnstileWidget";
import { useCopy } from "@/lib/i18n-client";

const liveMode = process.env.NEXT_PUBLIC_STOREFRONT_PURCHASE_SOURCE === "api";

function service() {
  const operations = liveMode
    ? new ApiPurchaseAdapter({
        baseUrl: process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "http://127.0.0.1:1",
        scope: createCommerceScope({
          store_id: process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? "default",
          channel_id: process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? "web",
          locale: process.env.NEXT_PUBLIC_STOREFRONT_LOCALE ?? "en",
          currency: process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? "USD",
        }),
      })
    : new FixturePurchaseAdapter();
  return new PurchaseService(operations);
}

function failureMessage(value: unknown, fallback: string): string {
  return value instanceof PurchaseError ? value.message : value instanceof Error ? value.message : fallback;
}

export function ForgotPasswordForm() {
  const copy = useCopy().auth;
  const [email, setEmail] = useState("");
  const [turnstileToken, setTurnstileToken] = useState("");
  const [turnstileKey, setTurnstileKey] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState("");
  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (submitting) return;
    setError("");
    setSubmitting(true);
    try {
      await service().requestPasswordReset(email, turnstileToken || undefined);
      setSent(true);
    } catch (value) {
      setTurnstileKey((key) => key + 1);
      setTurnstileToken("");
      // The endpoint answers identically whether the account exists; only
      // validation/rate-limit failures surface a message.
      if (value instanceof PurchaseError && (value.status === 422 || value.status === 429)) setError(value.message);
      else setSent(true);
    } finally {
      setSubmitting(false);
    }
  }
  if (sent) return <p className="form-notice" role="status">{copy.resetSent}</p>;
  return <form className="auth-form" onSubmit={(event) => void submit(event)}>
    <p>{copy.forgotCopy}</p>
    <label htmlFor="forgot-email">{copy.email}<input id="forgot-email" value={email} onChange={(event) => setEmail(event.target.value)} type="email" autoComplete="email" required maxLength={160} /></label>
    <TurnstileWidget key={turnstileKey} onToken={setTurnstileToken} />
    {error && <p className="form-error" role="alert">{error}</p>}
    <button className={submitting ? "button is-loading" : "button"} type="submit" aria-busy={submitting} disabled={submitting}>{submitting ? copy.creating : copy.sendResetLink}</button>
    <p className="muted"><Link className="text-link" href="/account">{copy.backToSignIn}</Link></p>
  </form>;
}

export function ResetPasswordForm() {
  const copy = useCopy().auth;
  const params = useSearchParams();
  const token = params.get("token") ?? "";
  const [password, setPassword] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState("");
  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (submitting) return;
    setError("");
    setSubmitting(true);
    try {
      await service().resetPassword(token, password);
      setDone(true);
    } catch (value) {
      setError(failureMessage(value, copy.resetInvalid));
    } finally {
      setSubmitting(false);
    }
  }
  if (!token) return <p className="form-error" role="alert">{copy.resetInvalid}</p>;
  if (done) return <><p className="form-notice" role="status">{copy.resetDone}</p><p><Link className="text-link" href="/account">{copy.backToSignIn}</Link></p></>;
  return <form className="auth-form" onSubmit={(event) => void submit(event)}>
    <p>{copy.resetCopy}</p>
    <label htmlFor="reset-password">{copy.newPassword}<input id="reset-password" value={password} onChange={(event) => setPassword(event.target.value)} type="password" autoComplete="new-password" required minLength={8} maxLength={200} /></label>
    {error && <p className="form-error" role="alert">{error}</p>}
    <button className={submitting ? "button is-loading" : "button"} type="submit" aria-busy={submitting} disabled={submitting}>{submitting ? copy.creating : copy.resetButton}</button>
  </form>;
}

export function VerifyEmailForm() {
  const copy = useCopy().auth;
  const params = useSearchParams();
  const token = params.get("token") ?? "";
  const [state, setState] = useState<"pending" | "done" | "failed">("pending");
  useEffect(() => {
    if (!token) return;
    let active = true;
    void service()
      .verifyEmail(token)
      .then(() => { if (active) setState("done"); })
      .catch(() => { if (active) setState("failed"); });
    return () => { active = false; };
  }, [token]);
  if (!token) return <p className="form-error" role="alert">{copy.verifyInvalid}</p>;
  if (state === "pending") return <p className="muted" role="status">{copy.verifying}</p>;
  if (state === "done") return <><p className="form-notice" role="status">{copy.verifyDone}</p><p><Link className="text-link" href="/account">{copy.backToSignIn}</Link></p></>;
  return <p className="form-error" role="alert">{copy.verifyFailed}</p>;
}
