"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { catalog } from "@/services/catalog";
import { customer, type WishlistItem } from "@/services/customer";
import type { Product } from "@/types";
import { fill } from "@/lib/i18n";
import { useCopy, useLocale } from "@/lib/i18n-client";
import { formatMinor } from "@/lib/money";

type WishlistRow = { item: WishlistItem; product?: Product };

function formatPrice(product: Product, locale: string): string | undefined {
  try {
    return formatMinor(product.price, product.currency, locale);
  } catch {
    return undefined;
  }
}

/**
 * Account wishlist backed by the real customer API (GET /api/wishlist,
 * DELETE /api/wishlist/{product}). Catalog details (name/image/price) are
 * resolved per saved product_id; rows without catalog data still render
 * under their saved id so no entry is ever invented or dropped.
 */
export function AccountWishlist({ isAuthenticated }: { isAuthenticated: boolean }) {
  const copy = useCopy();
  const locale = useLocale();
  const text = copy.accountWishlist;
  const [rows, setRows] = useState<WishlistRow[]>([]);
  const [status, setStatus] = useState<"idle" | "loading" | "ready" | "error">("idle");
  const [reloadToken, setReloadToken] = useState(0);
  const [removing, setRemoving] = useState<Record<string, boolean>>({});
  const [notice, setNotice] = useState("");

  useEffect(() => {
    if (!isAuthenticated) {
      setStatus("idle");
      setRows([]);
      setNotice("");
      return;
    }
    let active = true;
    setStatus("loading");
    setNotice("");
    customer()
      .getWishlist()
      .then(async (items) => {
        const enriched = await Promise.all(
          items.map(async (item): Promise<WishlistRow> => {
            try {
              const product = await catalog().getProduct(item.product_id);
              return product ? { item, product } : { item };
            } catch {
              return { item };
            }
          }),
        );
        if (active) {
          setRows(enriched);
          setStatus("ready");
        }
      })
      .catch(() => {
        if (active) setStatus("error");
      });
    return () => {
      active = false;
    };
  }, [isAuthenticated, reloadToken]);

  async function removeRow(row: WishlistRow) {
    const key = row.item.product_id;
    if (removing[key]) return;
    setRemoving((current) => ({ ...current, [key]: true }));
    setNotice("");
    try {
      await customer().removeWishlist(key);
      setRows((current) => current.filter((entry) => entry.item.product_id !== key));
      setNotice(text.removed);
    } catch {
      setNotice(text.removeFailed);
    } finally {
      setRemoving((current) => ({ ...current, [key]: false }));
    }
  }

  return (
    <section className="account-panel" aria-labelledby="account-wishlist-title">
      <div className="account-section-heading">
        <h3 id="account-wishlist-title">{text.title}</h3>
        <span className="muted">{status === "ready" ? fill(text.countLabel, { count: rows.length }) : ""}</span>
      </div>
      {!isAuthenticated ? (
        <p className="muted">{text.signInPrompt}</p>
      ) : status === "loading" || status === "idle" ? (
        <p className="muted" role="status" aria-live="polite">
          {text.loading}
        </p>
      ) : status === "error" ? (
        <>
          <p className="form-error" role="alert">
            {text.loadFailed}
          </p>
          <button className="text-button" type="button" onClick={() => setReloadToken((value) => value + 1)}>
            {text.retry}
          </button>
        </>
      ) : rows.length === 0 ? (
        <p className="muted">
          {text.empty}
        </p>
      ) : (
        <ul className="wishlist-items" aria-label={text.title}>
          {rows.map((row) => {
            const key = row.item.product_id;
            const name = row.product?.name ?? key;
            const price = row.product ? formatPrice(row.product, locale) : undefined;
            const busy = removing[key] ?? false;
            return (
              <li className="wishlist-item" key={key}>
                {row.product?.image ? (
                  <img className="cart-item-image" src={row.product.image} alt="" width="96" height="96" loading="lazy" decoding="async" />
                ) : (
                  <span className="cart-item-image cart-item-image-missing" aria-hidden="true" />
                )}
                <div className="cart-item-main">
                  <strong>
                    {row.product ? (
                      <Link className="text-link" href={`/product/${row.product.slug}`}>
                        {row.product.name}
                      </Link>
                    ) : (
                      name
                    )}
                  </strong>
                  {price ? <span className="muted">{price}</span> : <span className="muted">{text.detailsUnavailable}</span>}
                  <div className="cart-line-controls">
                    <form method="post" action="/cart/add">
                      <input type="hidden" name="product_id" value={row.item.variant_id ?? key} />
                      <input type="hidden" name="quantity" value="1" />
                      <button
                        className="button"
                        type="submit"
                        aria-label={`${text.addToCart}: ${name}`}
                      >
                        {text.addToCart}
                      </button>
                    </form>
                    <button
                      type="button"
                      className="text-button"
                      aria-label={fill(text.removeLabel, { name })}
                      onClick={() => void removeRow(row)}
                      disabled={busy}
                    >
                      {text.remove}
                    </button>
                  </div>
                </div>
              </li>
            );
          })}
        </ul>
      )}
      {notice && (
        <p className="form-notice" role="status">
          {notice}
        </p>
      )}
    </section>
  );
}
