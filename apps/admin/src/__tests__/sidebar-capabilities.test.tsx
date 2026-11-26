import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { Sidebar } from "@/layouts/sidebar";
import { I18nProvider } from "@/providers/i18n-provider";

const authState = vi.hoisted(() => ({
  user: { role: "admin" } as { role?: string; permissions?: string[] } | null,
  logout: vi.fn(),
}));

vi.mock("@/hooks/use-auth", () => ({
  useAuth: () => ({ user: authState.user, logout: authState.logout }),
}));

vi.mock("@/services/extensions.service", () => ({
  extensionsService: { views: vi.fn().mockResolvedValue([]) },
}));

vi.mock("next/navigation", () => ({
  usePathname: () => "/",
}));

vi.mock("next/link", () => ({
  default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a>,
}));

afterEach(() => {
  authState.user = { role: "admin" };
  cleanup();
});

function renderSidebar(role: string) {
  authState.user = { role };
  render(<I18nProvider><Sidebar collapsed={false} onToggle={() => undefined} /></I18nProvider>);
}

function link(name: string) {
  return screen.queryByRole("link", { name });
}

describe("sidebar capability filtering", () => {
  it("shows owner/admin configuration and settings without requiring a settings capability", () => {
    renderSidebar("admin");
    expect(link("Configuration")).toBeInTheDocument();
    expect(link("Settings")).toBeInTheDocument();
    expect(link("AI copilot queue")).toBeInTheDocument();
  });

  it("shows finance and subscription surfaces to finance only", () => {
    renderSidebar("finance");
    expect(link("Finance")).toBeInTheDocument();
    expect(link("Tax rules")).toBeInTheDocument();
    expect(link("Subscriptions")).toBeInTheDocument();
    expect(link("Configuration")).not.toBeInTheDocument();
    expect(link("Settings")).not.toBeInTheDocument();
    expect(link("Campaign automation")).not.toBeInTheDocument();
    expect(link("Users")).not.toBeInTheDocument();
    expect(link("AI copilot queue")).not.toBeInTheDocument();
  });

  it("shows campaign and content surfaces to marketers", () => {
    renderSidebar("marketer");
    expect(link("Campaign automation")).toBeInTheDocument();
    expect(link("Media library")).toBeInTheDocument();
    expect(link("Pages")).toBeInTheDocument();
    expect(link("AI copilot queue")).toBeInTheDocument();
    expect(link("Finance")).not.toBeInTheDocument();
    expect(link("Tax rules")).not.toBeInTheDocument();
    expect(link("Configuration")).not.toBeInTheDocument();
  });

  it("hides capability-gated surfaces from a viewer", () => {
    renderSidebar("viewer");
    expect(link("Orders")).not.toBeInTheDocument();
    expect(link("Pages")).not.toBeInTheDocument();
    expect(link("Finance")).not.toBeInTheDocument();
    expect(link("Settings")).not.toBeInTheDocument();
    expect(link("Configuration")).not.toBeInTheDocument();
  });
});
