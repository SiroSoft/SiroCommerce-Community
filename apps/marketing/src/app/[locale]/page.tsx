import { notFound } from "next/navigation";
import { MarketingPage } from "@/components/MarketingPage";
import { getContent, isLocale } from "@/content";
import { siteUrl } from "@/lib/site";

export default async function LocalePage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const content = getContent(raw);
  const faq = {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    inLanguage: content.meta.htmlLang,
    url: `${siteUrl}/${raw}/`,
    mainEntity: content.faq.items.map((item) => ({
      "@type": "Question",
      name: item.question,
      acceptedAnswer: { "@type": "Answer", text: item.answer },
    })),
  };
  return (
    <>
      <MarketingPage locale={raw} content={content} />
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(faq) }} />
    </>
  );
}
