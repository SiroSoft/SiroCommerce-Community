import Link from "next/link";
import type { Product } from "@/types";
import type { ProductCardVariant } from "@/theme/types";
import { ProductGrid } from "@/components/ProductGrid";
import { Reveal } from "@/components/Reveal";
import type { Locale } from "@/lib/i18n";

export function EditorialCollection({ eyebrow, title, description, href, linkLabel, products, locale, emptyTitle, emptyCopy, cardVariant = "editorial", listVariant = "list-editorial" }: { eyebrow: string; title: string; description: string; href: string; linkLabel: string; products: Product[]; locale?: Locale; emptyTitle?: string; emptyCopy?: string; cardVariant?: ProductCardVariant; listVariant?: "list-editorial" | "list-grid-wide" }) {
  return <section className="section editorial-collection" aria-labelledby={`${title.toLowerCase().replaceAll(" ", "-")}-title`}><Reveal>
    <div className="section-heading">
      <div><p className="eyebrow">{eyebrow}</p><h2 id={`${title.toLowerCase().replaceAll(" ", "-")}-title`}>{title}</h2><p>{description}</p></div>
      <Link className="text-link" href={href}>{linkLabel}</Link>
    </div>
    {products.length > 0 ? <ProductGrid products={products} locale={locale} cardVariant={cardVariant} listVariant={listVariant} /> : <div className="catalog-empty"><p>{emptyTitle}</p><p className="recovery-copy">{emptyCopy}</p><Link className="text-link" href={href}>{linkLabel}</Link></div>}
  </Reveal></section>;
}
