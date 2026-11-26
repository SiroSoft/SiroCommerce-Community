import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { Builder } from "@/app/(dashboard)/storefront/theme/page";
import type { ThemeConfig } from "@/services/storefront-theme.service";

const state = vi.hoisted(() => ({
  optionsData: [[], []],
  revisionsData: [],
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

const config: ThemeConfig = {
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
  homepage: { sections: [] },
  presentation: { product_card_variant: "editorial", product_detail_variant: "editorial", purchase_variant: "standard" },
  purchase: { quote_mode: "required" },
  policies: { shipping_url: "/shipping", returns_url: "/returns", support_url: "/contact" },
  experiments: [],
  version: 1,
};

function renderBuilder() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <Builder config={config} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  Object.values(state).forEach((value) => {
    if (typeof value === "function" && "mockReset" in value) (value as ReturnType<typeof vi.fn>).mockReset();
  });
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("theme builder import boundary", () => {
  it("reports invalid JSON without mutating the draft or saving", async () => {
    renderBuilder();
    const file = { text: vi.fn().mockResolvedValue("this is not json") } as unknown as File;
    const input = document.querySelector('input[aria-label="Import theme draft JSON"]') as HTMLInputElement;

    fireEvent.change(input, { target: { files: [file] } });

    expect(await screen.findByRole("alert")).toHaveTextContent("Import file is not valid JSON.");
    expect(state.saveMutate).not.toHaveBeenCalled();
    expect(screen.getByText(/Draft v1/)).toBeInTheDocument();
  });
});
