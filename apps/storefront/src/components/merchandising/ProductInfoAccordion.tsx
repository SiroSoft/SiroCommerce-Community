import type { Product } from "@/types";
import { fill, getCopy, type Locale } from "@/lib/i18n";

export function ProductInfoAccordion({ product, locale }: { product: Product; locale?: Locale }) {
  const copy = getCopy(locale).product;
  const delivery = product.delivery;
  const deliverySummary = delivery
    ? delivery.message ?? (delivery.mode === "downloadable" ? copy.dlDownload
      : delivery.mode === "virtual" ? copy.dlVirtual
        : delivery.mode === "scheduled" ? `${copy.dlScheduled}${delivery.scheduled_for ? fill(copy.dlScheduledFor, { when: delivery.scheduled_for }) : copy.dlScheduledSuffix}.`
          : delivery.mode === "physical" ? copy.dlPhysical : copy.dlUnknown)
    : undefined;
  return <div className="sf-sec-product-accordion">
    <details className="sf-sec-accordion">
      <summary className="sf-sec-accordion-summary">{copy.accordionDescription}</summary>
      <div className="sf-sec-accordion-body"><p>{product.description}</p></div>
    </details>
    {deliverySummary && <details className="sf-sec-accordion">
      <summary className="sf-sec-accordion-summary">{copy.accordionDelivery}</summary>
      <div className="sf-sec-accordion-body"><p>{deliverySummary}</p></div>
    </details>}
    {product.reviewCount > 0 && product.rating > 0 && <details className="sf-sec-accordion">
      <summary className="sf-sec-accordion-summary">{copy.accordionReviews}</summary>
      <div className="sf-sec-accordion-body">
        <p>{product.rating.toFixed(1)} · {product.reviewCount} {product.reviewCount === 1 ? copy.review : copy.reviews}</p>
        <a className="text-link" href="#reviews-title">{copy.reviewSummary}</a>
      </div>
    </details>}
  </div>;
}
