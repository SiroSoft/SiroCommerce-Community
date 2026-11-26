import "@testing-library/jest-dom/vitest";
import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { FooterConfig, HeaderConfig } from "@/theme/types";

vi.mock("next/link", () => ({
  default: ({ children, ...props }: React.PropsWithChildren<{ href: string }>) => <a {...props} href={props.href}>{children}</a>,
}));
vi.mock("next/navigation", () => ({ usePathname: () => "/account" }));

import { Header } from "@/components/Header";
import { Footer } from "@/components/Footer";
import { defaultTheme } from "@/theme/defaults";
import { ThemeProvider, useTheme } from "@/theme/ThemeProvider";
import { PresentationProvider, useProductPresentationSlots } from "@/presentation/context";
import { PresentationVariantProvider, useCardVariant, useDetailVariant, useListVariant, usePurchaseVariant } from "@/presentation/variant-context";

const navigation = {
  menuKey: "primary",
  items: [
    { label: "New arrivals", href: "/category/new" },
    { label: "Apparel", href: "/category/apparel" },
    { label: "Repeat", href: "/category/apparel" },
    { label: "Journal", href: "/pages/journal" },
  ],
};

const brand = { name: "Field Notes", wordmark: "field/notes", logoUrl: "https://example.com/logo.svg" };

function headerConfig(layout: HeaderConfig["layout"], overrides: Partial<HeaderConfig> = {}): { brand: typeof brand; navigation: typeof navigation; header: HeaderConfig } {
  return { brand, navigation, header: { layout, announcement: true, sticky: false, ...overrides } };
}

afterEach(() => {
  document.body.innerHTML = "";
});

describe("header layout variants", () => {
  it.each(["minimal", "centered", "split"] as const)("renders the %s layout with a data hook", (layout) => {
    const { container } = render(<Header config={headerConfig(layout, { announcement: false, sticky: true })} />);
    const header = container.querySelector("header")!;
    expect(header).toHaveAttribute("data-header-layout", layout);
    expect(header).toHaveClass(`header-${layout}`);
    expect(header).toHaveStyle({ position: "sticky" });
    expect(screen.queryByText("Complimentary shipping on qualifying orders")).toBeNull();
    expect(screen.getByRole("link", { name: "Field Notes home" })).toHaveAttribute("href", "/");
    if (layout === "minimal") {
      expect(container.querySelector("#primary-navigation")).toBeNull();
    } else {
      expect(container.querySelector("#primary-navigation")).not.toBeNull();
      expect(screen.getAllByRole("link", { name: "Apparel" })[0]).toHaveAttribute("href", "/category/apparel");
    }
  });

  it("falls back to the standard layout for an unknown key", () => {
    const { container } = render(<Header config={headerConfig("bogus" as HeaderConfig["layout"])} />);
    expect(container.querySelector("header")).toHaveAttribute("data-header-layout", "standard");
    expect(screen.getByText("field/notes")).toBeInTheDocument();
  });

  it("uses a single wordmark when the brand name normalizes to the same value", () => {
    const { container } = render(<Header config={{ brand: { name: "Field Notes", wordmark: "Field Notes", logoUrl: "/logo.svg" }, navigation }} />);
    expect(container.querySelector(".brand-name")).toBeNull();
    expect(container.querySelector(".wordmark")).toHaveTextContent("Field Notes");
  });
});

function footerConfig(layout: FooterConfig["layout"], overrides: Partial<FooterConfig> = {}): { brand: typeof brand; navigation: typeof navigation; policies: { shippingUrl: string; returnsUrl: string; supportUrl: string }; footer: FooterConfig } {
  return {
    brand,
    navigation,
    policies: { shippingUrl: "/pages/shipping", returnsUrl: "/pages/returns", supportUrl: "/pages/support" },
    footer: { layout, show_help: true, show_policies: true, columns: [], ...overrides },
  };
}

