import { getCopy, type Locale } from "@/lib/i18n";
import { jsonLd } from "@/lib/structured-data";
import { readFaqEntries, readTextProp } from "@/homepage/section-data";

export function FaqAccordion({ props, locale }: { props?: Record<string, unknown>; locale?: Locale }) {
  const copy = getCopy(locale);
  const entries = readFaqEntries(props);
  if (entries.length === 0) return null;
  const eyebrow = readTextProp(props, "eyebrow") ?? copy.home.faqEyebrow;
  const title = readTextProp(props, "title") ?? copy.home.faqTitle;
  const schema = {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    inLanguage: locale,
    mainEntity: entries.map((entry) => ({
      "@type": "Question",
      name: entry.question,
      acceptedAnswer: { "@type": "Answer", text: entry.answer },
    })),
  };
  return <>
    <div className="sf-sec-faq">
      <div className="sf-sec-heading">
        <p className="eyebrow">{eyebrow}</p>
        <h2>{title}</h2>
      </div>
      <div className="sf-sec-faq-list">
        {entries.map((entry, index) => <details className="sf-sec-faq-item" key={`${entry.question}-${index}`}>
          <summary className="sf-sec-faq-summary">{entry.question}</summary>
          <div className="sf-sec-faq-answer"><p>{entry.answer}</p></div>
        </details>)}
      </div>
    </div>
    <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(schema) }} />
  </>;
}
