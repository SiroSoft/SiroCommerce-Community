"use client";

import { useEffect, useId, useState } from "react";
import type { SlotProps } from "@/slots";
import { Modal } from "@/components/ui-primitives";
import { getCustomerAccessToken } from "@/services/customer";

// Gallery grid styles stay local (no shared grid primitive exists); the
// lightbox dialog is the shared Modal primitive from the Experience lane.

interface PhotoItem {
  id: string;
  review_id: string;
  product_id: string;
  url: string;
  sort: number;
  rating: number;
  title: string;
  created_at: string;
}

interface UploadedPhoto {
  name: string;
  url: string;
}

type LoadState = "loading" | "ready" | "unavailable";

const MAX_PHOTOS = 5;
const MAX_FILE_BYTES = 8 * 1024 * 1024;

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function readString(data: SlotProps["data"], key: string): string {
  const value = data?.[key];
  return typeof value === "string" ? value.trim() : "";
}

function apiBase(): string {
  const configured = process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "";
  return configured.replace(/\/$/, "");
}

function scopeHeaders(locale?: string): Record<string, string> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Store-Id": process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? "default",
    "X-Channel-Id": process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? "web",
    "X-Locale": locale?.trim() || process.env.NEXT_PUBLIC_STOREFRONT_LOCALE || "en",
    "X-Currency": process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? "USD",
  };
  const feToken = process.env.NEXT_PUBLIC_FE_TOKEN;
  if (feToken) headers["X-Siro-FE"] = feToken;
  return headers;
}

function parseItems(payload: unknown): PhotoItem[] {
  if (!isRecord(payload) || payload.success !== true || !Array.isArray(payload.data)) return [];
  const items: PhotoItem[] = [];
  for (const entry of payload.data) {
    if (!isRecord(entry)) continue;
    const url = typeof entry.url === "string" ? entry.url : "";
    if (!url) continue;
    items.push({
      id: typeof entry.id === "string" ? entry.id : "",
      review_id: typeof entry.review_id === "string" ? entry.review_id : "",
      product_id: typeof entry.product_id === "string" ? entry.product_id : "",
      url,
      sort: typeof entry.sort === "number" ? entry.sort : 0,
      rating: typeof entry.rating === "number" ? entry.rating : 0,
      title: typeof entry.title === "string" ? entry.title : "",
      created_at: typeof entry.created_at === "string" ? entry.created_at : "",
    });
  }
  return items;
}

function readMessage(payload: unknown): string {
  if (isRecord(payload) && typeof payload.message === "string" && payload.message.trim() !== "") {
    return payload.message.trim();
  }
  return "";
}

function createIdempotencyKey(): string {
  const random =
    typeof crypto !== "undefined" && typeof crypto.randomUUID === "function"
      ? crypto.randomUUID()
      : Math.random().toString(36).slice(2);
  return `photo-reviews-${Date.now().toString(36)}-${random}`.slice(0, 128);
}

function photoAlt(item: PhotoItem, index: number): string {
  const rating = item.rating > 0 ? `${item.rating}-star review` : "customer review";
  const title = item.title ? `: ${item.title}` : "";
  return `Photo ${index + 1} from a ${rating}${title}`;
}

