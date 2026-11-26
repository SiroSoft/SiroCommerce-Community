"use client";

import { createContext, useContext, type ReactNode } from "react";
import { getCopy, type StorefrontCopy } from "@/lib/i18n";
import { storefrontLocale, type StorefrontLocale } from "@/lib/locale";

const LocaleContext = createContext<StorefrontLocale | null>(null);

/** Provides the request locale to client components without prop drilling. */
export function LocaleProvider({ locale, children }: { locale: StorefrontLocale; children: ReactNode }) {
  return <LocaleContext.Provider value={locale}>{children}</LocaleContext.Provider>;
}

/**
 * Locale-aware copy for client components. Falls back to the configured
 * default when rendered outside a provider (tests, isolated previews).
 */
export function useCopy(): StorefrontCopy {
  return getCopy(useLocale());
}

/** Active locale for client components (provider value or configured default). */
export function useLocale(): StorefrontLocale {
  return useContext(LocaleContext) ?? storefrontLocale();
}
