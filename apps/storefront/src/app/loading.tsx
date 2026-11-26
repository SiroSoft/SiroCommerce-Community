import { getCopy } from "@/lib/i18n";
import { storefrontLocale } from "@/lib/locale";

export default function Loading() {
  const text = getCopy(storefrontLocale());
  return <section className="page-intro loading-state" aria-busy="true" aria-live="polite" aria-label={text.catalog.loadingCatalog}><p className="sr-only">{text.catalog.loadingCatalog}</p><div className="skeleton skeleton-title" aria-hidden="true" /><div className="skeleton skeleton-copy" aria-hidden="true" /><div className="skeleton skeleton-grid product-grid" aria-hidden="true">{[0, 1, 2, 3].map((key) => <div className="skeleton-card" key={key} />)}</div></section>;
}
