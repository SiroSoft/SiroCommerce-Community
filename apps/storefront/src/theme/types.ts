export type ThemeDensity = "compact" | "comfortable" | "spacious";
export type ThemeRadius = "sharp" | "soft" | "round";
export type ThemeCard = "flat" | "bordered" | "elevated";

export interface ThemeTypography {
  display: string;
  body: string;
  density: ThemeDensity;
  /** Real font stacks rendered into CSS variables. Empty = theme default. */
  display_stack?: string;
  body_stack?: string;
  /** Base font size in px applied to <html>. Unset = browser default. */
  base_size?: number;
}

export interface ThemeTokens {
  colors: Record<string, string>;
  typography: ThemeTypography;
  shape: { radius: ThemeRadius; card: ThemeCard };
}

export type HeaderLayout = "standard" | "minimal" | "centered" | "split";
export type FooterLayout = "standard" | "minimal" | "mega";
export type ListLayout = "list-editorial" | "list-grid-wide";

export interface HeaderConfig {
  announcement: boolean;
  layout: HeaderLayout;
  sticky: boolean;
}

export interface FooterLink { label: string; href: string }
export interface FooterColumn { title: string; links: FooterLink[] }

export interface FooterConfig {
  layout: FooterLayout;
  show_help: boolean;
  show_policies: boolean;
  columns: FooterColumn[];
}

export type ProductCardVariant = "editorial" | "minimal" | "spotlight";
export type ProductDetailVariant = "editorial" | "focused" | "page-gallery-left" | "split-buybox";
export type PurchaseVariant = "standard" | "compact";

export interface StorefrontTheme {
  key: string;
  version: string;
  schemaVersion: number;
  /** Child themes name a parent key and override only what they change. */
  extends?: string;
  brand: { name: string; wordmark?: string; logoUrl?: string };
  tokens: ThemeTokens;
}
