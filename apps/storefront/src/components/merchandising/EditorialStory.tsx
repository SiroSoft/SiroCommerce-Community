import Link from "next/link";
import type { Product } from "@/types";
import { fill, getCopy, type Locale } from "@/lib/i18n";
import { Reveal } from "@/components/Reveal";

export function EditorialStory({ product, locale }: { product: Product; locale?: Locale }) {
  const copy = getCopy(locale).story;
  return <section className="section editorial-story" aria-labelledby="story-title"><Reveal>
    <div className="story">
      <div className="story-media">
        <img src={product.image || "/placeholder-product.svg"} alt={product.imageAlt || product.name} loading="lazy" decoding="async" width="900" height="1100" />
      </div>
      <div className="story-copy">
        <p className="eyebrow">{copy.eyebrow}</p>
        <h2 id="story-title">{copy.title}</h2>
        <p className="description">{product.description}</p>
        <p className="muted">{copy.note}</p>
        <p><Link className="text-link" href={`/product/${product.slug}`}>{copy.discover} {product.name}</Link></p>
      </div>
    </div>
  </Reveal></section>;
}