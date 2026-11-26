import { describe, expect, it } from "vitest";
import {
  addHomepageSection,
  contrastRatio,
  moveHomepageSection,
  presetGallery,
  previewPresetConfig,
  validateThemeImport,
  type HomepageSection,
  type ThemeConfig,
  type VerticalPreset,
} from "@/services/storefront-theme.service";

const tokens = {
  colors: { ink: "#111111", muted: "#777777", paper: "#ffffff", surface: "#fafafa", accent: "#c0392b", accent_dark: "#922b21" },
  typography: { display: "Georgia", body: "Inter", density: "comfortable" },
  shape: { radius: "soft", card: "bordered" },
};

function baseConfig(): ThemeConfig {
  return {
    scope: { store_id: "s1", channel_id: "c1", locale: "en", currency: "USD" },
    theme: { key: "default", version: "1", schema_version: 1, tokens },
    vertical: { key: "general", version: "1" },
    brand: { name: "Siro", wordmark: "SIRO", logo_url: null, favicon_url: null },
    navigation: { menu_key: "main", items: [] },
    header: { announcement: false, layout: "standard", sticky: true },
    footer: { layout: "standard", show_help: true, show_policies: true, columns: [] },
    homepage: { sections: [] },
    presentation: { product_card_variant: "editorial", product_detail_variant: "editorial", purchase_variant: "standard" },
    purchase: { quote_mode: "required" },
    policies: { shipping_url: "/ship", returns_url: "/returns", support_url: "/support" },
    experiments: [],
    version: 3,
  };
}

function section(id: string, order: number, type: HomepageSection["type"] = "faq"): HomepageSection {
  return { id, type, enabled: true, order, props: {} };
}

describe("theme token helpers", () => {
  it("computes WCAG contrast ratios and rejects invalid hex values", () => {
    expect(contrastRatio("#000000", "#ffffff")).toBeCloseTo(21, 5);
    expect(contrastRatio("#fff", "#000")).toBeCloseTo(21, 5);
    expect(contrastRatio("not-a-color", "#ffffff")).toBeNull();
    expect(contrastRatio("#123456", "rgb(0,0,0)")).toBeNull();
  });

  it("appends homepage sections with increasing order and a generated id", () => {
    const next = addHomepageSection([section("a", 10), section("b", 30)], "faq");
    expect(next).toHaveLength(3);
    expect(next[2].order).toBe(40);
    expect(next[2].enabled).toBe(true);
    expect(next[2].id).toMatch(/^section-/);
  });

  it("moves sections within bounds and renumbers their order", () => {
    const sections = [section("a", 10), section("b", 20), section("c", 30)];
    const down = moveHomepageSection(sections, "a", "down");
    expect(down.map((item) => item.id)).toEqual(["a", "b", "c"]);
    expect(down.map((item) => item.order)).toEqual([20, 10, 30]);

    const up = moveHomepageSection(sections, "c", "up");
    expect(up.map((item) => item.order)).toEqual([10, 30, 20]);

    expect(moveHomepageSection(sections, "a", "up")).toBe(sections);
    expect(moveHomepageSection(sections, "c", "down")).toBe(sections);
    expect(moveHomepageSection(sections, "missing", "down")).toBe(sections);
  });

  it("previews a vertical preset without mutating the draft config", () => {
    const config = baseConfig();
    const preset: VerticalPreset = {
      key: "fashion",
      name: "Fashion",
      version: "1",
      theme_defaults: {
        theme: {
          tokens: {
            colors: { accent: "#000000" },
            typography: { display: "Playfair" },
            shape: { radius: "sharp" },
          },
        },
        header: { announcement: true, layout: "split", sticky: false },
        presentation: { product_card_variant: "minimal" },
        homepage: { sections: [section("hero", 10, "campaign_hero")] },
      },
      presentation_defaults: { purchase_variant: "compact" },
      homepage_defaults: { sections: [section("faq", 20, "faq")] },
    };

    const preview = previewPresetConfig(config, preset);
    expect(preview.theme.tokens.colors.accent).toBe("#000000");
    expect(preview.theme.tokens.colors.ink).toBe("#111111");
    expect(preview.theme.tokens.typography.display).toBe("Playfair");
    expect(preview.theme.tokens.shape.radius).toBe("sharp");
    expect(preview.header.layout).toBe("split");
    expect(preview.presentation).toMatchObject({ product_card_variant: "editorial", purchase_variant: "compact" });
    expect(preview.homepage.sections.map((item) => item.id)).toEqual(["faq"]);
    expect(preview.vertical.key).toBe("fashion");
    expect(config.theme.tokens.colors.accent).toBe("#c0392b");
    expect(config.homepage.sections).toEqual([]);
  });

  it("applies per-preset quote mode without letting unknown values through", () => {
    const config = baseConfig();
    const auto = previewPresetConfig(config, { key: "beauty", name: "Beauty", version: "1", purchase_defaults: { quote_mode: "auto" } });
    expect(auto.purchase.quote_mode).toBe("auto");
    const invalid = previewPresetConfig(config, { key: "beauty", name: "Beauty", version: "1", purchase_defaults: { quote_mode: "sometimes" } });
    expect(invalid.purchase.quote_mode).toBe("required");
  });

  it("falls back to the current blocks when the preset has no defaults", () => {
    const config = baseConfig();
    const preview = previewPresetConfig(config, { key: "general", name: "General", version: "1" });
    expect(preview.header).toBe(config.header);
    expect(preview.footer).toBe(config.footer);
    expect(preview.policies).toBe(config.policies);
    expect(preview.homepage).toBe(config.homepage);
  });

  it("builds preset gallery metadata from explicit, derived and built-in sources", () => {
    const explicit = presetGallery({
      key: "beauty", name: "Beauty", version: "1", swatches: ["#aaa", "#bbb", "#ccc"], layout_hints: ["Routine"],
    });
    expect(explicit.swatches).toEqual(["#aaa", "#bbb", "#ccc"]);
    expect(explicit.layout_hints).toEqual(["Routine"]);

    const derived = presetGallery({
      key: "fashion", name: "", version: "1",
      theme_defaults: { theme: { tokens: { colors: { ink: "#111111", paper: "#eeeeee", accent: "#cc0000" } } } },
    });
    expect(derived.name).toBe("Fashion & Apparel");
    expect(derived.swatches).toEqual(["#111111", "#eeeeee", "#cc0000"]);

    const fallback = presetGallery({ key: "unknown_vertical", name: "", version: "1" });
    expect(fallback.name).toBe("Unknown Vertical");
    expect(fallback.description).toBe("Presentation defaults for this vertical.");
    expect(fallback.swatches).toEqual(["#1E2420", "#F5F5F0", "#883C29"]);
    expect(fallback.layout_hints).toEqual([]);
  });
});

