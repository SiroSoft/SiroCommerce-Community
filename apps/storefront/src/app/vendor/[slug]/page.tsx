import type { Metadata } from "next";
import { notFound } from "next/navigation";
import Link from "next/link";
import { Breadcrumbs } from "@/components/Breadcrumbs";
import { CatalogRequestError, catalog } from "@/services/catalog";
import { CatalogState } from "@/components/CatalogState";
import { ProductGrid } from "@/components/ProductGrid";
import { fill, getCopy } from "@/lib/i18n";
import { requestLocale, requestScope } from "@/lib/request-locale";
import type { Product } from "@/types";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const copy = getCopy(await requestLocale()).vendorPage;
  const { slug } = await params;
  try {
    const source = catalog(await requestScope());
    const vendor = source.getVendor ? await source.getVendor(slug) : undefined;
    if (!vendor) return { title: copy.notFoundTitle };
    return { title: vendor.name, description: vendor.description ?? undefined, alternates: { canonical: `/vendor/${vendor.slug}` } };
  } catch {
    return { title: copy.notFoundTitle };
  }
}

export default async function VendorPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const locale = await requestLocale();
  const copy = getCopy(locale).vendorPage;
  const source = catalog(await requestScope());
  let vendor;
  try {
    vendor = source.getVendor ? await source.getVendor(slug) : undefined;
  } catch (error) {
    if (error instanceof CatalogRequestError) return <CatalogState error={error} locale={locale} />;
    throw error;
  }
  if (!vendor) notFound();
  let products: Product[] = [];
  if (!vendor.suspended) {
    try {
      products = await source.listProducts({ vendor: vendor.slug, perPage: 100 });
    } catch (error) {
      if (error instanceof CatalogRequestError) return <CatalogState error={error} locale={locale} />;
      throw error;
    }
  }
  return (
    <>
      <Breadcrumbs current={vendor.name} locale={locale} />
      <section className="page-intro">
        <p className="eyebrow">{copy.eyebrow}</p>
        <h1>{vendor.name}</h1>
        {vendor.description && <p>{vendor.description}</p>}
        <p className="muted">{fill(copy.productCount, { count: vendor.product_count })}</p>
      </section>
      {vendor.suspended ? (
        <section className="support-card" role="status" aria-labelledby="vendor-suspended-heading">
          <h2 id="vendor-suspended-heading">{copy.suspendedTitle}</h2>
          <p>{copy.suspendedNotice}</p>
          <Link className="text-link" href="/search">{copy.backToCatalog}</Link>
        </section>
      ) : products.length === 0 ? (
        <section className="catalog-empty empty-state">
          <h2>{copy.emptyTitle}</h2>
          <p>{copy.emptyStatus}</p>
          <Link className="text-link" href="/search">{copy.backToCatalog}</Link>
        </section>
      ) : (
        <ProductGrid products={products} locale={locale} />
      )}
    </>
  );
}
