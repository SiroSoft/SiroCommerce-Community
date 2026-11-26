"use client";

import { useEffect, useState } from "react";
import { readScopeCookie } from "@/lib/scope-cookie";

type Banner = { title: string; enabled: boolean; image: string };

export function PromoBanner() {
  const [banner, setBanner] = useState<Banner | null>(null);
  useEffect(() => {
    const base = process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "";
    if (!base) return;
    fetch(`${base}/api/configuration/public`, {
      headers: {
        "X-Store-Id": process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? "default",
        "X-Channel-Id": process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? "web",
        "X-Locale": readScopeCookie().locale ?? process.env.NEXT_PUBLIC_STOREFRONT_LOCALE ?? "en",
        "X-Currency": readScopeCookie().currency ?? process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? "USD",
      },
    })
      .then((r) => r.json().catch(() => ({})))
      .then((j: { data?: { promo_banner?: Banner } }) => {
        const b = j.data?.promo_banner;
        if (b && b.enabled && (b.title || b.image)) setBanner(b);
      })
      .catch(() => {});
  }, []);
  if (!banner) return null;
  return (
    <div className="promo-banner" role="banner" style={{ background: "var(--theme-paper)", borderBottom: "1px solid var(--line)", padding: "8px 16px", textAlign: "center" }}>
      {banner.image ? <img src={banner.image} alt={banner.title} width={1200} height={300} loading="lazy" decoding="async" style={{ maxHeight: 60, margin: "0 auto" }} /> : null}
      {banner.title ? <p style={{ margin: banner.image ? "8px 0 0" : 0, fontWeight: 600 }}>{banner.title}</p> : null}
    </div>
  );
}
