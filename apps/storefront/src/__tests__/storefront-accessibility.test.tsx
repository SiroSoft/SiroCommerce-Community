import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { Header } from "@/components/Header";
import { Footer } from "@/components/Footer";
import { SearchForm } from "@/components/SearchForm";

vi.mock("next/link", () => ({ default: ({ children, ...props }: React.PropsWithChildren<{ href: string }>) => <a {...props} href={props.href}>{children}</a> }));
vi.mock("next/navigation", () => ({ usePathname: () => "/", useRouter: () => ({ push: () => undefined }) }));

describe("storefront buyer accessibility", () => {
  afterEach(() => { document.body.innerHTML = ""; });

  it("uses real navigation for the bag and labels search", () => {
    render(<><Header /><SearchForm /></>);
    expect(screen.getByRole("link", { name: /open shopping bag/i })).toHaveAttribute("href", "/cart");
    expect(screen.getByRole("textbox", { name: /search the collection/i })).toBeTruthy();
  });

  it("keeps footer navigation semantic and always exposes privacy controls", () => {
    render(<Footer config={{ brand: { name: "Demo store", wordmark: "demo/shop" }, navigation: { menuKey: "primary", items: [{ label: "New arrivals", href: "/category/new" }] }, policies: {} }} />);
    expect(screen.getByRole("contentinfo")).toHaveAccessibleName("Useful things for everyday rituals.");
    expect(screen.getByRole("heading", { name: "Useful things for everyday rituals." })).toBeTruthy();
    expect(screen.getByRole("navigation", { name: "Shop" })).toContainElement(screen.getByRole("link", { name: "New arrivals" }));
    expect(screen.getByRole("navigation", { name: "Policies" })).toBeTruthy();
    expect(screen.getByRole("link", { name: "Privacy policy" })).toHaveAttribute("href", "/pages/privacy");
    expect(screen.getByRole("link", { name: "Cookie policy" })).toHaveAttribute("href", "/pages/cookie-policy");
    expect(screen.getByRole("button", { name: "Cookie settings" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Shipping quote" })).toHaveAttribute("href", "/quote");
    // Support email is only rendered when a real address is configured.
    expect(screen.queryByRole("link", { name: "Email our support team" })).toBeNull();
    expect(screen.queryByText(/sirocommerce\.test/i)).toBeNull();
  });

  it("hides the quote entry point when the store does not require a quote step", () => {
    render(<Footer config={{ brand: { name: "Demo store" }, navigation: { menuKey: "primary", items: [] }, policies: {}, purchase: { quoteMode: "off" } }} />);
    expect(screen.queryByRole("link", { name: "Shipping quote" })).toBeNull();
  });

  it("moves focus into the mobile menu and returns it to the trigger on escape", () => {
    render(<Header />);
    const menu = screen.getByRole("button", { name: "Open navigation menu" });
    fireEvent.click(menu);
    expect(screen.getByRole("link", { name: "Apparel" })).toHaveFocus();
    expect(menu).toHaveAttribute("aria-expanded", "true");
    fireEvent.keyDown(document, { key: "Escape" });
    expect(menu).toHaveFocus();
    expect(menu).toHaveAttribute("aria-expanded", "false");
    expect(document.body.style.overflow).toBe("");
  });

  it("renders navigation and search copy from the selected locale", async () => {
    vi.resetModules();
    vi.stubEnv("STOREFRONT_LOCALE", "vi");
    const { Header: LocalizedHeader } = await import("@/components/Header");
    const { SearchForm: LocalizedSearchForm } = await import("@/components/SearchForm");
    render(<><LocalizedHeader /><LocalizedSearchForm /></>);
    expect(screen.getAllByRole("link", { name: /Apparel|Thời trang/ }).length).toBeGreaterThan(0);
    expect(screen.getAllByPlaceholderText(/Search the collection|Tìm kiếm trong bộ sưu tập/).length).toBeGreaterThan(0);
    vi.unstubAllEnvs();
  });
});
