"use client";

import Link from "next/link";
import { useCopy } from "@/lib/i18n-client";

export default function StorefrontError({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  const copy = useCopy().states;
  return <section className="state-page" role="alert" aria-labelledby="storefront-error-title">
    <p className="eyebrow">{copy.errorEyebrow}</p>
    <h1 id="storefront-error-title">{copy.errorTitle}</h1>
    <p>{copy.errorCopy}</p>
    <div className="state-actions">
      <button className="button" type="button" onClick={reset}>{copy.tryAgain}</button>
      <Link className="text-link" href="/">{copy.returnHome}</Link>
    </div>
  </section>;
}
