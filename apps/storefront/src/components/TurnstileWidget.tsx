"use client";

import { useEffect, useRef } from "react";
import { useCopy } from "@/lib/i18n-client";

declare global {
  interface Window {
    turnstile?: {
      render(element: HTMLElement, options: Record<string, unknown>): string;
      remove(widgetId: string): void;
    };
  }
}

const SITE_KEY = process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY ?? "";
const SCRIPT_SRC = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";

let scriptPromise: Promise<void> | null = null;

function loadScript(): Promise<void> {
  if (typeof document === "undefined") return Promise.resolve();
  if (window.turnstile) return Promise.resolve();
  if (!scriptPromise) {
    scriptPromise = new Promise<void>((resolve, reject) => {
      const existing = document.querySelector<HTMLScriptElement>(`script[src="${SCRIPT_SRC}"]`);
      if (existing) {
        existing.addEventListener("load", () => resolve(), { once: true });
        existing.addEventListener("error", () => reject(new Error("turnstile_load_failed")), { once: true });
        return;
      }
      const script = document.createElement("script");
      script.src = SCRIPT_SRC;
      script.async = true;
      script.defer = true;
      script.addEventListener("load", () => resolve(), { once: true });
      script.addEventListener("error", () => reject(new Error("turnstile_load_failed")), { once: true });
      document.head.appendChild(script);
    });
  }
  return scriptPromise;
}

/** Cloudflare Turnstile widget. Renders nothing until NEXT_PUBLIC_TURNSTILE_SITE_KEY is set, so local/dev auth keeps working while production gets bot protection. Tokens are single-use: the parent should remount (key) after a failed submit. */
export function TurnstileWidget({ onToken }: { onToken: (token: string) => void }) {
  const copy = useCopy();
  const hostRef = useRef<HTMLDivElement>(null);
  const callbackRef = useRef(onToken);
  callbackRef.current = onToken;

  useEffect(() => {
    if (!SITE_KEY || !hostRef.current) return;
    let widgetId: string | undefined;
    let cancelled = false;
    void loadScript()
      .then(() => {
        if (cancelled || !hostRef.current || !window.turnstile) return;
        widgetId = window.turnstile.render(hostRef.current, {
          sitekey: SITE_KEY,
          callback: (token: unknown) => callbackRef.current(typeof token === "string" ? token : ""),
          "expired-callback": () => callbackRef.current(""),
          "error-callback": () => callbackRef.current(""),
        });
      })
      .catch(() => undefined);
    return () => {
      cancelled = true;
      try {
        if (widgetId !== undefined) window.turnstile?.remove(widgetId);
      } catch {
        /* Widget teardown is best effort. */
      }
    };
  }, []);

  if (!SITE_KEY) return null;
  return <div ref={hostRef} className="turnstile-widget" aria-label={copy.auth.humanVerification} />;
}

export function turnstileConfigured(): boolean {
  return SITE_KEY !== "";
}
