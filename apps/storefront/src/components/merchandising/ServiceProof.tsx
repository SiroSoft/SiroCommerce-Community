import Link from "next/link";
import { getCopy, type Locale } from "@/lib/i18n";
import { Reveal } from "@/components/Reveal";

export function ServiceProof({ locale }: { locale?: Locale }) {
  const copy = getCopy(locale).proof;
  const proofPoints = [
    { title: copy.point1t, copy: copy.point1c },
    { title: copy.point2t, copy: copy.point2c },
    { title: copy.point3t, copy: copy.point3c },
  ];
  return <section className="section" aria-labelledby="service-proof-title"><Reveal>
    <div className="section-heading">
      <div><p className="eyebrow">{copy.eyebrow}</p><h2 id="service-proof-title">{copy.title}</h2></div>
      <Link className="text-link" href="/search">{copy.explore}</Link>
    </div>
    <div className="trust-band">
      {proofPoints.map((point, index) => <article className="trust-item" key={point.title}>
        <span className="trust-index" aria-hidden="true">{String(index + 1).padStart(2, "0")}</span>
        <h3>{point.title}</h3>
        <p>{point.copy}</p>
      </article>)}
    </div>
  </Reveal></section>;
}
