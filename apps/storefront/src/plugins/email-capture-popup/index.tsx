"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { LiveRegion, Modal } from "@/components/ui-primitives";

export const EMAIL_CAPTURE_POPUP_ID = "siro/email-capture-popup";
export const EMAIL_CAPTURE_POPUP_SLOT = "footer" as const;

const SHOWN_KEY = "siro-email-capture-popup:shown";
const SUBSCRIBED_KEY = "siro-email-capture-popup:subscribed";
const SESSION_KEY = "siro-session-id";

interface PopupConfig {
  active: boolean;
  delay_ms: number;
  coupon_enabled: boolean;
  coupon_percent: number;
}

function readConfig(value: unknown): PopupConfig | null {
  if (typeof value !== "object" || value === null || Array.isArray(value)) return null;
  const record = value as Record<string, unknown>;
  const data = (typeof record.data === "object" && record.data !== null && !Array.isArray(record.data)
    ? record.data
    : record) as Record<string, unknown>;
  const delay = typeof data.delay_ms === "number" ? data.delay_ms : 8000;
  return {
    active: data.active === true,
    delay_ms: Number.isFinite(delay) && delay >= 0 ? delay : 8000,
    coupon_enabled: data.coupon_enabled === true,
    coupon_percent: typeof data.coupon_percent === "number" ? data.coupon_percent : 10,
  };
}

function apiBase(): string {
  return (process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "").replace(/\/+$/, "");
}

function scopeHeaders(): HeadersInit {
  return {
    Accept: "application/json",
    "X-Store-Id": process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? "default",
    "X-Channel-Id": process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? "web",
    "X-Locale": process.env.NEXT_PUBLIC_STOREFRONT_LOCALE ?? "en",
    "X-Currency": process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? "VND",
  };
}

function sessionId(): string {
  try {
    const existing = window.sessionStorage.getItem(SESSION_KEY);
    if (existing) return existing;
    const next = `sess-${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`;
    window.sessionStorage.setItem(SESSION_KEY, next);
    return next;
  } catch {
    return `sess-${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`;
  }
}

function randomKey(prefix: string): string {
  return `${prefix}-${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`;
}

/**
 * Email capture popup (footer slot — renders on every page via the layout).
 *
 * Dialog behaviour (focus trap, ESC + overlay close, body scroll lock,
 * focus return) comes from the shared `Modal` primitive
 * (`@/components/ui-primitives`); form feedback is announced through
 * `LiveRegion`. No modal/focus-trap code lives in this plugin.
 *
 * Behaviour: fetches the public extension config on the client and renders
 * nothing until the trigger fires (time delay, exit-intent, or 50% scroll),
 * at most once per session. The popup never renders server-side and never
 * shifts layout (fixed overlay via a portal), so it does not touch LCP.
 * Subscribing posts the opt-in to core POST /api/consent/marketing first,
 * then to the extension subscribe endpoint; the welcome coupon is issued
 * server-side only when consent is verified.
 */
