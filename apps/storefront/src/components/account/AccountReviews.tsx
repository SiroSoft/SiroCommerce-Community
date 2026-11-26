"use client";

import { useMemo, useState, type FormEvent } from "react";
import { customer, CustomerRequestError, type CustomerAdapter } from "@/services/customer";
import { useCopy } from "@/lib/i18n-client";

export function AccountReviews({ sessionToken, adapter }: { sessionToken?: string; adapter?: CustomerAdapter }) {
  const copy = useCopy().accountReviews;
  const client = useMemo(() => adapter ?? customer(), [adapter]);
  const signedIn = Boolean(sessionToken);
  const [productId, setProductId] = useState("");
  const [orderId, setOrderId] = useState("");
  const [orderLineId, setOrderLineId] = useState("");
  const [rating, setRating] = useState("");
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [status, setStatus] = useState<"idle" | "submitting" | "success" | "error">("idle");
  const [message, setMessage] = useState("");

  if (!signedIn) {
    return (
      <section className="account-panel" aria-labelledby="account-reviews-title">
        <div className="account-section-heading">
          <h3 id="account-reviews-title">{copy.title}</h3>
        </div>
        <p className="muted" role="note">{copy.signInRequired}</p>
      </section>
    );
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    const errors: Record<string, string> = {};
    if (!productId.trim()) errors.productId = copy.requiredField;
    if (!orderId.trim()) errors.orderId = copy.requiredField;
    if (!orderLineId.trim()) errors.orderLineId = copy.requiredField;
    const parsedRating = Number(rating);
    if (!Number.isInteger(parsedRating) || parsedRating < 1 || parsedRating > 5) errors.rating = copy.ratingRange;
    if (!body.trim()) errors.body = copy.requiredField;
    setFieldErrors(errors);
    if (Object.keys(errors).length > 0) return;
    setStatus("submitting");
    setMessage("");
    try {
      await client.submitReview(productId.trim(), {
        order_id: orderId.trim(),
        order_line_id: orderLineId.trim(),
        rating: parsedRating,
        ...(title.trim() ? { title: title.trim() } : {}),
        body: body.trim(),
      });
      setStatus("success");
      setMessage(copy.success);
      setTitle("");
      setBody("");
    } catch (error) {
      setStatus("error");
      setMessage(error instanceof CustomerRequestError && error.status === 401 ? copy.signInRequired : copy.submitError);
    }
  }

  return (
    <section className="account-panel" aria-labelledby="account-reviews-title">
      <div className="account-section-heading">
        <h3 id="account-reviews-title">{copy.title}</h3>
      </div>
      <p className="muted">{copy.intro}</p>
      <p className="muted" role="note">{copy.noHistory}</p>
      <h4>{copy.formTitle}</h4>
      <form className="auth-form" onSubmit={submit} noValidate>
        <label htmlFor="account-review-product-id">
          {copy.productIdLabel}
          <input
            id="account-review-product-id"
            value={productId}
            onChange={(event) => setProductId(event.target.value)}
            aria-invalid={Boolean(fieldErrors.productId)}
            aria-describedby={fieldErrors.productId ? "account-review-product-id-error" : undefined}
            autoComplete="off"
          />
        </label>
        {fieldErrors.productId && (
          <p id="account-review-product-id-error" className="form-error" role="alert">{fieldErrors.productId}</p>
        )}
        <label htmlFor="account-review-order-id">
          {copy.orderIdLabel}
          <input
            id="account-review-order-id"
            value={orderId}
            onChange={(event) => setOrderId(event.target.value)}
            aria-invalid={Boolean(fieldErrors.orderId)}
            aria-describedby={fieldErrors.orderId ? "account-review-order-id-error" : undefined}
            autoComplete="off"
          />
        </label>
        {fieldErrors.orderId && (
          <p id="account-review-order-id-error" className="form-error" role="alert">{fieldErrors.orderId}</p>
        )}
        <label htmlFor="account-review-order-line-id">
          {copy.orderLineIdLabel}
          <input
            id="account-review-order-line-id"
            value={orderLineId}
            onChange={(event) => setOrderLineId(event.target.value)}
            aria-invalid={Boolean(fieldErrors.orderLineId)}
            aria-describedby={fieldErrors.orderLineId ? "account-review-order-line-id-error" : undefined}
            autoComplete="off"
          />
        </label>
        {fieldErrors.orderLineId && (
          <p id="account-review-order-line-id-error" className="form-error" role="alert">{fieldErrors.orderLineId}</p>
        )}
        <label htmlFor="account-review-rating">
          {copy.ratingLabel}
          <input
            id="account-review-rating"
            type="number"
            min={1}
            max={5}
            step={1}
            value={rating}
            onChange={(event) => setRating(event.target.value)}
            aria-invalid={Boolean(fieldErrors.rating)}
            aria-describedby={fieldErrors.rating ? "account-review-rating-error" : undefined}
          />
        </label>
        {fieldErrors.rating && (
          <p id="account-review-rating-error" className="form-error" role="alert">{fieldErrors.rating}</p>
        )}
        <label htmlFor="account-review-title">
          {copy.reviewTitleLabel}
          <input
            id="account-review-title"
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            autoComplete="off"
          />
        </label>
        <label htmlFor="account-review-body">
          {copy.bodyLabel}
          <textarea
            id="account-review-body"
            value={body}
            onChange={(event) => setBody(event.target.value)}
            aria-invalid={Boolean(fieldErrors.body)}
            aria-describedby={fieldErrors.body ? "account-review-body-error" : undefined}
          />
        </label>
        {fieldErrors.body && (
          <p id="account-review-body-error" className="form-error" role="alert">{fieldErrors.body}</p>
        )}
        <button className="button" type="submit" aria-busy={status === "submitting"} disabled={status === "submitting"}>
          {status === "submitting" ? copy.submitting : copy.submit}
        </button>
        {message && status === "success" && (
          <p className="form-notice" role="status">{message}</p>
        )}
        {message && status === "error" && (
          <p className="form-error" role="alert">{message}</p>
        )}
      </form>
    </section>
  );
}
