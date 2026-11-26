import { describe, expect, it } from "vitest";
import { jsonLd, priceForSchema } from "@/lib/structured-data";
import { siteUrl } from "@/lib/locale";
import { generateMetadata as layoutMetadata } from "@/app/layout";

describe("storefront SEO foundations", () => {
  it("escapes markup-sensitive catalog text in JSON-LD", () => {
    const value = jsonLd({ name: "<script>alert('&')</script>" });
    expect(value).not.toContain("<script>");
    expect(value).toContain("\\u003cscript\\u003e");
    expect(JSON.parse(value)).toEqual({ name: "<script>alert('&')</script>" });
  });

  it("converts wire minor units to schema prices", () => {
    expect(priceForSchema(6800, "USD")).toBe(68);
    expect(priceForSchema(68000, "VND")).toBe(68000);
    expect(priceForSchema(1234, "KWD")).toBe(1.234);
  });

  it("uses a valid configured HTTPS origin for canonical URLs", () => {
    const previous = process.env.NEXT_PUBLIC_STOREFRONT_URL;
    process.env.NEXT_PUBLIC_STOREFRONT_URL = "https://shop.example.test/";
    expect(siteUrl("/product/mug")).toBe("https://shop.example.test/product/mug");
    if (previous === undefined) delete process.env.NEXT_PUBLIC_STOREFRONT_URL;
    else process.env.NEXT_PUBLIC_STOREFRONT_URL = previous;
  });

  it("publishes metadata in the negotiated storefront language", async () => {
    const previous = process.env.STOREFRONT_LOCALE;
    process.env.STOREFRONT_LOCALE = "de-DE";
    await expect(layoutMetadata()).resolves.toMatchObject({
      description: "Kleinserien und leichte Lagen für ein gut gelebtes Leben.",
      openGraph: { locale: "de" },
    });
    if (previous === undefined) delete process.env.STOREFRONT_LOCALE;
    else process.env.STOREFRONT_LOCALE = previous;
  });
});
