"use client";

import { createContext, useContext, useState, useCallback, useMemo, useEffect } from "react";
import en from "@/locales/en";
import vi from "@/locales/vi";
import de from "@/locales/de";
import zh from "@/locales/zh";
import ja from "@/locales/ja";
import type { Locale } from "@/locales/en";

type NestedKeyOf<T> = T extends Record<string, unknown>
  ? { [K in keyof T]: K extends string ? `${K}${T[K] extends Record<string, unknown> ? `.${NestedKeyOf<T[K]>}` : ""}` : never }[keyof T]
  : "";

export type TranslationKey = NestedKeyOf<Locale>;

const LOCALE_NAMES = ["en", "vi", "de", "zh", "ja"] as const;
type LocaleName = (typeof LOCALE_NAMES)[number];

function isLocale(value: string | null): value is LocaleName {
  return value !== null && (LOCALE_NAMES as readonly string[]).includes(value);
}

function readLocaleCookie(): LocaleName | null {
  if (typeof document === "undefined") return null;
  const entry = document.cookie.split("; ").find((item) => item.startsWith("siro_locale="));
  const value = entry?.slice("siro_locale=".length) ?? null;
  return isLocale(value) ? value : null;
}

interface I18nContextValue {
  locale: LocaleName;
  setLocale: (locale: LocaleName) => void;
  t: (key: TranslationKey, params?: Record<string, string | number>) => string;
}

const I18nContext = createContext<I18nContextValue | null>(null);

const locales: Record<LocaleName, Locale> = { en, vi, de, zh, ja };

function getValue(obj: Record<string, unknown>, path: string): string {
  const keys = path.split(".");
  let current: unknown = obj;
  for (const key of keys) {
    if (current == null || typeof current !== "object") return path;
    current = (current as Record<string, unknown>)[key];
  }
  return typeof current === "string" ? current : path;
}

export function I18nProvider({ children }: { children: React.ReactNode }) {
  const [locale, setLocaleState] = useState<LocaleName>("en");

  useEffect(() => {
    const saved = localStorage.getItem("siro_locale");
    const next = isLocale(saved) ? saved : readLocaleCookie();
    if (next) {
      setLocaleState(next);
      document.documentElement.lang = next;
    }
  }, []);

  const setLocale = useCallback((l: LocaleName) => {
    setLocaleState(l);
    localStorage.setItem("siro_locale", l);
    document.cookie = `siro_locale=${l}; Path=/; Max-Age=31536000; SameSite=Lax`;
    document.documentElement.lang = l;
  }, []);

  const t = useCallback(
    (key: TranslationKey, params?: Record<string, string | number>): string => {
      const raw = getValue(locales[locale] as unknown as Record<string, unknown>, key);
      if (!params) return raw;
      return raw.replace(/\{\{(\w+)\}\}/g, (_, p: string) => String(params[p] ?? ""));
    },
    [locale],
  );

  const value = useMemo(() => ({ locale, setLocale, t }), [locale, setLocale, t]);

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n() {
  const ctx = useContext(I18nContext);
  if (!ctx) throw new Error("useI18n must be used within an I18nProvider");
  return ctx;
}
