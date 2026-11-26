import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { Builder } from "@/app/(dashboard)/storefront/theme/page";
import {
  HOMEPAGE_SECTION_TYPES,
  addHomepageSection,
  moveHomepageSection,
  validateThemeImport,
  type HomepageSection,
  type ThemeConfig,
  type VerticalPreset,
} from "@/services/storefront-theme.service";
import { AdminApiError } from "@/services/api-errors";

const state = vi.hoisted(() => ({
  optionsData: [] as unknown,
  revisionsData: [] as unknown,
  saveMutate: vi.fn(),
  previewMutate: vi.fn(),
  applyMutate: vi.fn(),
  publishMutate: vi.fn(),
  restoreMutate: vi.fn(),
}));

vi.mock("@/hooks/use-storefront-theme", () => ({
  useStorefrontThemeOptions: () => ({ data: state.optionsData, isLoading: false }),
  useStorefrontRevisions: () => ({ data: state.revisionsData }),
  useSaveStorefrontTheme: () => ({ mutate: state.saveMutate, isPending: false }),
  usePreviewStorefrontTheme: () => ({ mutate: state.previewMutate, isPending: false }),
  useApplyStorefrontPreset: () => ({ mutate: state.applyMutate, isPending: false }),
  usePublishStorefrontTheme: () => ({ mutate: state.publishMutate, isPending: false }),
  useRestoreStorefrontRevision: () => ({ mutate: state.restoreMutate, isPending: false }),
}));

const presets: VerticalPreset[] = [
  { key: "general", name: "General", version: "1.0.0" },
  { key: "fnb", name: "Food & Beverage", version: "1.0.0" },
  { key: "beauty", name: "Beauty & Personal Care", version: "1.0.0" },
  { key: "b2b", name: "B2B & Wholesale", version: "1.0.0" },
];

function themeConfig(options: { ink?: string; paper?: string; extends?: string; sections?: HomepageSection[] } = {}): ThemeConfig {
  const base: ThemeConfig = {
    scope: { store_id: "store-1", channel_id: "web", locale: "en", currency: "USD" },
    theme: {
      key: "editorial",
      version: "1.0.0",
      schema_version: 1,
      tokens: {
        colors: { ink: "#1E2420", muted: "#536057", paper: "#F5F5F0", surface: "#FFFEFA", accent: "#883C29", accent_dark: "#693021" },
        typography: { display: "editorial", body: "system", density: "comfortable" },
        shape: { radius: "soft", card: "bordered" },
      },
    },
    vertical: { key: "general", version: "1.0.0" },
    brand: { name: "SiroCommerce", wordmark: "siro/commerce", logo_url: null, favicon_url: null },
    navigation: { menu_key: "primary", items: [] },
    header: { announcement: true, layout: "standard", sticky: false },
    footer: { layout: "standard", show_help: true, show_policies: true, columns: [] },
    homepage: { sections: options.sections ?? [] },
    presentation: { product_card_variant: "editorial", product_detail_variant: "editorial", purchase_variant: "standard" },
    purchase: { quote_mode: "required" },
    policies: { shipping_url: "/shipping", returns_url: "/returns", support_url: "/contact" },
    experiments: [],
    version: 1,
  };
  return {
    ...base,
    theme: {
      ...base.theme,
      extends: options.extends,
      tokens: {
        ...base.theme.tokens,
        colors: { ...base.theme.tokens.colors, ink: options.ink ?? base.theme.tokens.colors.ink, paper: options.paper ?? base.theme.tokens.colors.paper },
      },
    },
  };
}

function renderBuilder(config: ThemeConfig = themeConfig()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><Builder config={config} /></QueryClientProvider>);
}

beforeEach(() => {
  state.optionsData = [[], presets];
  state.revisionsData = [];
  Object.values(state).forEach((value) => { if (typeof value === "function" && "mockReset" in value) (value as ReturnType<typeof vi.fn>).mockReset(); });
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  delete (URL as unknown as { createObjectURL?: unknown }).createObjectURL;
  delete (URL as unknown as { revokeObjectURL?: unknown }).revokeObjectURL;
});

