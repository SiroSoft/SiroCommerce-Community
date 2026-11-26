import type { MetadataRoute } from "next";
import { siteUrl } from "@/lib/locale";

export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: ["/account", "/cart", "/checkout", "/quote", "/order-result", "/api", "/admin"],
    },
    sitemap: siteUrl("/sitemap.xml"),
  };
}
