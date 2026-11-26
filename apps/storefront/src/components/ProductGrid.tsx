import type { Product } from "@/types";
import type { ProductCardVariant } from "@/theme/types";
import { getCopy, type Locale } from "@/lib/i18n";
import { ProductCard } from "./ProductCard";
export function ProductGrid({ products, loading = false, recommendationId, recommendationSource, locale, cardVariant = "editorial", listVariant = "list-editorial" }: { products: Product[]; loading?: boolean; recommendationId?: string; recommendationSource?: string; locale?: Locale; cardVariant?: ProductCardVariant; listVariant?: "list-editorial" | "list-grid-wide" }) {
  if (loading) return <div className="product-grid" aria-busy="true" aria-label={getCopy(locale).recommendation.loading}>{[0, 1, 2, 3].map((item) => <div className="skeleton" role="presentation" key={item} />)}</div>;
  return <div className="product-grid" data-list-layout={listVariant}>{products.map((product, index) => <ProductCard product={product} key={product.id} recommendationId={recommendationId} recommendationSource={recommendationSource} recommendationPosition={index + 1} variant={cardVariant} />)}</div>;
}