export function EmailCapturePopup() {
  const [config, setConfig] = useState<PopupConfig | null>(null);
  const [open, setOpen] = useState(false);
  const [email, setEmail] = useState("");
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [coupon, setCoupon] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  const openedRef = useRef(false);

  useEffect(() => {
    const base = apiBase();
    if (base === "") return;
    let cancelled = false;
    try {
      if (window.sessionStorage.getItem(SUBSCRIBED_KEY)) return;
    } catch {
      // Storage unavailable — still show the popup; submission still works.
    }
    const controller = new AbortController();
    void (async () => {
      try {
        const response = await fetch(`${base}/api/ext/siro/email-capture-popup/public`, {
          cache: "no-store",
          headers: scopeHeaders(),
          signal: controller.signal,
        });
        if (!response.ok) return;
        const parsed = readConfig(await response.json());
        if (!cancelled && parsed?.active) setConfig(parsed);
      } catch {
        if (!cancelled) setConfig(null);
      }
    })();
    return () => {
      cancelled = true;
      controller.abort();
    };
  }, []);

  const show = useCallback(() => {
    if (openedRef.current) return;
    openedRef.current = true;
    try {
      if (window.sessionStorage.getItem(SHOWN_KEY)) return;
      window.sessionStorage.setItem(SHOWN_KEY, "1");
    } catch {
      // Ignore storage failures; the popup still shows once per mount.
    }
    setOpen(true);
  }, []);

  useEffect(() => {
    if (config === null) return;
    const timer = window.setTimeout(show, config.delay_ms);
    const onExitIntent = (event: MouseEvent) => {
      if (event.clientY <= 0 && !event.relatedTarget) show();
    };
    const onScroll = () => {
      const total = document.documentElement.scrollHeight - window.innerHeight;
      if (total > 0 && window.scrollY / total >= 0.5) show();
    };
    document.addEventListener("mouseout", onExitIntent);
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => {
      window.clearTimeout(timer);
      document.removeEventListener("mouseout", onExitIntent);
      window.removeEventListener("scroll", onScroll);
    };
  }, [config, show]);

  const close = useCallback(() => {
    setOpen(false);
  }, []);

  async function submit(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    const trimmed = email.trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) {
      setError("Please enter a valid email address.");
      return;
    }
    setError("");
    setSubmitting(true);
    try {
      const base = apiBase();
      const sid = sessionId();
      const consent = await fetch(`${base}/api/consent/marketing`, {
        method: "POST",
        headers: { ...scopeHeaders(), "Content-Type": "application/json", "X-Session-Id": sid, "Idempotency-Key": randomKey("consent") },
        body: JSON.stringify({ marketing: true, session_id: sid, email: trimmed }),
      });
      if (!consent.ok) throw new Error("consent");
      const response = await fetch(`${base}/api/ext/siro/email-capture-popup/subscribe`, {
        method: "POST",
        headers: { ...scopeHeaders(), "Content-Type": "application/json", "X-Session-Id": sid, "Idempotency-Key": randomKey("popup") },
        body: JSON.stringify({ email: trimmed, source: "popup", session_id: sid }),
      });
      if (!response.ok) throw new Error("subscribe");
      const payload = (await response.json()) as { data?: { coupon_code?: string } };
      const code = typeof payload.data?.coupon_code === "string" && payload.data.coupon_code !== "" ? payload.data.coupon_code : null;
      setCoupon(code);
      setDone(true);
      try {
        window.sessionStorage.setItem(SUBSCRIBED_KEY, "1");
      } catch {
        // Ignore storage failures.
      }
    } catch {
      setError("Something went wrong. Please try again.");
    } finally {
      setSubmitting(false);
    }
  }

  if (config === null) return null;

  return (
    <Modal
      open={open}
      onClose={close}
      title={done ? "You're in!" : `Get ${config.coupon_percent}% off your first order`}
      description={
        done
          ? "Thanks for subscribing. Check your inbox for your welcome offer."
          : "Join our list and we'll send your welcome coupon straight to your inbox."
      }
    >
      {done ? (
        <div>
          <LiveRegion
            message={
              coupon
                ? `You're subscribed. Your welcome coupon is ${coupon}.`
                : "You're subscribed. Check your inbox for your welcome offer."
            }
          />
          {coupon ? (
            <p>
              Your welcome coupon: <strong data-testid="email-capture-popup-coupon">{coupon}</strong>
            </p>
          ) : null}
          <button type="button" onClick={close}>
            Continue shopping
          </button>
        </div>
      ) : (
        <form noValidate onSubmit={(event) => void submit(event)}>
          <label htmlFor="email-capture-popup-email">Email address</label>
          <input
            id="email-capture-popup-email"
            name="email"
            type="email"
            autoComplete="email"
            required
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            data-testid="email-capture-popup-email"
          />
          <LiveRegion message={error} politeness="assertive" visible />
          <button type="submit" disabled={submitting}>
            {submitting ? "Subscribing…" : "Claim my discount"}
          </button>
          <p>We only send marketing email with your consent. Unsubscribe anytime.</p>
        </form>
      )}
    </Modal>
  );
}
