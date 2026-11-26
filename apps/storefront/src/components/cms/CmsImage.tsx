import type { CmsBlock } from "@/lib/cms";
import { readCmsImage } from "@/lib/cms";

export function CmsImage({ block }: { block: CmsBlock }) {
  const image = readCmsImage(block);
  if (!image) return null;
  const media = <img src={image.src} alt={image.alt} loading="lazy" />;
  return <figure className="sf-cms-image">
    {image.href ? <a href={image.href}>{media}</a> : media}
    {image.caption ? <figcaption>{image.caption}</figcaption> : null}
  </figure>;
}
