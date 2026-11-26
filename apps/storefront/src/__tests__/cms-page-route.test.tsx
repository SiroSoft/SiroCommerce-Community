import "@testing-library/jest-dom/vitest";
import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

vi.mock("next/navigation", () => ({
  notFound: () => {
    const error = new Error("NEXT_NOT_FOUND") as Error & { digest?: string };
    error.digest = "NEXT_NOT_FOUND";
    throw error;
  },
}));

vi.mock("@/lib/request-locale", () => ({
  requestScope: async () => ({ store_id: "store", channel_id: "web", locale: "en", currency: "USD" }),
  requestLocale: async () => "en",
  requestCurrency: async () => "USD",
}));

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

function page(slug: string, overrides: Record<string, unknown> = {}) {
  return {
    success: true,
    message: "Page",
    data: {
      id: `page-${slug}`,
      slug,
      title: "About us",
      body: "<p>Body copy</p>",
      status: "published",
      updated_at: "2026-11-01T10:00:00Z",
      blocks: [
        { id: "b1", type: "rich_text", content: "<p>Hello <strong>there</strong></p>", status: "published", sort: 1 },
        { id: "b2", type: "rich_text", content: '<p onmouseover="alert(1)">Safe<script>alert(2)</script></p>', status: "published", sort: 2 },
      ],
      seo: { title: "About page", description: "About description", canonical: null, robots: "index,follow" },
      ...overrides,
    },
  };
}

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe("CMS page route", () => {
  it("renders published blocks from the scoped page endpoint", async () => {
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_URL", "https://shop.example.test");
    const transport = vi.fn(async (input: URL | RequestInfo) => {
      expect(String(input)).toBe("https://api.example.test/api/pages/about");
      return json(page("about"));
    });
    vi.stubGlobal("fetch", transport);
    const route = await import("@/app/pages/[slug]/page");
    const element = await route.default({ params: Promise.resolve({ slug: "about" }) });
    render(element);
    expect(screen.getByRole("heading", { level: 1, name: "About us" })).toBeInTheDocument();
    expect(screen.getByText("there").closest("strong")).toBeTruthy();
    expect(document.body.innerHTML).not.toContain("<script");
    expect(document.body.innerHTML).not.toContain("onmouseover");
    const metadata = await route.generateMetadata({ params: Promise.resolve({ slug: "about" }) });
    expect(metadata.title).toBe("About page");
    expect(metadata.alternates?.canonical).toBe("https://shop.example.test/pages/about");
    expect(metadata.robots).toEqual({ index: true, follow: true });
  });

  it("calls notFound() when the backend has no published page", async () => {
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
    vi.stubGlobal("fetch", vi.fn(async () => json({ success: false, message: "Page not found", data: null }, 404)));
    const route = await import("@/app/pages/[slug]/page");
    await expect(route.default({ params: Promise.resolve({ slug: "missing-page" }) })).rejects.toThrow("NEXT_NOT_FOUND");
  });

  it("passes commerce scope headers to the CMS endpoint", async () => {
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
    const transport = vi.fn(async (_input: URL | RequestInfo, init?: RequestInit) => {
      const headers = new Headers(init?.headers);
      expect(headers.get("X-Store-Id")).toBe("store");
      expect(headers.get("X-Channel-Id")).toBe("web");
      expect(headers.get("X-Locale")).toBe("en");
      expect(headers.get("X-Currency")).toBe("USD");
      return json(page("scoped-page"));
    });
    vi.stubGlobal("fetch", transport);
    const route = await import("@/app/pages/[slug]/page");
    await route.default({ params: Promise.resolve({ slug: "scoped-page" }) });
    expect(transport).toHaveBeenCalledOnce();
  });
});
