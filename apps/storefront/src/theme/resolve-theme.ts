import { defaultTheme, defaultThemeColors } from "./defaults";
import { themePresets } from "./presets";
import type { StorefrontTheme, ThemeCard, ThemeDensity, ThemeRadius, ThemeTokens, ThemeTypography } from "./types";

const densities = ["compact", "comfortable", "spacious"] as const;
const radii = ["sharp", "soft", "round"] as const;
const cards = ["flat", "bordered", "elevated"] as const;
const safeColor = /^#[0-9a-f]{6}$/i;
const safeText = (value: unknown, fallback: string, max = 120) => typeof value === "string" && value.trim().length > 0 && value.length <= max && !/[<>]/.test(value) ? value.trim() : fallback;
const safeUrl = (value: unknown): string | undefined => {
  if (typeof value !== "string" || value.length > 500 || /[<>\s]/.test(value)) return undefined;
  if (value.startsWith("/")) return value;
  try { const url = new URL(value); return url.protocol === "https:" || url.protocol === "http:" ? value : undefined; } catch { return undefined; }
};

const safeFontStack = (value: string): string | undefined => {
  const stack = value.trim();
  if (stack === "" || stack.length > 200 || /[<>;{}]/.test(stack) || /\b(url|expression|javascript|data)\b/i.test(stack)) return undefined;
  if (!/^[A-Za-z0-9\s,.'"\-]+$/.test(stack)) return undefined;
  return stack;
};

function resolveTokens(value: unknown, base: ThemeTokens = defaultTheme.tokens): ThemeTokens {
  if (!value || typeof value !== "object") return base;
  const raw = value as Record<string, unknown>;
  const colors = raw.colors;
  if (!colors || typeof colors !== "object") return base;
  const rawColors = colors as Record<string, unknown>;
  const requiredColors = ["ink", "muted", "paper", "surface", "accent", "accent_dark"];
  if (requiredColors.some((key) => rawColors[key] !== undefined && !safeColor.test(String(rawColors[key]))) || requiredColors.some((key) => rawColors[key] === undefined && base.colors[key] === undefined)) return base;
  const typography = raw.typography;
  const shape = raw.shape;
  if (!typography || typeof typography !== "object" || !shape || typeof shape !== "object") return base;
  const t = typography as Record<string, unknown>;
  const s = shape as Record<string, unknown>;
  const density = t.density;
  const radius = s.radius;
  const card = s.card;
  if (!densities.includes(density as ThemeDensity) || !radii.includes(radius as ThemeRadius) || !cards.includes(card as ThemeCard)) return base;
  const resolved: ThemeTypography = { display: safeText(t.display, base.typography.display, 40), body: safeText(t.body, base.typography.body, 40), density: density as ThemeDensity };
  // Font stacks render into CSS: plain font-name lists only, never urls or
  // expressions. Anything suspicious falls back to the theme default.
  const baseStacks: Record<string, unknown> = { display_stack: base.typography.display_stack, body_stack: base.typography.body_stack };
  for (const field of ["display_stack", "body_stack"] as const) {
    const stack: unknown = t[field];
    if (typeof stack === "string" && stack.trim() !== "") {
      const fallback = typeof baseStacks[field] === "string" ? (baseStacks[field] as string) : "";
      resolved[field] = safeFontStack(stack) ?? fallback;
    } else if (typeof baseStacks[field] === "string") {
      resolved[field] = baseStacks[field] as string;
    }
  }
  const size: unknown = t.base_size ?? t.baseSize;
  if (typeof size === "number" && Number.isInteger(size) && size >= 12 && size <= 22) resolved.base_size = size;
  else if (typeof base.typography.base_size === "number") resolved.base_size = base.typography.base_size;
  return { colors: Object.fromEntries(defaultThemeColors.map((key) => [key, safeColor.test(String(rawColors[key] ?? "")) ? String(rawColors[key]) : base.colors[key]!])), typography: resolved, shape: { radius: radius as ThemeRadius, card: card as ThemeCard } };
}

export function resolveTheme(value: unknown, registry: Record<string, StorefrontTheme> = themePresets): StorefrontTheme {
  return resolveThemeInner(value, registry, []);
}

function resolveThemeInner(value: unknown, registry: Record<string, StorefrontTheme>, chain: string[]): StorefrontTheme {
  if (!value || typeof value !== "object") return defaultTheme;
  const raw = value as Record<string, unknown>;
  const brand = raw.brand && typeof raw.brand === "object" ? raw.brand as Record<string, unknown> : {};
  const parentKey = typeof raw.extends === "string" ? raw.extends : "";
  // Child-theme inheritance with cycle protection: unknown keys, cycles, and
  // chains deeper than three fall back to the default theme instead of
  // producing a half-merged look.
  const base = parentKey && !chain.includes(parentKey) && chain.length < 3 && registry[parentKey]
    ? resolveThemeInner(registry[parentKey], registry, [...chain, parentKey])
    : defaultTheme;
  const merged: StorefrontTheme = {
    ...base,
    key: safeText(raw.key, base.key, 40),
    version: safeText(raw.version, base.version, 40),
    schemaVersion: raw.schema_version === 1 || raw.schemaVersion === 1 ? 1 : base.schemaVersion,
    brand: {
      name: safeText(brand.name, base.brand.name),
      ...(safeText(brand.wordmark, "", 120) ? { wordmark: safeText(brand.wordmark, "", 120) } : base.brand.wordmark ? { wordmark: base.brand.wordmark } : {}),
      ...(safeUrl(brand.logo_url ?? brand.logoUrl) ?? base.brand.logoUrl ? { logoUrl: (safeUrl(brand.logo_url ?? brand.logoUrl) ?? base.brand.logoUrl)! } : {}),
    },
    tokens: resolveTokens(raw.tokens, base.tokens),
  };
  if (parentKey) merged.extends = parentKey;
  return merged;
}