describe("footer layout variants", () => {
  it("renders the standard footer with policies and custom columns", () => {
    const { container } = render(<Footer config={footerConfig("standard", { columns: [{ title: "About", links: [{ label: "Story", href: "/pages/about" }] }] })} />);
    expect(container.querySelector("footer")).toHaveAttribute("data-footer-layout", "standard");
    expect(screen.getByRole("heading", { name: "Shop" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Help" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Shipping policy" })).toHaveAttribute("href", "/pages/shipping");
    expect(screen.getByRole("link", { name: "Returns policy" })).toHaveAttribute("href", "/pages/returns");
    expect(screen.getByRole("link", { name: "Support policy" })).toHaveAttribute("href", "/pages/support");
    expect(screen.getByRole("link", { name: "Story" })).toHaveAttribute("href", "/pages/about");
    expect(screen.getByRole("button", { name: "Cookie settings" })).toBeInTheDocument();
  });

  it("renders the minimal footer without nav columns", () => {
    const { container } = render(<Footer config={footerConfig("minimal")} />);
    expect(container.querySelector("footer")).toHaveClass("footer-minimal");
    expect(screen.queryByRole("heading", { name: "Shop" })).toBeNull();
    expect(container.querySelector(".footer-bottom")).not.toBeNull();
  });

  it("renders the mega footer and can hide help and policies", () => {
    render(<Footer config={footerConfig("mega", { show_help: false, show_policies: false, columns: [{ title: "Support", links: [{ label: "Contact", href: "/pages/contact" }] }] })} />);
    expect(screen.getByRole("heading", { name: "Shop" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Contact" })).toHaveAttribute("href", "/pages/contact");
    expect(screen.queryByRole("heading", { name: "Help" })).toBeNull();
    expect(screen.queryByRole("heading", { name: "Policies" })).toBeNull();
  });

  it("falls back to standard for an unknown footer layout", () => {
    const { container } = render(<Footer config={footerConfig("bogus" as FooterConfig["layout"])} />);
    expect(container.querySelector("footer")).toHaveAttribute("data-footer-layout", "standard");
  });

  it("renders support email links when configured", async () => {
    vi.resetModules();
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SUPPORT_EMAIL", "help@example.test");
    const { Footer: ConfiguredFooter } = await import("@/components/Footer");
    const { container } = render(<ConfiguredFooter config={footerConfig("standard")} />);
    expect(container.querySelector('a[href^="mailto:help@example.test"]')).not.toBeNull();
    vi.unstubAllEnvs();
  });
});

describe("theme and presentation providers", () => {
  it("provides the injected theme and falls back to the default theme", () => {
    function Probe() {
      const theme = useTheme();
      return <span data-testid="theme">{theme.key}:{theme.tokens.typography.density}</span>;
    }
    const theme = { ...defaultTheme, key: "noir", tokens: { ...defaultTheme.tokens, typography: { ...defaultTheme.tokens.typography, density: "compact" as const } } };
    const { unmount } = render(<ThemeProvider theme={theme}><Probe /></ThemeProvider>);
    expect(screen.getByTestId("theme")).toHaveTextContent("noir:compact");
    unmount();

    render(<ThemeProvider><Probe /></ThemeProvider>);
    expect(screen.getByTestId("theme")).toHaveTextContent(`${defaultTheme.key}:`);
  });

  it("provides product presentation slots with an empty default", () => {
    function Slots() {
      const slots = useProductPresentationSlots();
      return <span data-testid="slots">{Object.keys(slots.card).length + Object.keys(slots.detail).length}</span>;
    }
    const { unmount } = render(<Slots />);
    expect(screen.getByTestId("slots")).toHaveTextContent("0");
    unmount();

    render(<PresentationProvider card={{ beforeMedia: () => <span>Media</span> }} detail={{}}><Slots /></PresentationProvider>);
    expect(screen.getByTestId("slots")).toHaveTextContent("1");
  });

  it("provides presentation variants with defaults and overrides", () => {
    function Variants() {
      return <span data-testid="variants">{useCardVariant()}|{useDetailVariant()}|{useListVariant()}|{usePurchaseVariant()}</span>;
    }
    const { unmount } = render(<Variants />);
    expect(screen.getByTestId("variants")).toHaveTextContent("editorial|editorial|list-editorial|standard");
    unmount();

    render(<PresentationVariantProvider card="spotlight" detail="focused" list="list-grid-wide" purchase="compact"><Variants /></PresentationVariantProvider>);
    expect(screen.getByTestId("variants")).toHaveTextContent("spotlight|focused|list-grid-wide|compact");
  });
});
