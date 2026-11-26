import api, { fetchWithAuth } from "./api";

export const HOMEPAGE_SECTION_TYPES = [
  "campaign_hero",
  "collection_directory",
  "editorial_story",
  "product_collection",
  "service_proof",
  "trust_strip",
  "category_links",
  "banner_grid",
  "usp_strip",
  "faq",
] as const;

/**
 * Layout options mirror apps/storefront/src/theme/layouts.ts
 * (FE_THEME_LAYOUT_SPEC RULE-2). Keep the four layers in agreement.
 */
export const HEADER_LAYOUT_OPTIONS = ["standard", "minimal", "centered", "split"] as const;
export const FOOTER_LAYOUT_OPTIONS = ["standard", "minimal", "mega"] as const;
export const LIST_LAYOUT_OPTIONS = ["list-editorial", "list-grid-wide"] as const;
export const PAGE_LAYOUT_OPTIONS = ["editorial", "focused", "page-gallery-left"] as const;
export const CARD_VARIANT_OPTIONS = ["editorial", "minimal"] as const;
export const PURCHASE_VARIANT_OPTIONS = ["standard", "compact"] as const;
export const QUOTE_MODE_OPTIONS = ["required", "auto", "off"] as const;
export const QUOTE_MODE_LABELS: Record<(typeof QUOTE_MODE_OPTIONS)[number], string> = {
  required: "Required quote step",
  auto: "Auto quote",
  off: "No quote step",
};
export const QUOTE_MODE_HINTS: Record<(typeof QUOTE_MODE_OPTIONS)[number], string> = {
  required: "Cart → quote → checkout. Shows quote entry points across the storefront.",
  auto: "Cart → checkout. The server quote still runs automatically before placing the order.",
  off: "Same flow as auto and quote entry points are hidden.",
};
export const DENSITY_OPTIONS = ["compact", "comfortable", "spacious"] as const;
export const RADIUS_OPTIONS = ["sharp", "soft", "round"] as const;
export const CARD_SHAPE_OPTIONS = ["flat", "bordered", "elevated"] as const;

export type HomepageSectionType = (typeof HOMEPAGE_SECTION_TYPES)[number];
export type ThemeConfig = {
  scope: { store_id: string; channel_id: string; locale: string; currency: string };
  theme: { key: string; extends?: string; version: string; schema_version: number; tokens: ThemeTokens };
  vertical: { key: string; version: string };
  brand: { name: string; wordmark: string; logo_url: string | null; favicon_url: string | null };
  navigation: { menu_key: string; items: unknown[] };
  header: { announcement: boolean; layout: string; sticky: boolean };
  footer: { layout: string; show_help: boolean; show_policies: boolean; columns: Array<{ title: string; links: Array<{ label: string; href: string }> }> };
  homepage: { sections: HomepageSection[] };
  presentation: { product_card_variant: string; product_detail_variant: string; product_list_variant?: string; purchase_variant: string };
  purchase: { quote_mode: (typeof QUOTE_MODE_OPTIONS)[number] };
  policies: { shipping_url: string; returns_url: string; support_url: string };
  experiments: Array<{ id: string; enabled?: boolean; variants: Array<{ id: string; weight: number }> }>;
  version: number;
};
export type ThemeTokens = {
  colors: { ink: string; muted: string; paper: string; surface: string; accent: string; accent_dark: string; success?: string; danger?: string };
  typography: { display: string; body: string; density: string; display_stack?: string; body_stack?: string; base_size?: number };
  shape: { radius: string; card: string };
};
export type HomepageSection = {
  id: string;
  type: HomepageSectionType;
  enabled: boolean;
  order: number;
  props: { title?: string; subtitle?: string; eyebrow?: string; source?: string; limit?: number; cta?: { label?: string; href?: string } };
};
export type ThemeOption = { key: string; name: string; version: string; status?: string };
export type VerticalPreset = ThemeOption & {
  description?: string;
  preview?: string;
  swatches?: string[];
  layout_hints?: string[];
  presentation_defaults?: Record<string, unknown>;
  purchase_defaults?: { quote_mode?: string };
  theme_defaults?: Record<string, unknown>;
  homepage_defaults?: { preferred_sections?: HomepageSectionType[]; sections?: HomepageSection[] };
};
export type PresetGalleryItem = {
  key: string;
  name: string;
  description: string;
  swatches: string[];
  layout_hints: string[];
  preset: VerticalPreset;
};
export const THEME_CATALOG_EDITIONS = ["free", "pro", "all"] as const;
export type ThemeCatalogEdition = (typeof THEME_CATALOG_EDITIONS)[number];
export type EcosystemCatalogItem = {
  id: string;
  type: "plugin" | "theme";
  name: string;
  description: string;
  vendor: string;
  edition: "free" | "pro";
  status: string;
  latest_version: string;
  installed_version: string | null;
  installed_state: string;
  update_available: boolean;
  compatible: boolean;
  min_core: string | null;
  min_commerce: string | null;
  license_required: boolean;
  license_sku: string | null;
  license_status: "not_required" | "valid" | "missing" | "expired" | "grace";
  artifact_url: string | null;
  sha256: string | null;
  preview_url: string | null;
  changelog_url: string | null;
  changelog: string | null;
  tags: string[];
};
export const PRO_THEME_LOCK_REASON = "Pro theme requires license and signed artifact";
export type ThemeCatalogAvailability = { openable: boolean; reason: string | null };

