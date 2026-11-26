import Link from "next/link";
import type { Product } from "@/types";
import { fill, getCopy, type Locale } from "@/lib/i18n";
import { formatMinor } from "@/lib/money";
import { categoryHref } from "@/lib/category-href";

export type HomeCategoryLink = { slug: string; label: string; href: string; count: number };

type SectionProps = Record<string, unknown>;

/** Backwards-compatible alias for the shared category route helper. */
export const resolveCategoryHref = categoryHref;

function textProp(props: SectionProps | undefined, key: string): string | undefined {
  const value = props?.[key];
  return typeof value === "string" && value.trim() ? value : undefined;
}

export function CategoryShortcuts({ categories, props, locale }: { categories: HomeCategoryLink[]; props?: SectionProps; locale?: Locale }) {
  if (categories.length === 0) return null;
  const copy = getCopy(locale);
  const eyebrow = textProp(props, "eyebrow") ?? copy.category.prefix;
  const title = textProp(props, "title") ?? copy.home.shopByCategory;
  return (
    <div className="sf-category-strip">
      <div className="sf-section-heading">
        <p className="eyebrow">{eyebrow}</p>
        <h2>{title}</h2>
      </div>
      <ul className="sf-category-list">
        {categories.map((category) => (
          <li key={category.slug}>
            <Link href={category.href} prefetch>
              <span className="sf-category-label">{category.label}</span>
              <span className="sf-category-count">
                {category.count} {category.count === 1 ? copy.discovery.piece : copy.discovery.pieces}
              </span>
              <span className="sf-category-arrow" aria-hidden="true">→</span>
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}

type TrustItem = { title: string; copy: string };

function trustItems(locale: Locale | undefined, props: SectionProps | undefined): TrustItem[] {
  const copy = getCopy(locale);
  return [
    {
      // Existing key: home.heroTrustShipping.
      title: textProp(props, "shippingTitle") ?? copy.product.deliveryTitle,
      copy: textProp(props, "shippingCopy") ?? copy.home.heroTrustShipping,
    },
    {
      title: textProp(props, "returnsTitle") ?? copy.footer.returnsPolicy,
      copy: textProp(props, "returnsCopy") ?? copy.home.trustReturns,
    },
    {
      title: textProp(props, "supportTitle") ?? copy.footer.contact,
      copy: textProp(props, "supportCopy") ?? copy.footer.emailSupport,
    },
  ];
}

export function FlashOfferStrip({ products, locale }: { products: Product[]; locale?: Locale }) {
  const copy = getCopy(locale);
  const offers = products.flatMap((product) => {
    const deal = product.productType === "booking" ? undefined : (product.flashSales ?? [])[0];
    return deal ? [{ product, deal }] : [];
  }).slice(0, 4);
  if (offers.length === 0) return null;
  return (
    <aside className="sf-media-flash-strip" aria-label={copy.product.flashBadge}>
      <div className="sf-media-flash-heading">
        <p className="eyebrow">{copy.product.flashBadge}</p>
        <p className="sf-media-flash-copy">{copy.home.flashStripCopy}</p>
      </div>
      <ul className="sf-media-flash-list">
        {offers.map(({ product, deal }) => (
          <li className="sf-media-flash-item" key={`${product.id}-${deal.sale_id}`}>
            <Link className="sf-media-flash-link" href={`/product/${product.slug}`}>
              <span className="sf-media-flash-name">{deal.sale_name || product.name}</span>
              <span className="sf-media-flash-prices">
                <strong>{formatMinor(deal.sale_price_minor, product.currency, locale)}</strong>
                {deal.sale_price_minor < product.price && <del>{formatMinor(product.price, product.currency, locale)}</del>}
              </span>
              {deal.remaining !== null && <span className="sf-media-flash-note">{fill(copy.product.flashOnlyLeft, { count: deal.remaining })}</span>}
            </Link>
          </li>
        ))}
      </ul>
    </aside>
  );
}

export function TrustBand({ locale, props }: { locale?: Locale; props?: SectionProps }) {
  const copy = getCopy(locale);
  const eyebrow = textProp(props, "eyebrow") ?? copy.footer.needHelp;
  const title = textProp(props, "title") ?? copy.product.beforeYouBuy;
  return (
    <div className="sf-trust-band">
      <div className="sf-section-heading">
        <p className="eyebrow">{eyebrow}</p>
        <h2>{title}</h2>
      </div>
      <ul className="sf-trust-list">
        {trustItems(locale, props).map((item) => (
          <li key={item.title} className="sf-trust-item">
            <h3>{item.title}</h3>
            <p>{item.copy}</p>
          </li>
        ))}
      </ul>
    </div>
  );
}