describe("validateThemeImport", () => {
  it("rejects non-objects, missing version, theme, tokens and scope fields", () => {
    expect(validateThemeImport(null)).toEqual({ ok: false, error: "Import must be a theme configuration object." });
    expect(validateThemeImport([])).toMatchObject({ ok: false });
    expect(validateThemeImport({})).toEqual({ ok: false, error: "Import is missing a numeric version." });
    expect(validateThemeImport({ version: Number.NaN })).toMatchObject({ ok: false });
    expect(validateThemeImport({ version: 1 })).toEqual({ ok: false, error: "Import is missing the theme block." });
    expect(validateThemeImport({ version: 1, theme: {} })).toEqual({ ok: false, error: "Import theme.key is required." });
    expect(validateThemeImport({ version: 1, theme: { key: "x" } })).toEqual({ ok: false, error: "Import theme.tokens is required." });
    expect(validateThemeImport({ version: 1, theme: { key: "x", tokens: {} } })).toEqual({ ok: false, error: "Import is missing the scope block." });
    expect(validateThemeImport({ version: 1, theme: { key: "x", tokens: {} }, scope: {} }))
      .toEqual({ ok: false, error: "Import scope.store_id is required." });
  });

  it("validates the wrapped config shape and returns the config", () => {
    const config = baseConfig();
    expect(validateThemeImport({ config })).toEqual({ ok: true, config });
    expect(validateThemeImport(config)).toEqual({ ok: true, config });
    expect(validateThemeImport({ version: 1, theme: { key: "x", tokens: {} }, scope: { store_id: "s", channel_id: "c", locale: "", currency: "USD" } }))
      .toEqual({ ok: false, error: "Import scope.locale is required." });
  });
});