/** A catalog theme is only importable into the builder when it is a published free package. */
export function themeCatalogAvailability(
  item: Pick<EcosystemCatalogItem, "edition" | "status">,
): ThemeCatalogAvailability {
  if (item.edition === "free" && item.status === "published") return { openable: true, reason: null };
  if (item.edition === "pro" || item.status === "planned") return { openable: false, reason: PRO_THEME_LOCK_REASON };
  return { openable: false, reason: "Only published free themes can be imported." };
}

export type ThemeCatalogQuery = { edition?: ThemeCatalogEdition; q?: string };
export type ThemeCatalogResponse = {
  schema: string;
  generated_at: string;
  items: EcosystemCatalogItem[];
};
export type ThemeUpdatesResponse = { items: EcosystemCatalogItem[] };
export type ThemeImportResult = { ok: true; config: ThemeConfig } | { ok: false; error: string };
export type ThemePackageImportResult = { ok: true; package: Record<string, unknown> } | { ok: false; error: string };
export type Revision = { id: string; version: number; action: string; created_at?: string; actor_id?: string };
export type RevisionDiff = { changes: Array<{ field: string; from: unknown; to: unknown }> };
export type ThemeWrite = Omit<ThemeConfig, "scope" | "version" | "theme" | "vertical"> & {
  expected_version: number;
  theme_key: string;
  theme_extends?: string;
  vertical_key: string;
  tokens: ThemeTokens;
};

