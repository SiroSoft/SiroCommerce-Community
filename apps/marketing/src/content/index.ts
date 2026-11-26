import { de } from "./de";
import { en } from "./en";
import { ja } from "./ja";
import { vi } from "./vi";
import { zh } from "./zh";
import { hreflangByLocale, locales, type Content, type IconName, type Locale } from "./types";

export { de, en, ja, vi, zh, hreflangByLocale, locales };
export type { Content, IconName, Locale };
export const defaultLocale: Locale = "en";

export function isLocale(value: string): value is Locale {
  return (locales as string[]).includes(value);
}

export function getContent(locale: Locale): Content {
  switch (locale) {
    case "vi":
      return vi;
    case "de":
      return de;
    case "ja":
      return ja;
    case "zh":
      return zh;
    default:
      return en;
  }
}

export function localePath(locale: Locale): string {
  return `/${locale}/`;
}
