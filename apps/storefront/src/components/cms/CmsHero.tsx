import type { CmsBlock } from "@/lib/cms";
import { readCmsHero } from "@/lib/cms";

export function CmsHero({ block, title }: { block: CmsBlock; title: string }) {
  const hero = readCmsHero(block, title);
  return <section className="sf-cms-hero">
    <div className="sf-cms-hero-copy">
      <h1>{hero.title}</h1>
      {hero.subtitleHtml ? <div className="sf-cms-hero-subtitle" dangerouslySetInnerHTML={{ __html: hero.subtitleHtml }} /> : hero.subtitle ? <p className="sf-cms-hero-subtitle">{hero.subtitle}</p> : null}
      {hero.ctaLabel ? hero.ctaHref
        ? <a className="sf-cms-cta-link" href={hero.ctaHref}>{hero.ctaLabel}</a>
        : <span className="sf-cms-cta-label">{hero.ctaLabel}</span>
        : null}
    </div>
    {hero.image ? <figure className="sf-cms-hero-media"><img src={hero.image} alt={hero.imageAlt ?? ""} loading="lazy" /></figure> : null}
  </section>;
}
