import type { CmsBlock } from "@/lib/cms";
import { CmsCta } from "@/components/cms/CmsCta";
import { CmsFaq } from "@/components/cms/CmsFaq";
import { CmsHero } from "@/components/cms/CmsHero";
import { CmsImage } from "@/components/cms/CmsImage";
import { CmsRichText } from "@/components/cms/CmsRichText";

export function CmsBlocks({ blocks, body, title }: { blocks?: CmsBlock[] | null; body?: string | null; title: string }) {
  const published = (blocks ?? []).filter((block) => !block.status || block.status === "published");
  if (published.length === 0) return <CmsRichText html={body} />;
  return <>{published.map((block, index) => {
    const key = block.id ?? `${block.type}-${index}`;
    switch (block.type) {
      case "hero":
        return <CmsHero block={block} title={title} key={key} />;
      case "image":
        return <CmsImage block={block} key={key} />;
      case "cta":
        return <CmsCta block={block} key={key} />;
      case "faq":
        return <CmsFaq block={block} key={key} />;
      case "text":
      case "rich_text":
      case "markdown":
        return <CmsRichText html={block.content} key={key} />;
      case "internal":
        return null;
      default:
        return block.content ? <CmsRichText html={block.content} key={key} /> : null;
    }
  })}</>;
}
