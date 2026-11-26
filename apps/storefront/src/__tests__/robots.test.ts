import { afterEach, describe, expect, it, vi } from "vitest";
import robots from "@/app/robots";

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("storefront robots", () => {
  it("allows browsing while keeping private and system routes out of the index", () => {
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_URL", "https://shop.example.test");
    const value = robots();
    expect(value.rules).toMatchObject({ userAgent: "*", allow: "/" });
    const disallow = Array.isArray(value.rules) ? [] : value.rules.disallow ?? [];
    expect(disallow).toEqual(expect.arrayContaining(["/account", "/cart", "/checkout", "/quote", "/order-result", "/api", "/admin"]));
    expect(value.sitemap).toBe("https://shop.example.test/sitemap.xml");
  });
});
