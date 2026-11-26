import en from "@/locales/en/common.json";
import vi from "@/locales/vi/common.json";
import ja from "@/locales/ja/common.json";
import de from "@/locales/de/common.json";
import zh from "@/locales/zh/common.json";
import { storefrontLocale } from "@/lib/locale";
import type { StorefrontLocale } from "@/lib/locale";
export type Locale = StorefrontLocale;

const translations = { en, vi, ja, de, zh } as const;
export type StorefrontCopy = (typeof translations)["en"];

export function getCopy(locale: Locale = storefrontLocale()) {
  return translations[locale] ?? en;
}

export const copy = en;

/** Replaces {placeholders} in a localized template. */
export function fill(template: string, vars: Record<string, string | number>): string {
  return Object.entries(vars).reduce((text, [key, value]) => text.split(`{${key}}`).join(String(value)), template);
}
