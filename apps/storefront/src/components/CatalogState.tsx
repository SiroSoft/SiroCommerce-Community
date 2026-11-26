"use client";

import Link from "next/link";
import type { CatalogRequestError } from "@/services/catalog";
import { getCopy, type Locale } from "@/lib/i18n";

export function CatalogState({ error, locale }: { error: CatalogRequestError; locale?: Locale }) {
  const text = getCopy(locale).catalogError;
  const message = error.status === 401 ? text.unauthorized : error.status === 403 ? text.forbidden : error.status === 404 ? text.notFound : error.status === 429 ? text.rateLimited : error.status >= 500 ? text.serverError : text.generic;
  return <section role="alert" className="page-intro catalog-state" aria-labelledby="catalog-error-title"><p className="eyebrow">{text.eyebrow}</p><h1 id="catalog-error-title">{message}</h1><p>{error.status === 429 ? text.retryHint : text.persistentHint}</p><div className="state-actions"><button className="button" type="button" onClick={() => window.location.reload()}>{text.tryAgain}</button><Link className="text-link" href="/">{text.returnHome}</Link></div></section>;
}
