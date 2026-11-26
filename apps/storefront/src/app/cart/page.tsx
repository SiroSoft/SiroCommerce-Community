import type { Metadata } from "next";
import Link from "next/link";
import { Suspense } from "react";
import { PurchaseFlow } from "@/components/PurchaseFlow";
import { ProductGrid } from "@/components/ProductGrid";
import { loadCartSnapshot } from "@/lib/cart-server";
import { requestLocale, requestScope } from "@/lib/request-locale";
import { getCopy } from "@/lib/i18n";
import { toDisplayCart, type CartSnapshot } from "@/lib/cart-session";
import { catalog } from "@/services/catalog";
import { getConfiguredStorefrontConfig } from "@/services/storefront-config";
import { Slot } from "@/slots";
import type { Product } from "@/types";
export async function generateMetadata(): Promise<Metadata> { const copy = getCopy(await requestLocale()).purchase; return { title: copy.cartPageTitle, description: copy.cartPageDesc, robots: { index: false, follow: false } }; }

async function CartCrossSell({ exclude }: { exclude: string[] }) {
  const locale = await requestLocale();
  const scope = await requestScope();
  let products: Product[] = [];
  try {
    const source = catalog(scope);
    const result = source.listProductsResult ? await source.listProductsResult({ perPage: 8 }) : { products: await source.listProducts({ perPage: 8 }) };
    products = result.products.filter((product) => !exclude.includes(product.id) && !exclude.includes(product.slug)).slice(0, 3);
  } catch {
    products = [];
  }
  if (products.length === 0) return null;
  return <aside className="sf-cross-sell" aria-label={getCopy(locale).recommendation.alsoLike}>
    <h2>{getCopy(locale).recommendation.alsoLike}</h2>
    <ProductGrid products={products} locale={locale} />
  </aside>;
}

export default async function CartPage({ searchParams }: { searchParams?: Promise<Record<string, string | string[] | undefined>> }) {
  const locale = await requestLocale();
  const copy = getCopy(locale).purchase;
  const snapshot: CartSnapshot | undefined = await loadCartSnapshot();
  const config = await getConfiguredStorefrontConfig();
  const params = (await searchParams) ?? {};
  const flag = (name: string) => {
    const value = params[name];
    return Array.isArray(value) ? value[0] : value;
  };
  const notice = flag("added") === "1" ? "added" : flag("updated") === "1" ? "updated" : flag("removed") === "1" ? "removed" : flag("add") === "failed" ? "failed" : undefined;
  const lines = snapshot?.lines ?? [];
  return <>
    <section className="page-intro"><p className="eyebrow">{copy.cartPageEyebrow}</p><h1>{copy.cartPageTitle}</h1><p>{copy.cartPageCopy}</p></section>
    <PurchaseFlow initialStep="cart" initialCart={snapshot ? toDisplayCart(snapshot) : undefined} flash={notice} quoteMode={config.purchase.quoteMode} />
    <Slot name="cart" />
    {lines.length === 0 && <section className="sf-cart-empty-cta" aria-label={copy.continueShopping}><Link className="button" href="/search">{copy.continueShopping}</Link></section>}
    <Suspense fallback={null}><CartCrossSell exclude={lines.map((line) => line.productId)} /></Suspense>
  </>;
}