describe("storefront theme builder", () => {
  it("renders the preset gallery including new vertical presets", () => {
    renderBuilder();
    const gallery = screen.getByRole("group", { name: "Vertical presets" });
    const food = within(gallery).getByRole("button", { name: "Food & Beverage preset" });
    expect(food).toHaveAttribute("aria-pressed", "false");
    expect(within(food).getByText(/appetite-led food and beverage layout/i)).toBeInTheDocument();
    expect(within(gallery).getByRole("button", { name: "B2B & Wholesale preset" })).toBeInTheDocument();
    expect(within(gallery).getByRole("button", { name: "Beauty & Personal Care preset" })).toBeInTheDocument();
  });

  it("previews a selected preset and applies it through the API after confirmation", async () => {
    renderBuilder();
    fireEvent.click(screen.getByRole("button", { name: "Food & Beverage preset" }));
    expect(state.previewMutate).toHaveBeenCalledTimes(1);
    expect(screen.getByRole("button", { name: "Food & Beverage preset" })).toHaveAttribute("aria-pressed", "true");

    fireEvent.click(screen.getByRole("button", { name: "Apply preset" }));
    const dialog = await screen.findByRole("dialog", { name: "Apply preset" });
    expect(dialog).toHaveAccessibleDescription(/will be replaced/i);
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));

    expect(state.applyMutate).toHaveBeenCalledTimes(1);
    expect(state.applyMutate.mock.calls[0][0]).toMatchObject({ expected_version: 1, vertical_key: "fnb" });
  });

  it("warns when ink and paper have low WCAG contrast and marks child themes", () => {
    renderBuilder(themeConfig({ ink: "#777777", paper: "#888888", extends: "general" }));
    expect(screen.getByText(/low contrast between ink and paper/i)).toBeInTheDocument();
    expect(screen.getByText(/child theme/i)).toBeInTheDocument();
  });

  it("adds new section types and reorders them by order", () => {
    expect(HOMEPAGE_SECTION_TYPES).toContain("banner_grid");
    expect(HOMEPAGE_SECTION_TYPES).toContain("usp_strip");
    expect(HOMEPAGE_SECTION_TYPES).toContain("faq");

    const one = addHomepageSection([], "banner_grid");
    expect(one[0]).toMatchObject({ type: "banner_grid", enabled: true, order: 10 });
    const two = addHomepageSection(one, "faq");
    const moved = moveHomepageSection(two, two[1].id, "up");
    const ordered = moved.slice().sort((a, b) => a.order - b.order);
    expect(ordered.map((section) => section.type)).toEqual(["faq", "banner_grid"]);
    expect(ordered.map((section) => section.order)).toEqual([10, 20]);
  });

  it("exports the current draft as a JSON blob download", async () => {
    const createObjectURL = vi.fn((_blob: Blob) => "blob:theme");
    const revokeObjectURL = vi.fn();
    Object.defineProperty(URL, "createObjectURL", { configurable: true, value: createObjectURL });
    Object.defineProperty(URL, "revokeObjectURL", { configurable: true, value: revokeObjectURL });
    const click = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => {});

    renderBuilder();
    fireEvent.click(screen.getByRole("button", { name: "Export" }));

    await waitFor(() => expect(createObjectURL).toHaveBeenCalledTimes(1));
    const blob = createObjectURL.mock.calls[0][0] as Blob;
    expect(blob).toBeInstanceOf(Blob);
    expect(blob.type).toBe("application/json");
    expect(click).toHaveBeenCalled();
    expect(revokeObjectURL).toHaveBeenCalledWith("blob:theme");
  });

  it("imports a JSON file into the draft without auto-saving", async () => {
    renderBuilder();
    const imported = themeConfig();
    imported.brand = { ...imported.brand, name: "Imported Store" };
    const file = { text: vi.fn().mockResolvedValue(JSON.stringify({ exported_at: "2026-01-01T00:00:00Z", config: imported })) } as unknown as File;
    const input = document.querySelector('input[type="file"]') as HTMLInputElement;

    fireEvent.change(input, { target: { files: [file] } });

    await waitFor(() => expect(screen.getByDisplayValue("Imported Store")).toBeInTheDocument());
    expect(state.saveMutate).not.toHaveBeenCalled();
    expect(screen.getByText(/imported into the draft/i)).toBeInTheDocument();
  });

  it("rejects imports without the required version, theme, and scope blocks", () => {
    expect(validateThemeImport({ theme: { key: "x", tokens: {} }, scope: {} }).ok).toBe(false);
    expect(validateThemeImport({ version: 1, theme: {}, scope: {} }).ok).toBe(false);
    expect(validateThemeImport({ version: 1, theme: { key: "x", tokens: {} }, scope: { store_id: "s" } }).ok).toBe(false);
    expect(validateThemeImport(themeConfig()).ok).toBe(true);
  });

  it("composes homepage sections, footer columns and experiments", async () => {
    const sections = addHomepageSection([], "campaign_hero");
    renderBuilder(themeConfig({ sections }));
    expect(screen.getAllByText("Campaign Hero").length).toBeGreaterThan(0);
    fireEvent.change(screen.getByLabelText("Campaign Hero title"), { target: { value: "Summer drop" } });
    fireEvent.change(screen.getByLabelText("Section order"), { target: { value: "30" } });
    fireEvent.click(screen.getByRole("switch", { name: /Enable Campaign Hero/ }));
    fireEvent.click(screen.getByRole("button", { name: "Add section" }));
    expect(screen.getAllByLabelText("Section order")).toHaveLength(2);
    fireEvent.click(screen.getAllByRole("button", { name: "Move section up" })[1]);
    fireEvent.click(screen.getByRole("button", { name: "Add column" }));
    fireEvent.change(screen.getByPlaceholderText("Column title"), { target: { value: "Shop" } });
    fireEvent.change(screen.getByPlaceholderText("Sale | /sale"), { target: { value: "Sale | /sale" } });
    expect(screen.getByDisplayValue("Sale | /sale")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Remove column" }));
    const experiments = document.getElementById("experiments-json") as HTMLTextAreaElement;
    fireEvent.change(experiments, { target: { value: "[{\"key\":\"hero\",\"variants\":[\"a\",\"b\"]}]" } });
    expect(screen.queryByText("Experiments must be valid JSON.")).not.toBeInTheDocument();
    fireEvent.change(experiments, { target: { value: "not-json" } });
    expect(await screen.findByText("Experiments must be valid JSON.")).toBeInTheDocument();
  });

  it("saves, previews and publishes drafts with confirmation", async () => {
    state.saveMutate.mockImplementation((_body: unknown, options?: { onSuccess?: (value: unknown) => void }) => options?.onSuccess?.({ ...themeConfig(), version: 2 }));
    state.previewMutate.mockImplementation((_body: unknown, options?: { onSuccess?: (value: unknown) => void }) => options?.onSuccess?.(themeConfig()));
    state.publishMutate.mockImplementation((_value: unknown, options?: { onSuccess?: (value: unknown) => void }) => options?.onSuccess?.(themeConfig()));
    renderBuilder();
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    expect(state.saveMutate).toHaveBeenCalledWith(expect.objectContaining({ expected_version: 1, brand: expect.objectContaining({ name: "SiroCommerce" }) }), expect.any(Object));
    expect(await screen.findByText("Draft saved.")).toBeInTheDocument();
    expect(screen.getByText("Draft v2")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Preview state" }));
    expect(state.previewMutate).toHaveBeenCalledTimes(1);
    expect(await screen.findByText("Preview generated.")).toBeInTheDocument();
    expect(screen.getAllByText("Preview state").length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole("button", { name: "Publish" }));
    const dialog = await screen.findByRole("dialog", { name: "Publish theme" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    expect(state.publishMutate).toHaveBeenCalledTimes(1);
    expect(await screen.findByText("Theme published.")).toBeInTheDocument();
  });

  it("exposes native theme package JSON and archive controls", () => {
    renderBuilder();
    expect(screen.getByLabelText("Import Siro theme package JSON")).toBeInTheDocument();
    expect(screen.getByLabelText("Import Siro theme archive")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Export Siro theme/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Export theme archive/i })).toBeInTheDocument();
  });

  it("saves the configured quote mode with the theme", async () => {
    state.saveMutate.mockImplementation((_body: unknown, options?: { onSuccess?: (value: unknown) => void }) => options?.onSuccess?.({ ...themeConfig(), version: 2 }));
    renderBuilder({ ...themeConfig(), purchase: { quote_mode: "auto" } });
    expect(screen.getByLabelText("Quote mode")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    expect(state.saveMutate).toHaveBeenCalledWith(expect.objectContaining({ purchase: { quote_mode: "auto" } }), expect.any(Object));
  });

  it("requires a brand name and surfaces conflict and permission errors", async () => {
    renderBuilder();
    fireEvent.change(screen.getByDisplayValue("SiroCommerce"), { target: { value: "" } });
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    expect(screen.getByText("Brand name is required before saving.")).toBeInTheDocument();
    expect(state.saveMutate).not.toHaveBeenCalled();
    cleanup();
    renderBuilder();
    state.saveMutate.mockImplementation((_body: unknown, options?: { onError?: (error: unknown) => void }) => options?.onError?.(new AdminApiError({ message: "stale", status: 409 })));
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    expect(await screen.findByText(/This draft is stale/)).toBeInTheDocument();
    state.saveMutate.mockImplementation((_body: unknown, options?: { onError?: (error: unknown) => void }) => options?.onError?.(new AdminApiError({ message: "denied", status: 403 })));
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    expect(await screen.findByText(/do not have permission/)).toBeInTheDocument();
  });

  it("restores a revision after confirmation and renders diff state", async () => {
    state.revisionsData = [
      { id: "r2", version: 2, action: "publish", created_at: "2026-01-02T00:00:00Z" },
      { id: "r1", version: 1, action: "restore", created_at: "2026-01-01T00:00:00Z" },
    ];
    state.restoreMutate.mockImplementation((_value: unknown, options?: { onSuccess?: (value: unknown) => void }) => options?.onSuccess?.(themeConfig()));
    renderBuilder();
    expect(screen.getByText(/v2 · publish/)).toBeInTheDocument();
    expect(screen.getByText(/v1 · restore/)).toBeInTheDocument();
    fireEvent.click(screen.getAllByRole("button", { name: "Restore" })[0]);
    const dialog = await screen.findByRole("dialog", { name: "Restore revision" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    expect(state.restoreMutate).toHaveBeenCalledWith(expect.objectContaining({ revision: "r2", expected_version: 1 }), expect.any(Object));
    expect(await screen.findByText("Revision restored as a new draft.")).toBeInTheDocument();
  });
});
