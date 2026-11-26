"use client";

import Link from "next/link";
import { useCopy } from "@/lib/i18n-client";

export default function OfflinePage() {
  const copy = useCopy().states;
  return <section className="state-page"><p className="eyebrow">{copy.offlineEyebrow}</p><h1>{copy.offlineTitle}</h1><p>{copy.offlineCopy}</p><div className="state-actions"><button className="button" type="button" onClick={() => window.location.reload()}>{copy.tryAgain}</button><Link className="text-link" href="/">{copy.returnHome}</Link></div></section>;
}