export const VERTICAL_PRESET_META: Record<string, { name: string; description: string; swatches: string[]; layout_hints: string[] }> = {
  general: {
    name: "General",
    description: "A balanced editorial storefront for mixed catalogs.",
    swatches: ["#1E2420", "#F5F5F0", "#883C29"],
    layout_hints: ["Editorial cards", "Editorial product detail"],
  },
  beauty: {
    name: "Beauty & Personal Care",
    description: "Soft, editorial beauty storefront with routine-led collections, trust proof and FAQ.",
    swatches: ["#2A1F24", "#FBF4F6", "#C05A7A"],
    layout_hints: ["Routine-led collections", "Banner grid + USP strip", "Product care FAQ"],
  },
  pharmacy: {
    name: "Pharmacy & Health",
    description: "Trust-first pharmacy layout with pharmacist proof, essential categories and health FAQ.",
    swatches: ["#14232B", "#F2F7F8", "#0F7C8A"],
    layout_hints: ["Trust proof first", "Refill banners", "Health FAQ"],
  },
  fashion: {
    name: "Fashion & Apparel",
    description: "Editorial fashion storefront with lookbook banners, new-season collections and size FAQ.",
    swatches: ["#111111", "#F7F6F3", "#9B2C2C"],
    layout_hints: ["Lookbook banners", "Editorial detail page", "Size and returns FAQ"],
  },
  fnb: {
    name: "Food & Beverage",
    description: "Appetite-led food and beverage layout with category shortcuts, banners and delivery FAQ.",
    swatches: ["#2B1D14", "#FFF8EE", "#C4551F"],
    layout_hints: ["Appetite-led banners", "Category shortcuts", "Delivery FAQ"],
  },
  electronics: {
    name: "Electronics & Technology",
    description: "Spec-first electronics storefront with compare banners, warranty proof and technical FAQ.",
    swatches: ["#0E1622", "#F4F7FA", "#0B6BCB"],
    layout_hints: ["Spec-first cards", "Compare banners", "Warranty and returns FAQ"],
  },
  b2b: {
    name: "B2B & Wholesale",
    description: "Company-first wholesale layout with quote request, bulk pricing proof and procurement FAQ.",
    swatches: ["#12202F", "#F3F5F8", "#1E4E79"],
    layout_hints: ["Quote workflow", "Bulk pricing proof", "Procurement FAQ"],
  },
  apparel: {
    name: "Apparel",
    description: "Lookbook-first layout with portrait product imagery.",
    swatches: ["#1E2420", "#F4F0E8", "#8A3B4A"],
    layout_hints: ["Portrait cards", "Size and option emphasis"],
  },
  home: {
    name: "Home",
    description: "Room-inspiration composition for furniture and decor.",
    swatches: ["#2D2A26", "#F3EFE8", "#7C6A52"],
    layout_hints: ["Wide lifestyle banners", "Room collection links"],
  },
  food_beverage: {
    name: "Food & Beverage",
    description: "Appetite-led presentation for fresh and packaged goods.",
    swatches: ["#25301F", "#FBF4E6", "#B4532A"],
    layout_hints: ["Banner grid", "USP strip for delivery and freshness"],
  },
  digital: {
    name: "Digital",
    description: "Feature and benefit-led layout for downloadable products.",
    swatches: ["#161B2E", "#F4F6FB", "#4F5BD5"],
    layout_hints: ["Compact cards", "FAQ for licensing and delivery"],
  },
  marketplace: {
    name: "Marketplace",
    description: "Dense discovery layout for multi-vendor catalogs.",
    swatches: ["#1F2933", "#F7F7F5", "#C05621"],
    layout_hints: ["Wide grid", "Vendor and rating visibility"],
  },
};

function presetSwatches(preset: VerticalPreset): string[] {
  const defaults = preset.theme_defaults;
  const theme = defaults && typeof defaults.theme === "object" && defaults.theme !== null ? defaults.theme as Record<string, unknown> : defaults;
  const tokens = theme && typeof theme.tokens === "object" && theme.tokens !== null ? theme.tokens as Record<string, unknown> : undefined;
  const colors = tokens && typeof tokens.colors === "object" && tokens.colors !== null ? tokens.colors as Record<string, unknown> : undefined;
  const derived = [colors?.ink, colors?.paper, colors?.accent].filter((value): value is string => typeof value === "string" && /^#[0-9a-f]{3,6}$/i.test(value));
  return derived.length === 3 ? derived : [];
}

export function presetGallery(preset: VerticalPreset): PresetGalleryItem {
  const meta = VERTICAL_PRESET_META[preset.key];
  const derivedSwatches = presetSwatches(preset);
  return {
    key: preset.key,
    name: preset.name || meta?.name || preset.key.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase()),
    description: preset.description || meta?.description || "Presentation defaults for this vertical.",
    swatches: preset.swatches?.length ? preset.swatches : derivedSwatches.length ? derivedSwatches : meta?.swatches ?? ["#1E2420", "#F5F5F0", "#883C29"],
    layout_hints: preset.layout_hints?.length ? preset.layout_hints : meta?.layout_hints ?? [],
    preset,
  };
}

function parseHex(value: string): [number, number, number] | null {
  const match = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(value.trim());
  if (!match) return null;
  const hex = match[1].length === 3 ? match[1].split("").map((char) => char + char).join("") : match[1];
  return [Number.parseInt(hex.slice(0, 2), 16), Number.parseInt(hex.slice(2, 4), 16), Number.parseInt(hex.slice(4, 6), 16)];
}

