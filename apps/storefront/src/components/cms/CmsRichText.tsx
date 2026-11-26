import { sanitizeCmsHtml } from "@/lib/cms";

export function CmsRichText({ html, className }: { html?: string | null; className?: string }) {
  const safe = sanitizeCmsHtml(html);
  if (!safe) return null;
  return <div className={className ? `sf-cms-richtext ${className}` : "sf-cms-richtext"} dangerouslySetInnerHTML={{ __html: safe }} />;
}
