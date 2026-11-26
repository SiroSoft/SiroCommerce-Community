import Link from "next/link";
import { getCopy } from "@/lib/i18n";
import { requestLocale } from "@/lib/request-locale";

export default async function NotFound() {
  const copy = getCopy(await requestLocale()).states;
  return <section className="state-page" aria-labelledby="not-found-title">
    <p className="eyebrow">{copy.notFoundEyebrow}</p>
    <h1 id="not-found-title">{copy.notFoundTitle}</h1>
    <p>{copy.notFoundCopy}</p>
    <div className="state-actions">
      <Link className="button" href="/">{copy.returnHome}</Link>
      <Link className="text-link" href="/search">{copy.searchCollection}</Link>
    </div>
  </section>;
}
