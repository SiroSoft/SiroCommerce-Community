import type { CmsBlock } from "@/lib/cms";
import { readCmsFaq } from "@/lib/cms";

export function CmsFaq({ block }: { block: CmsBlock }) {
  const faq = readCmsFaq(block);
  if (faq.html) return <div className="sf-cms-faq" dangerouslySetInnerHTML={{ __html: faq.html }} />;
  if (faq.entries.length === 0) return null;
  return <section className="sf-cms-faq" aria-label="Frequently asked questions">
    {faq.entries.map((entry, index) => <details className="sf-cms-faq-item" key={`${entry.question}-${index}`}>
      <summary className="sf-cms-faq-summary">{entry.question}</summary>
      <div className="sf-cms-faq-answer"><p>{entry.answer}</p></div>
    </details>)}
  </section>;
}
