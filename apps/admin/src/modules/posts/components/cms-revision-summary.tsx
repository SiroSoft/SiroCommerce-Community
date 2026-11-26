"use client";

import { useQuery } from "@tanstack/react-query";
import { cmsService } from "@/services/cms.service";

export function CmsRevisionSummary({ pageId }: { pageId: string }) {
  const revisions = useQuery({ queryKey: ["cms-revisions", pageId], queryFn: () => cmsService.revisions("page", pageId) });
  if (revisions.isLoading) return <p className="text-sm text-muted-foreground">Loading revision history...</p>;
  if (revisions.isError) return <p className="text-sm text-destructive">Revision history unavailable.</p>;
  return <div aria-label="CMS revision history" className="space-y-1 text-sm"><strong>Revision history</strong>{revisions.data?.slice(0, 5).map((revision) => <div key={revision.id} className="flex justify-between gap-3"><span>v{revision.version} · {revision.action}</span><span className="text-muted-foreground">{revision.created_at ?? ""}</span></div>)}</div>;
}
