import Link from "next/link";
import { getCopy, type Locale } from "@/lib/i18n";
import { readBannerItems, readTextProp } from "@/homepage/section-data";

export function BannerGrid({ props, locale }: { props?: Record<string, unknown>; locale?: Locale }) {
  const copy = getCopy(locale);
  const items = readBannerItems(props);
  if (items.length === 0) return null;
  const eyebrow = readTextProp(props, "eyebrow") ?? copy.home.bannerEyebrow;
  const title = readTextProp(props, "title") ?? copy.home.bannerTitle;
  return <div className="sf-sec-banner-grid">
    <div className="sf-sec-heading">
      <p className="eyebrow">{eyebrow}</p>
      <h2>{title}</h2>
    </div>
    <ul className="sf-sec-banner-grid-list" data-count={Math.min(4, Math.max(2, items.length))}>
      {items.map((item, index) => <li key={`${item.href ?? item.title}-${index}`}>
        <article className={`sf-sec-banner-card${item.image ? " has-media" : ""}`}>
          {item.image && <div className="sf-sec-banner-media">
            <img src={item.image} alt={item.imageAlt ?? ""} loading="lazy" decoding="async" />
          </div>}
          <div className="sf-sec-banner-body">
            <h3>{item.title}</h3>
            {item.subtitle && <p>{item.subtitle}</p>}
            {item.href && <Link className="sf-sec-banner-cta" href={item.ctaHref ?? item.href}>{item.cta ?? copy.discovery.viewAll}</Link>}
          </div>
        </article>
      </li>)}
    </ul>
  </div>;
}