export function ProductPhotoReviewsSlot({ locale, data }: SlotProps) {
  const headingId = useId();
  const productId = readString(data, "productId");
  const [items, setItems] = useState<PhotoItem[]>([]);
  const [loadState, setLoadState] = useState<LoadState>("loading");
  const [openIndex, setOpenIndex] = useState<number | null>(null);
  const [sessionToken, setSessionToken] = useState("");

  // Upload + review form state.
  const [orderId, setOrderId] = useState("");
  const [orderLineId, setOrderLineId] = useState("");
  const [rating, setRating] = useState("");
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");
  const [photos, setPhotos] = useState<UploadedPhoto[]>([]);
  const [uploading, setUploading] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");

  useEffect(() => {
    const refresh = () => setSessionToken(getCustomerAccessToken());
    refresh();
    window.addEventListener("siro:customer-session", refresh);
    return () => window.removeEventListener("siro:customer-session", refresh);
  }, []);

  useEffect(() => {
    if (!productId) {
      setItems([]);
      setLoadState("ready");
      return;
    }
    const controller = new AbortController();
    setLoadState("loading");
    const url = `${apiBase()}/api/ext/siro/product-photo-reviews/public?product_id=${encodeURIComponent(productId)}&limit=24`;
    void fetch(url, { headers: scopeHeaders(locale), cache: "no-store", signal: controller.signal })
      .then(async (response) => {
        if (!response.ok) throw new Error(String(response.status));
        const payload: unknown = await response.json().catch(() => null);
        return parseItems(payload);
      })
      .then((next) => {
        setItems(next);
        setLoadState("ready");
      })
      .catch(() => {
        if (controller.signal.aborted) return;
        setItems([]);
        setLoadState("unavailable");
      });
    return () => controller.abort();
  }, [productId, locale]);

  // Lightbox keyboard navigation: arrows move between photos. Escape and
  // focus trap/return are handled by the shared Modal primitive; the window
  // handler below keeps Escape working for keyboard users too (idempotent
  // with Modal's own Escape handling).
  useEffect(() => {
    if (openIndex === null) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault();
        setOpenIndex(null);
      } else if (event.key === "ArrowRight") {
        event.preventDefault();
        setOpenIndex((current) => (current === null ? current : (current + 1) % items.length));
      } else if (event.key === "ArrowLeft") {
        event.preventDefault();
        setOpenIndex((current) => (current === null ? current : (current - 1 + items.length) % items.length));
      }
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [openIndex, items.length]);

  if (!productId) return null;
  const signedIn = sessionToken !== "";
  // Nothing to show: no photos and no signed-in shopper to invite.
  if (loadState !== "loading" && items.length === 0 && !signedIn) return null;

  const openPhoto = (index: number) => {
    setOpenIndex(index);
  };

  const uploadFiles = async (files: FileList | null) => {
    if (!files || files.length === 0 || !sessionToken) return;
    const picked = Array.from(files);
    if (photos.length + picked.length > MAX_PHOTOS) {
      setError(`You can attach at most ${MAX_PHOTOS} photos per review.`);
      return;
    }
    for (const file of picked) {
      if (!file.type.startsWith("image/")) {
        setError(`"${file.name}" is not an image file.`);
        return;
      }
      if (file.size > MAX_FILE_BYTES) {
        setError(`"${file.name}" is larger than 8 MB.`);
        return;
      }
    }
    setUploading(true);
    setError("");
    try {
      const uploaded: UploadedPhoto[] = [];
      for (const file of picked) {
        const form = new FormData();
        form.append("file", file);
        const response = await fetch("/api/upload", {
          method: "POST",
          headers: { Authorization: `Bearer ${sessionToken}` },
          body: form,
        });
        const payload: unknown = await response.json().catch(() => null);
        const url = isRecord(payload) && isRecord(payload.data) && typeof payload.data.url === "string" ? payload.data.url : "";
        if (!response.ok || !url) throw new Error(`"${file.name}" could not be uploaded.`);
        uploaded.push({ name: file.name, url });
      }
      setPhotos((current) => [...current, ...uploaded]);
    } catch (caught) {
      setError(caught instanceof Error && caught.message ? caught.message : "A photo could not be uploaded.");
    } finally {
      setUploading(false);
    }
  };

  const submitReview = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (submitting) return;
    if (!orderId.trim() || !orderLineId.trim()) {
      setError("Please enter your order ID and order line ID.");
      return;
    }
    const parsedRating = Number(rating);
    if (!Number.isInteger(parsedRating) || parsedRating < 1 || parsedRating > 5) {
      setError("Please enter a rating from 1 to 5.");
      return;
    }
    if (!body.trim()) {
      setError("Please write a few words about the product.");
      return;
    }
    if (photos.length === 0) {
      setError("Please attach at least one photo.");
      return;
    }
    setSubmitting(true);
    setError("");
    setNotice("");
    try {
      const response = await fetch(`${apiBase()}/api/products/${encodeURIComponent(productId)}/reviews`, {
        method: "POST",
        headers: {
          ...scopeHeaders(locale),
          Authorization: `Bearer ${sessionToken}`,
          "Content-Type": "application/json",
          "Idempotency-Key": createIdempotencyKey(),
        },
        cache: "no-store",
        body: JSON.stringify({
          order_id: orderId.trim(),
          order_line_id: orderLineId.trim(),
          rating: parsedRating,
          ...(title.trim() ? { title: title.trim() } : {}),
          body: body.trim(),
          images: photos.map((photo) => photo.url),
        }),
      });
      const payload: unknown = await response.json().catch(() => null);
      if (!response.ok || !isRecord(payload) || payload.success !== true) {
        throw new Error(readMessage(payload) || "Your review could not be submitted.");
      }
      setNotice("Thanks! Your review with photos was submitted and is awaiting moderation.");
      setOrderId("");
      setOrderLineId("");
      setRating("");
      setTitle("");
      setBody("");
      setPhotos([]);
    } catch (caught) {
      setError(caught instanceof Error && caught.message ? caught.message : "Your review could not be submitted.");
    } finally {
      setSubmitting(false);
    }
  };

  const openItem = openIndex !== null ? items[openIndex] : undefined;

  return (
    <section className="product-photo-reviews" data-testid="product-photo-reviews" aria-labelledby={headingId}>
      <div className="section-heading">
        <div>
          <p className="eyebrow">Customer photos</p>
          <h2 id={headingId}>Photos from reviews</h2>
        </div>
      </div>
      {loadState === "loading" && <p className="muted" role="status">Loading review photos...</p>}
      {loadState === "ready" && items.length > 0 && (
        <>
          <ul
            data-testid="photo-review-gallery"
            style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(120px, 1fr))", gap: "8px", listStyle: "none", margin: 0, padding: 0 }}
          >
            {items.map((item, index) => (
              <li key={item.id || `${item.review_id}-${item.sort}`}>
                <button
                  type="button"
                  data-testid={`photo-review-thumb-${index}`}
                  onClick={() => openPhoto(index)}
                  aria-label={`Open ${photoAlt(item, index)}`}
                  style={{ display: "block", width: "100%", padding: 0, border: 0, background: "transparent", cursor: "pointer" }}
                >
                  <img
                    src={item.url}
                    alt={photoAlt(item, index)}
                    loading="lazy"
                    decoding="async"
                    style={{ display: "block", width: "100%", aspectRatio: "4 / 3", objectFit: "cover" }}
                  />
                </button>
              </li>
            ))}
          </ul>
          {openItem && openIndex !== null && (
            <Modal
              open={openIndex !== null}
              onClose={() => setOpenIndex(null)}
              title={`Photo ${openIndex + 1} of ${items.length}`}
              description={openItem.rating > 0 ? `${openItem.rating} out of 5 stars` : undefined}
              size="lg"
              closeLabel="Close photo viewer"
            >
              <div data-testid="photo-review-lightbox" style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: "12px" }}>
                <img
                  src={openItem.url}
                  alt={photoAlt(openItem, openIndex)}
                  style={{ maxWidth: "100%", maxHeight: "75vh", objectFit: "contain" }}
                />
                <p className="muted" style={{ margin: 0 }}>
                  Photo {openIndex + 1} of {items.length}
                  {openItem.rating > 0 ? ` — ${openItem.rating} out of 5 stars` : ""}
                </p>
                <div style={{ display: "flex", gap: "8px" }}>
                  <button type="button" className="button" data-testid="photo-review-prev" onClick={() => setOpenIndex((openIndex - 1 + items.length) % items.length)} aria-label="Previous photo">
                    Previous
                  </button>
                  <button type="button" className="button" data-testid="photo-review-next" onClick={() => setOpenIndex((openIndex + 1) % items.length)} aria-label="Next photo">
                    Next
                  </button>
                </div>
              </div>
            </Modal>
          )}
        </>
      )}
      {signedIn ? (
        <form className="product-photo-reviews-form" data-testid="photo-review-form" onSubmit={(event) => void submitReview(event)} noValidate>
          <p className="muted">Bought this product? Add photos to your review. Photos appear here after moderation.</p>
          <label htmlFor={`photo-review-order-${headingId}`}>
            Order ID
            <input id={`photo-review-order-${headingId}`} value={orderId} onChange={(event) => setOrderId(event.target.value)} autoComplete="off" required />
          </label>
          <label htmlFor={`photo-review-line-${headingId}`}>
            Order line ID
            <input id={`photo-review-line-${headingId}`} value={orderLineId} onChange={(event) => setOrderLineId(event.target.value)} autoComplete="off" required />
          </label>
          <label htmlFor={`photo-review-rating-${headingId}`}>
            Rating (1–5)
            <input id={`photo-review-rating-${headingId}`} type="number" min={1} max={5} value={rating} onChange={(event) => setRating(event.target.value)} required />
          </label>
          <label htmlFor={`photo-review-title-${headingId}`}>
            Title
            <input id={`photo-review-title-${headingId}`} value={title} onChange={(event) => setTitle(event.target.value)} autoComplete="off" />
          </label>
          <label htmlFor={`photo-review-body-${headingId}`}>
            Your review
            <textarea id={`photo-review-body-${headingId}`} rows={4} value={body} onChange={(event) => setBody(event.target.value)} required />
          </label>
          <label htmlFor={`photo-review-files-${headingId}`}>
            Photos (up to {MAX_PHOTOS})
            <input
              id={`photo-review-files-${headingId}`}
              type="file"
              accept="image/*"
              multiple
              disabled={uploading}
              onChange={(event) => void uploadFiles(event.target.files)}
            />
          </label>
          {photos.length > 0 && (
            <ul data-testid="photo-review-staged" style={{ listStyle: "none", margin: 0, padding: 0 }}>
              {photos.map((photo) => (
                <li key={photo.url}>
                  <span>{photo.name}</span>{" "}
                  <button
                    type="button"
                    onClick={() => setPhotos((current) => current.filter((entry) => entry.url !== photo.url))}
                    aria-label={`Remove ${photo.name}`}
                  >
                    Remove
                  </button>
                </li>
              ))}
            </ul>
          )}
          {uploading && <p className="muted" role="status">Uploading photos...</p>}
          <button className="button" type="submit" disabled={submitting || uploading}>
            {submitting ? "Submitting..." : "Submit review with photos"}
          </button>
          {notice ? <p className="form-notice" role="status">{notice}</p> : null}
          {error ? <p className="form-error" role="alert">{error}</p> : null}
        </form>
      ) : (
        <p className="muted" data-testid="photo-review-signin-note">Sign in to add photos to your review.</p>
      )}
    </section>
  );
}
