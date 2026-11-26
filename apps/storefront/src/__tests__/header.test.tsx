import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { Header } from "@/components/Header";

vi.mock("next/link", () => ({ default: ({ children, ...props }: React.PropsWithChildren<{ href: string }>) => <a {...props} href={props.href}>{children}</a> }));
vi.mock("next/navigation", () => ({ usePathname: () => "/category/new" }));

afterEach(() => { document.body.innerHTML = ""; });

describe("storefront header", () => {
  it("keeps the commerce actions discoverable and renders a configured brand lockup", () => {
    render(<Header config={{ brand: { name: "Field Notes", wordmark: "field/notes", logoUrl: "https://example.com/logo.svg" }, navigation: { menuKey: "primary", items: [{ label: "New arrivals", href: "/category/new" }] } }} />);

    expect(document.querySelector(".brand-logo")).toHaveAttribute("src", "https://example.com/logo.svg");
    expect(screen.getByRole("link", { name: "Field Notes home" })).toHaveAttribute("href", "/");
    expect(screen.getByRole("link", { name: /Search products/i })).toHaveAttribute("href", "/search");
    expect(screen.getByRole("link", { name: /Open shopping bag/i })).toHaveAttribute("href", "/cart");
    expect(screen.getByRole("link", { name: "Account" })).toHaveAttribute("href", "/account");
    expect(screen.getByRole("link", { name: "New arrivals" })).toHaveAttribute("href", "/category/new");
    expect(screen.getByRole("link", { name: "New arrivals" })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("combobox", { name: "Language" })).toHaveDisplayValue("English");
    expect(screen.getByRole("combobox", { name: "Currency" })).toHaveDisplayValue("USD $");
  });

  it("keeps categories directly in the desktop nav and a keyboard accordion on mobile", () => {
    render(<Header />);
    expect(screen.getByRole("link", { name: "Apparel" })).toHaveAttribute("href", "/category/apparel");

    fireEvent.click(screen.getByRole("button", { name: "Open navigation menu" }));
    const trigger = screen.getAllByRole("button", { name: "Collection" }).find((button) => button.getAttribute("aria-controls") === "sf-mobile-categories");
    expect(trigger).toBeDefined();
    expect(trigger).toHaveAttribute("aria-expanded", "false");

    fireEvent.click(trigger!);
    expect(trigger).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByRole("link", { name: "Apparel Collection" })).toHaveAttribute("href", "/category/apparel");

    fireEvent.keyDown(trigger!, { key: "Escape" });
    expect(trigger).toHaveAttribute("aria-expanded", "false");
  });

  it("adds a category accordion inside the existing mobile menu", () => {
    render(<Header />);
    fireEvent.click(screen.getByRole("button", { name: "Open navigation menu" }));
    const mobileTrigger = screen.getAllByRole("button", { name: "Collection" }).find((button) => button.getAttribute("aria-controls") === "sf-mobile-categories");
    expect(mobileTrigger).toBeDefined();
    fireEvent.click(mobileTrigger!);
    expect(mobileTrigger).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByRole("link", { name: "Apparel Collection" })).toHaveAttribute("href", "/category/apparel");
  });
});
