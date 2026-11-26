"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { AccountWishlist } from "@/components/account/AccountWishlist";
import { fill } from "@/lib/i18n";
import { useCopy, useLocale } from "@/lib/i18n-client";
import { formatMinor } from "@/lib/money";
import { hasStoredSession } from "@/lib/session-hint";
import { catalog } from "@/services/catalog";
import type { Product } from "@/types";
import { useWishlist, type WishlistEntry } from "./wishlist-store";

export { useWishlist } from "./wishlist-store";
export type { WishlistEntry, WishlistProductRef, WishlistToggleResult } from "./wishlist-store";

type LocalWishlistRow = { entry: WishlistEntry; product?: Product };

function priceLabel(product: Product, locale: string): string | undefined {
  try {
    return formatMinor(product.price, product.currency, locale);
  } catch {
    return undefined;
  }
}

function LocalWishlistPanel() {
  const copy = useCopy();
  const locale = useLocale();
  const wishlist = useWishlist();
  const [rows, setRows] = useState<LocalWishlistRow[]>([]);
  const [notice, setNotice] = useState("");
  const [removing, setRemoving] = useState<string | undefined>();
  const signature = wishlist.entries.map((entry) => entry.slug).join("|");

  useEffect(() => {
    let active = true;
    const entries = wishlist.entries;
    Promise.all(
      entries.map(async (entry): Promise<LocalWishlistRow> => {
        try {
          const product = await catalog().getProduct(entry.slug);
          return product ? { entry, product } : { entry };
        } catch {
          return { entry };
        }
      }),
    ).then((nextRows) => {
      if (active) setRows(nextRows);
    });
    return () => {
      active = false;
    };
  }, [signature]);

  async function remove(row: LocalWishlistRow) {
    const key = row.entry.slug;
    if (removing === key) return;
    setRemoving(key);
    setNotice("");
    try {
      await wishlist.toggle({ id: row.product?.id ?? key, slug: key });
      setNotice(copy.accountWishlist.removed);
    } finally {
      setRemoving(undefined);
    }
  }

  return (
    <section className="account-panel sf-wish-panel" aria-labelledby="sf-wish-local-title">
      <div className="account-section-heading">
        <h2 id="sf-wish-local-title">{copy.accountWishlist.title}</h2>
        <span className="muted">{fill(copy.accountWishlist.countLabel, { count: wishlist.entries.length })}</span>
      </div>
      {wishlist.entries.length === 0 ? (
        <p className="muted sf-wish-status">{copy.accountWishlist.empty}</p>
      ) : (
        <ul className="wishlist-items" aria-label={copy.accountWishlist.title}>
          {rows.map((row) => {
            const name = row.product?.name ?? row.entry.slug;
            const price = row.product ? priceLabel(row.product, locale) : undefined;
            const busy = removing === row.entry.slug;
            return (
              <li className="wishlist-item" key={row.entry.slug}>
                {row.product?.image ? (
                  <img className="cart-item-image" src={row.product.image} alt="" width="96" height="96" loading="lazy" decoding="async" />
                ) : (
                  <span className="cart-item-image cart-item-image-missing" aria-hidden="true" />
                )}
                <div className="cart-item-main">
                  <strong>
                    {row.product ? (
                      <Link className="text-link" href={`/product/${row.product.slug}`}>{row.product.name}</Link>
                    ) : (
                      name
                    )}
                  </strong>
                  {price ? <span className="muted">{price}</span> : <span className="muted">{copy.accountWishlist.detailsUnavailable}</span>}
                  <div className="cart-line-controls">
                    {row.product && (
                      <form method="post" action="/cart/add">
                        <input type="hidden" name="product_id" value={row.product.id} />
                        <input type="hidden" name="quantity" value="1" />
                        <button className="button" type="submit" aria-label={`${copy.accountWishlist.addToCart}: ${name}`}>
                          {copy.accountWishlist.addToCart}
                        </button>
                      </form>
                    )}
                    <button
                      type="button"
                      className="text-button"
                      aria-label={fill(copy.accountWishlist.removeLabel, { name })}
                      onClick={() => void remove(row)}
                      disabled={busy}
                    >
                      {copy.accountWishlist.remove}
                    </button>
                  </div>
                </div>
              </li>
            );
          })}
        </ul>
      )}
      {notice && <p className="form-notice sf-wish-status" role="status">{notice}</p>}
    </section>
  );
}

export function WishlistAccountView() {
  const copy = useCopy();
  const wishlist = useWishlist();
  const [authenticated, setAuthenticated] = useState<boolean | null>(null);

  useEffect(() => {
    const sync = () => setAuthenticated(hasStoredSession());
    sync();
    window.addEventListener("siro:session-updated", sync);
    window.addEventListener("storage", sync);
    return () => {
      window.removeEventListener("siro:session-updated", sync);
      window.removeEventListener("storage", sync);
    };
  }, []);

  if (authenticated === null) {
    return <p className="muted sf-wish-status" role="status">{copy.accountWishlist.loading}</p>;
  }
  if (authenticated) {
    return <>
      {wishlist.migrationSynced && <p className="muted sf-wish-status" role="status">{copy.wishlistPanel.synced}</p>}
      <AccountWishlist isAuthenticated />
    </>;
  }
  return <LocalWishlistPanel />;
}