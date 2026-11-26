import Link from "next/link";
import type { Product } from "@/types";
import { fill, getCopy, type Locale } from "@/lib/i18n";
import { Reveal } from "@/components/Reveal";

export function CollectionDirectory({ products, locale }: { products: Product[]; locale?: Locale }) {
  const copy = getCopy(locale).collection;
  const piece = (count: number) => (count === 1 ? copy.piece : copy.pieces);
  const seen = new Map<string, { slug: string; name: string }>();
  for (const product of products) {
    if (!product.category || seen.has(product.category)) continue;
    seen.set(product.category, { slug: product.category, name: product.categoryName || product.category });
    if (seen.size >= 4) break;
  }
  const collections = [...seen.values()];
  return <section className="section" aria-labelledby="collection-directory-title"><Reveal>
    <div className="section-heading">
      <div><p className="eyebrow">{copy.eyebrow}</p><h2 id="collection-directory-title">{copy.title}</h2><p>{fill(copy.distinctEdits, { count: collections.length })}</p></div>
      <Link className="text-link" href="/search">{copy.browseAll}</Link>
    </div>
    <div className="directory-grid">
      {collections.map((collection) => {
        const feature = products.find((product) => product.category === collection.slug);
        const count = products.filter((product) => product.category === collection.slug).length;
        const note = copy.notes[collection.slug as keyof typeof copy.notes] ?? copy.title;
        return <Link className="collection-tile" href={`/category/${collection.slug}`} key={collection.slug} aria-label={`${copy.explore} ${collection.name}, ${count} ${piece(count)}`}>
          {feature && <div className="collection-media"><img src={feature.image || "/placeholder-product.svg"} alt={feature.imageAlt || feature.name} loading="lazy" decoding="async" width="900" height="600" /></div>}
          <div className="collection-body">
            <span className="eyebrow">{collection.name}</span>
            <strong>{note}</strong>
            <span className="collection-count">{fill(copy.inCatalog, { count, unit: piece(count) })}</span>
            <span className="text-link">{copy.explore} {collection.name.toLowerCase()}</span>
          </div>
        </Link>;
      })}
    </div>
  </Reveal></section>;
}