function relativeLuminance(hex: string): number | null {
  const rgb = parseHex(hex);
  if (!rgb) return null;
  const channels = rgb.map((channel) => {
    const value = channel / 255;
    return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}

/** WCAG 2.1 contrast ratio for two hex colors, or null when a value is invalid. */
export function contrastRatio(foreground: string, background: string): number | null {
  const foregroundLuminance = relativeLuminance(foreground);
  const backgroundLuminance = relativeLuminance(background);
  if (foregroundLuminance === null || backgroundLuminance === null) return null;
  const lighter = Math.max(foregroundLuminance, backgroundLuminance);
  const darker = Math.min(foregroundLuminance, backgroundLuminance);
  return (lighter + 0.05) / (darker + 0.05);
}

export function addHomepageSection(sections: HomepageSection[], type: HomepageSectionType): HomepageSection[] {
  const nextOrder = sections.reduce((max, section) => Math.max(max, section.order), 0) + 10;
  const id = `section-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  return [...sections, { id, type, enabled: true, order: nextOrder, props: {} }];
}

export function moveHomepageSection(sections: HomepageSection[], id: string, direction: "up" | "down"): HomepageSection[] {
  const ordered = [...sections].sort((a, b) => a.order - b.order || a.id.localeCompare(b.id));
  const index = ordered.findIndex((section) => section.id === id);
  const target = direction === "up" ? index - 1 : index + 1;
  if (index < 0 || target < 0 || target >= ordered.length) return sections;
  [ordered[index], ordered[target]] = [ordered[target], ordered[index]];
  const orders = new Map(ordered.map((section, position) => [section.id, (position + 1) * 10]));
  return sections.map((section) => ({ ...section, order: orders.get(section.id) ?? section.order }));
}

/** Builds a preview-only config from a vertical preset without mutating the draft. */
export function previewPresetConfig(config: ThemeConfig, preset: VerticalPreset): ThemeConfig {
  const defaults = (preset.theme_defaults ?? {}) as Record<string, unknown>;
  const nestedTheme = defaults.theme && typeof defaults.theme === "object" && defaults.theme !== null ? defaults.theme as Partial<ThemeConfig["theme"]> : {};
  const tokens = nestedTheme.tokens;
  const nestedPurchase = defaults.purchase && typeof defaults.purchase === "object" && defaults.purchase !== null ? defaults.purchase as { quote_mode?: unknown } : {};
  const presetQuoteMode = preset.purchase_defaults?.quote_mode ?? nestedPurchase.quote_mode;
  const quoteMode = typeof presetQuoteMode === "string" && (QUOTE_MODE_OPTIONS as readonly string[]).includes(presetQuoteMode)
    ? presetQuoteMode as (typeof QUOTE_MODE_OPTIONS)[number]
    : config.purchase.quote_mode;
  return {
    ...config,
    theme: {
      ...config.theme,
      ...nestedTheme,
      tokens: {
        ...config.theme.tokens,
        ...tokens,
        colors: { ...config.theme.tokens.colors, ...(tokens?.colors ?? {}) },
        typography: { ...config.theme.tokens.typography, ...(tokens?.typography ?? {}) },
        shape: { ...config.theme.tokens.shape, ...(tokens?.shape ?? {}) },
      },
    },
    header: (defaults.header as ThemeConfig["header"] | undefined) ?? config.header,
    footer: (defaults.footer as ThemeConfig["footer"] | undefined) ?? config.footer,
    policies: (defaults.policies as ThemeConfig["policies"] | undefined) ?? config.policies,
    presentation: { ...config.presentation, ...(preset.presentation_defaults ?? {}) },
    purchase: { quote_mode: quoteMode },
    homepage: preset.homepage_defaults?.sections?.length ? { sections: preset.homepage_defaults.sections } : config.homepage,
    vertical: { ...config.vertical, key: preset.key },
  };
}

/** Minimal import guard: version/theme/scope must exist before a draft is replaced. */
export function validateThemeImport(value: unknown): ThemeImportResult {
  if (!value || typeof value !== "object" || Array.isArray(value)) return { ok: false, error: "Import must be a theme configuration object." };
  const record = value as Record<string, unknown>;
  const wrapped = record.config;
  const candidate = wrapped && typeof wrapped === "object" && !Array.isArray(wrapped) ? wrapped as Record<string, unknown> : record;
  if (typeof candidate.version !== "number" || !Number.isFinite(candidate.version)) return { ok: false, error: "Import is missing a numeric version." };
  const theme = candidate.theme;
  if (!theme || typeof theme !== "object" || Array.isArray(theme)) return { ok: false, error: "Import is missing the theme block." };
  const themeRecord = theme as Record<string, unknown>;
  if (typeof themeRecord.key !== "string" || !themeRecord.key.trim()) return { ok: false, error: "Import theme.key is required." };
  if (!themeRecord.tokens || typeof themeRecord.tokens !== "object" || Array.isArray(themeRecord.tokens)) return { ok: false, error: "Import theme.tokens is required." };
  const scope = candidate.scope;
  if (!scope || typeof scope !== "object" || Array.isArray(scope)) return { ok: false, error: "Import is missing the scope block." };
  const scopeRecord = scope as Record<string, unknown>;
  for (const field of ["store_id", "channel_id", "locale", "currency"] as const) {
    if (typeof scopeRecord[field] !== "string" || !(scopeRecord[field] as string).trim()) return { ok: false, error: `Import scope.${field} is required.` };
  }
  return { ok: true, config: candidate as unknown as ThemeConfig };
}

/** Native Siro theme package (siro.theme/1): portable, no scope, no auto-publish. */
export function validateThemePackage(value: unknown): ThemePackageImportResult {
  if (!value || typeof value !== "object" || Array.isArray(value)) return { ok: false, error: "Theme package must be an object." };
  const record = value as Record<string, unknown>;
  if (record.schema !== "siro.theme/1") return { ok: false, error: "Theme package schema must be siro.theme/1." };
  if (typeof record.id !== "string" || !/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/.test(record.id)) return { ok: false, error: "Theme package id must be vendor/key." };
  if (typeof record.name !== "string" || record.name.trim().length < 2) return { ok: false, error: "Theme package name is required." };
  if (typeof record.version !== "string" || !/^\d+\.\d+\.\d+$/.test(record.version)) return { ok: false, error: "Theme package version must be major.minor.patch." };
  const homepage = record.homepage;
  const sections = homepage && typeof homepage === "object" && !Array.isArray(homepage) ? (homepage as Record<string, unknown>).sections : undefined;
  if (!Array.isArray(sections) || sections.length === 0) return { ok: false, error: "Theme package homepage.sections must contain at least one section." };
  return { ok: true, package: record };
}

type Envelope<T> = { data: T };
const unwrap = <T>(response: { data: Envelope<T> | T }): T => {
  const body = response.data as Envelope<T>;
  return body && typeof body === "object" && "data" in body ? body.data : response.data as T;
};

const idempotency = (key: string) => ({ headers: { "Idempotency-Key": key } });

const safeEdition = (edition?: ThemeCatalogEdition): ThemeCatalogEdition =>
  edition && (THEME_CATALOG_EDITIONS as readonly string[]).includes(edition) ? edition : "all";

// Adapter seam for the forthcoming generated OpenAPI methods. All requests still
// use the shared scope/auth/retry boundary and never call fetch directly.
export const storefrontThemeService = {
  async catalog({ edition, q }: ThemeCatalogQuery = {}): Promise<ThemeCatalogResponse> {
    const params: Record<string, string> = { type: "theme", edition: safeEdition(edition) };
    const search = typeof q === "string" ? q.trim() : "";
    if (search) params.q = search;
    return unwrap(await api.get<Envelope<ThemeCatalogResponse> | ThemeCatalogResponse>("/api/admin/ecosystem/catalog", { params }));
  },
  async updates(): Promise<ThemeUpdatesResponse> {
    return unwrap(await api.get<Envelope<ThemeUpdatesResponse> | ThemeUpdatesResponse>("/api/admin/ecosystem/updates"));
  },
  async getConfig() {
    return unwrap(await api.get<Envelope<ThemeConfig> | ThemeConfig>("/api/admin/storefront/config"));
  },
  async themes() {
    return unwrap(await api.get<Envelope<ThemeOption[]> | ThemeOption[]>("/api/admin/storefront/themes"));
  },
  async presets(): Promise<VerticalPreset[]> {
    return unwrap(await api.get<Envelope<VerticalPreset[]> | VerticalPreset[]>("/api/admin/storefront/vertical-presets"));
  },
  async revisions() {
    return unwrap(await api.get<Envelope<Revision[]> | Revision[]>("/api/admin/storefront/config/revisions"));
  },
  async diff(from: string, to: string) {
    return unwrap(await api.get<Envelope<RevisionDiff> | RevisionDiff>("/api/admin/storefront/config/revisions/diff", { params: { from, to } }));
  },
  async saveDraft(body: ThemeWrite, idempotencyKey: string) {
    return unwrap(await api.put<Envelope<ThemeConfig> | ThemeConfig>("/api/admin/storefront/config", body, { idempotencyKey }));
  },
  async preview(body: ThemeWrite, idempotencyKey: string) {
    return unwrap(await api.post<Envelope<ThemeConfig> | ThemeConfig>("/api/admin/storefront/config/preview", body, { idempotencyKey }));
  },
  async applyPreset(expected_version: number, vertical_key: string, key: string) {
    return unwrap(await api.post<Envelope<ThemeConfig> | ThemeConfig>("/api/admin/storefront/config/apply-preset", { expected_version, vertical_key }, idempotency(key)));
  },
  async publish(expected_version: number, key: string) {
    return unwrap(await api.post<Envelope<ThemeConfig> | ThemeConfig>("/api/admin/storefront/config/publish", { expected_version }, idempotency(key)));
  },
  async restore(revision: string, expected_version: number, key: string) {
    return unwrap(await api.post<Envelope<ThemeConfig> | ThemeConfig>(`/api/admin/storefront/config/restore/${revision}`, { expected_version }, idempotency(key)));
  },
  async exportConfig() {
    return unwrap(await api.get<Envelope<{ exported_at: string; scope: unknown; config: ThemeConfig }> | { exported_at: string; scope: unknown; config: ThemeConfig }>("/api/admin/storefront/config/export"));
  },
  async importConfig(config: unknown, expected_version: number | undefined, key: string) {
    return unwrap(await api.post<Envelope<ThemeConfig> | ThemeConfig>("/api/admin/storefront/config/import", expected_version === undefined ? { config } : { config, expected_version }, idempotency(key)));
  },
  async importThemePackage(theme: Record<string, unknown>, expected_version: number | undefined, key: string) {
    return unwrap(await api.post<Envelope<ThemeConfig> | ThemeConfig>("/api/admin/storefront/config/import-theme", expected_version === undefined ? { theme } : { theme, expected_version }, idempotency(key)));
  },
  async exportThemePackage() {
    return unwrap(await api.get<Envelope<Record<string, unknown>> | Record<string, unknown>>("/api/admin/storefront/config/export-theme"));
  },
  async exportThemeArchive(): Promise<{ blob: Blob; filename: string }> {
    const response = await fetchWithAuth("/api/admin/storefront/config/export-theme-archive", { method: "GET" });
    if (!response.ok) throw new Error("Theme archive export failed");
    const disposition = response.headers.get("Content-Disposition") ?? "";
    const match = /filename="([^"]+)"/.exec(disposition);
    return { blob: await response.blob(), filename: match?.[1] ?? "siro-theme.zip" };
  },
  async importThemeArchive(file: File, expected_version: number | undefined, key: string) {
    const body = new FormData();
    body.append("package", file);
    if (expected_version !== undefined) body.append("expected_version", String(expected_version));
    const response = await fetchWithAuth("/api/admin/storefront/config/import-theme-archive", { method: "POST", headers: { "Idempotency-Key": key }, body });
    const json = await response.json().catch(() => ({})) as { data?: { draft?: ThemeConfig }; message?: string };
    if (!response.ok) throw new Error(json.message ?? "Theme archive import failed");
    return json.data?.draft as ThemeConfig;
  },
};
