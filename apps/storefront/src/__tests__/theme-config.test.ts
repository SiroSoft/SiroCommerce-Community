import { describe, expect, it } from "vitest";
import { defaultSections } from "@/homepage/resolve-homepage";
import { defaultTheme } from "@/theme/defaults";
import { themePresets } from "@/theme/presets";
import { resolveTheme } from "@/theme/resolve-theme";
import { themeCss } from "@/theme/theme-css";
import { getDefaultStorefrontConfig, getConfiguredStorefrontConfig, getStorefrontConfig, sanitizeStorefrontConfig } from "@/services/storefront-config";

describe("storefront theme/config foundation", () => {
  it("falls back to safe token groups for invalid values", () => {
    const theme = resolveTheme({ key: "unsafe<script>", tokens: { colors: { ink: "red" } } });
    expect(theme.tokens).toEqual(defaultTheme.tokens);
    expect(themeCss(theme)["--theme-accent"]).toBe(defaultTheme.tokens.colors.accent);
  });
  it("sanitizes config and retains the requested scope", () => {
    const scope = { store_id: "store-a", channel_id: "web", locale: "en", currency: "USD" };
    const config = sanitizeStorefrontConfig({ theme: {}, homepage: { sections: [{ id: "bad", type: "unknown", enabled: true }] } }, scope);
    expect(config.scope).toEqual(scope);
    expect(config.homepage.sections).toEqual(defaultSections);
  });
  it("accepts the backend envelope and snake_case response fields", () => {
    const config = sanitizeStorefrontConfig({ success: true, data: { theme: { tokens: { colors: { ink: "#111111", muted: "#222222", paper: "#333333", surface: "#444444", accent: "#555555", accent_dark: "#666666" }, typography: { display: "editorial", body: "system", density: "comfortable" }, shape: { radius: "soft", card: "bordered" } } }, brand: { name: "Demo", wordmark: "demo/shop", logo_url: "/logo.svg" }, header: { layout: "centered", announcement: false, sticky: true }, footer: { layout: "minimal", show_help: false, columns: [{ title: "Tet", links: [{ label: "Sale", href: "/sale" }] }] }, presentation: { product_card_variant: "minimal", product_detail_variant: "hologram", purchase_variant: "compact" }, policies: { shipping_url: "/shipping" }, version: 4 } });
    expect(config.brand.logoUrl).toBe("/logo.svg");
    expect(config.header).toEqual({ announcement: false, layout: "centered", sticky: true });
    expect(config.footer.layout).toBe("minimal");
    expect(config.footer.columns).toEqual([{ title: "Tet", links: [{ label: "Sale", href: "/sale" }] }]);
    expect(config.presentation.productCardVariant).toBe("minimal");
    expect(config.presentation.productDetailVariant).toBe("editorial");
    expect(config.presentation.purchaseVariant).toBe("compact");
    expect(config.policies.shippingUrl).toBe("/shipping");
    expect(config.version).toBe(4);
  });
  it("keeps batch-1 layouts and coerces unknown keys to defaults", () => {
    const config = sanitizeStorefrontConfig({ theme: {}, header: { layout: "split", announcement: true, sticky: false }, footer: { layout: "mega", show_help: true, show_policies: true, columns: [] }, presentation: { product_card_variant: "minimal", product_detail_variant: "page-gallery-left", product_list_variant: "list-grid-wide", purchase_variant: "compact" } });
    expect(config.header.layout).toBe("split");
    expect(config.footer.layout).toBe("mega");
    expect(config.presentation.productDetailVariant).toBe("page-gallery-left");
    expect(config.presentation.productListVariant).toBe("list-grid-wide");
    const coerced = sanitizeStorefrontConfig({ theme: {}, header: { layout: "header-hologram" }, footer: { layout: "footer-maxi" }, presentation: { product_card_variant: "hologram", product_detail_variant: "hologram", product_list_variant: "list-hologram", purchase_variant: "warp" } });
    expect(coerced.header.layout).toBe("standard");
    expect(coerced.footer.layout).toBe("standard");
    expect(coerced.presentation.productCardVariant).toBe("editorial");
    expect(coerced.presentation.productDetailVariant).toBe("editorial");
    expect(coerced.presentation.productListVariant).toBe("list-editorial");
    expect(coerced.presentation.purchaseVariant).toBe("standard");
  });
  it("resolves admin-driven typography into CSS variables and rejects hostile stacks", () => {
    const good = resolveTheme({ key: "custom", tokens: { colors: { ink: "#111111", muted: "#222222", paper: "#333333", surface: "#444444", accent: "#555555", accent_dark: "#666666" }, typography: { display: "festive", body: "system", density: "spacious", display_stack: '"Be Vietnam Pro", Arial, sans-serif', body_stack: '"Be Vietnam Pro", Arial, sans-serif', base_size: 17 }, shape: { radius: "soft", card: "bordered" } } });
    expect(good.tokens.typography.display_stack).toBe('"Be Vietnam Pro", Arial, sans-serif');
    expect(good.tokens.typography.base_size).toBe(17);
    expect(themeCss(good)["--theme-font-display"]).toBe('"Be Vietnam Pro", Arial, sans-serif');
    expect(themeCss(good)["--theme-font-size"]).toBe("17px");
    const evil = resolveTheme({ key: "evil", tokens: { colors: { ink: "#111111", muted: "#222222", paper: "#333333", surface: "#444444", accent: "#555555", accent_dark: "#666666" }, typography: { display: "x", body: "y", density: "comfortable", display_stack: 'x; background: url(evil)', base_size: 99 }, shape: { radius: "soft", card: "bordered" } } });
    expect(evil.tokens.typography.display_stack).toBe(defaultTheme.tokens.typography.display_stack);
    expect(evil.tokens.typography.base_size).toBe(defaultTheme.tokens.typography.base_size);
    expect(themeCss(evil)["--theme-font-display"]).toBeUndefined();
  });
  it("resolves the per-store quote mode with a safe fallback", () => {
    expect(getDefaultStorefrontConfig().purchase.quoteMode).toBe("required");
    expect(sanitizeStorefrontConfig({ theme: {}, purchase: { quote_mode: "auto" } }).purchase.quoteMode).toBe("auto");
    expect(sanitizeStorefrontConfig({ theme: {}, purchase: { quote_mode: "off" } }).purchase.quoteMode).toBe("off");
    expect(sanitizeStorefrontConfig({ theme: {}, purchase: { quote_mode: "sometimes" } }).purchase.quoteMode).toBe("required");
    expect(sanitizeStorefrontConfig({ theme: {} }).purchase.quoteMode).toBe("required");
  });
  it("does not require a backend for the default path", async () => {
    const config = await getStorefrontConfig({ store_id: "s", channel_id: "c", locale: "en", currency: "USD" }, { baseUrl: "" });
    expect(config).toEqual(getDefaultStorefrontConfig());
  });
  it("does not fetch unless both base URL and complete scope are configured", async () => {
    const request = async () => { throw new Error("must not fetch"); };
    const config = await getConfiguredStorefrontConfig({ baseUrl: "https://api.example.test", fetch: request });
    expect(config).toEqual(getDefaultStorefrontConfig());
  });
  it("applies an allowlisted preview theme without publishing", async () => {
    const keys = ["NEXT_PUBLIC_STOREFRONT_STORE_ID", "NEXT_PUBLIC_STOREFRONT_CHANNEL_ID", "NEXT_PUBLIC_STOREFRONT_LOCALE", "NEXT_PUBLIC_STOREFRONT_CURRENCY"] as const;
    const previous = Object.fromEntries(keys.map((key) => [key, process.env[key]]));
    process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID = "store-a";
    process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID = "web";
    process.env.NEXT_PUBLIC_STOREFRONT_LOCALE = "en";
    process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY = "USD";
    try {
      const base = getDefaultStorefrontConfig();
      const preset = {
        key: "pharmacy",
        name: "Pharmacy",
        description: "Preview",
        preview: "/preview.png",
        theme: { key: "pharmacy", extends: "general", version: "1.0.0", schema_version: 1, tokens: defaultTheme.tokens },
        brand: { name: "Preview Pharmacy", wordmark: "preview", logo_url: "/logo.svg" },
        header: { announcement: true, layout: "centered", sticky: true },
        footer: { layout: "minimal", show_help: true, show_policies: true, columns: [] },
        purchase: { quote_mode: "auto" },
        policies: { shipping_url: "/shipping" },
        presentation: { product_card_variant: "minimal", product_detail_variant: "focused", purchase_variant: "compact" },
        sections: [{ id: "hero", type: "campaign_hero", enabled: true, order: 10, props: {} }],
      };
      const request = async (input: string | URL | Request) => {
        const url = String(input);
        return url.includes("/api/storefront/theme-presets")
          ? { ok: true, json: async () => ({ data: [preset] }) }
          : { ok: true, json: async () => ({ data: base }) };
      };
      const config = await getConfiguredStorefrontConfig({ baseUrl: "https://api.example.test", previewTheme: "pharmacy", fetch: request as unknown as typeof fetch });
      expect(config.theme.key).toBe("pharmacy");
      expect(config.brand.name).toBe("Preview Pharmacy");
      expect(config.header.layout).toBe("centered");
      expect(config.purchase.quoteMode).toBe("auto");
      expect(config.homepage.sections[0]?.id).toBe("hero");
    } finally {
      for (const key of keys) {
        if (previous[key] === undefined) delete process.env[key];
        else process.env[key] = previous[key];
      }
    }
  });
  it("inherits missing tokens from parent via extends and keeps child overrides", () => {
    const child = resolveTheme({ key: "forest-child", extends: "forest", tokens: { colors: { ink: "#000000", muted: "#111111", paper: "#222222", surface: "#333333", accent: "#444444", accent_dark: "#555555" }, typography: { display: "editorial", body: "system", density: "compact" }, shape: { radius: "sharp", card: "bordered" } } }, themePresets);
    expect(child.extends).toBe("forest");
    expect(child.tokens.colors.accent).toBe("#444444");
    expect(child.tokens.typography.density).toBe("compact");
  });
  it("falls back to default theme for unknown parent, cycles, or deep chains", () => {
    const unknown = resolveTheme({ key: "x", extends: "no-such-theme", tokens: defaultTheme.tokens }, themePresets);
    expect(unknown.tokens).toEqual(defaultTheme.tokens);
    const cyclic: Record<string, unknown> = { general: { ...themePresets.general, extends: "loop-b" }, "loop-b": { ...themePresets.general, key: "loop-b", extends: "general" } };
    const loop = resolveTheme({ key: "loop-a", extends: "general", tokens: defaultTheme.tokens }, cyclic as never);
    expect(loop.key).toBe("loop-a");
  });
});
