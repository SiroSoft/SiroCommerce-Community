import type { CmsBlock } from "@/lib/cms";
import { readCmsCta } from "@/lib/cms";

export function CmsCta({ block }: { block: CmsBlock }) {
  const cta = readCmsCta(block);
  if (!cta) return null;
  return <section className="sf-cms-cta">
    {cta.copy ? <p>{cta.copy}</p> : null}
    {cta.href
      ? <a className="sf-cms-cta-link" href={cta.href}>{cta.label}</a>
      : <span className="sf-cms-cta-label">{cta.label}</span>}
  </section>;
}
