"use client";

import { useEffect, useMemo, useState, type FormEvent } from "react";
import { ArrowDown, ArrowUp, Edit, ExternalLink, Plus, Save, Send } from "lucide-react";
import { PageHeader } from "@/components/page-header";
import { CapabilityGate } from "@/components/capability-gate";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { EmptyState } from "@/components/empty-state";
import { ApiState } from "@/modules/merchant/api-state";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import { formatDate } from "@/lib/utils";
import { AdminApiError } from "@/services/api-errors";
import type { CmsBlock, CmsPage, CmsScheduleState } from "@/services/cms.service";
import {
  useAddCmsBlock,
  useCmsPagePreview,
  useCmsPageRevisions,
  useCmsPages,
  useCreateCmsPage,
  usePublishCmsPage,
  useRestoreCmsRevision,
  useUpdateCmsBlock,
  useUpdateCmsPage,
} from "@/hooks/use-cms-pages";

const STOREFRONT_URL = (process.env.NEXT_PUBLIC_STOREFRONT_URL || "https://ecommerce.sirophp.com").replace(/\/+$/, "");
const SLUG_PATTERN = /^[a-z0-9][a-z0-9-]{0,159}$/;

const BLOCK_TYPES = [
  { value: "rich_text", label: "Rich text", supported: true },
  { value: "hero", label: "Hero", supported: true },
  { value: "image", label: "Image", supported: true },
  { value: "cta", label: "Call to action", supported: false },
  { value: "faq", label: "FAQ", supported: false },
] as const;

type FormState = {
  slug: string;
  title: string;
  locale: string;
  status: CmsScheduleState;
  scheduled_at: string;
  body: string;
};

type Confirmation = {
  title: string;
  description: string;
  confirmLabel: string;
  run: () => void;
};

const EMPTY_FORM: FormState = { slug: "", title: "", locale: "en", status: "draft", scheduled_at: "", body: "" };

function pagePreviewHref(slug: string): string {
  return `${STOREFRONT_URL}/pages/${encodeURIComponent(slug)}`;
}

function validateForm(form: FormState): string | null {
  if (!SLUG_PATTERN.test(form.slug.trim())) return "Slug must be lowercase letters, numbers, and hyphens (max 160 characters).";
  if (form.title.trim().length < 2) return "Title must be at least 2 characters.";
  if (!form.locale.trim()) return "Locale is required.";
  if (form.status === "scheduled" && !form.scheduled_at.trim()) return "Scheduled pages require a scheduled date and time.";
  return null;
}

function jsonError(value: string): string | null {
  if (!value.trim()) return "Content is required.";
  try {
    JSON.parse(value);
    return null;
  } catch {
    return "Content must be valid JSON.";
  }
}

function toForm(page: CmsPage): FormState {
  return {
    slug: page.slug ?? "",
    title: page.title ?? "",
    locale: page.locale ?? "en",
    status: (page.status as CmsScheduleState) ?? "draft",
    scheduled_at: page.scheduled_at ?? "",
    body: page.body ?? "",
  };
}

export default function PagesPage() {
  return (
    <CapabilityGate capability="cms">
      <PagesContent />
    </CapabilityGate>
  );
}

function PagesContent() {
  const [statusFilter, setStatusFilter] = useState("");
  const [slugFilter, setSlugFilter] = useState("");
  const [editingId, setEditingId] = useState<string | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [formError, setFormError] = useState<string | null>(null);
  const [conflict, setConflict] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmation, setConfirmation] = useState<Confirmation | null>(null);
  const [blockType, setBlockType] = useState<string>("rich_text");
  const [blockContent, setBlockContent] = useState("{\n  \"text\": \"\"\n}");
  const [blockSort, setBlockSort] = useState("10");
  const [blockError, setBlockError] = useState<string | null>(null);
  const [blockBusy, setBlockBusy] = useState(false);

  const pagesQuery = useCmsPages();
  const detail = useCmsPagePreview(editingId ?? undefined);
  const revisions = useCmsPageRevisions(editingId ?? undefined);
  const createPage = useCreateCmsPage();
  const updatePage = useUpdateCmsPage(editingId ?? "");
  const addBlock = useAddCmsBlock(editingId ?? "");
  const updateBlock = useUpdateCmsBlock();
  const publishPage = usePublishCmsPage();
  const restoreRevision = useRestoreCmsRevision();

  const editing = Boolean(editingId);
  const detailPage = detail.data;
  const orderedBlocks = useMemo(
    () => [...(detailPage?.blocks ?? [])].sort((left, right) => (left.sort ?? 0) - (right.sort ?? 0) || left.id.localeCompare(right.id)),
    [detailPage?.blocks],
  );
  const pageItems = useMemo(() => {
    const query = slugFilter.trim().toLowerCase();
    return pagesQuery.pages.filter((page) => {
      const statusMatch = !statusFilter || (page.status ?? "") === statusFilter;
      const slugMatch = !query || (page.slug ?? "").toLowerCase().includes(query);
      return statusMatch && slugMatch;
    });
  }, [pagesQuery.pages, slugFilter, statusFilter]);

  useEffect(() => {
    setBlockSort(String((orderedBlocks.reduce((max, block) => Math.max(max, block.sort ?? 0), 0) || 0) + 10));
    setBlockContent("{\n  \"text\": \"\"\n}");
    setBlockError(null);
  }, [detailPage?.id]);

  const resetForm = () => {
    setForm(EMPTY_FORM);
    setFormError(null);
    setConflict(false);
    setBlockError(null);
  };

  const startCreate = () => {
    setEditingId(null);
    setShowCreate(true);
    setNotice(null);
    resetForm();
  };

  const startEdit = (page: CmsPage) => {
    setEditingId(page.id);
    setShowCreate(false);
    setForm(toForm(page));
    setNotice(null);
    setFormError(null);
    setConflict(false);
  };

  const cancelEditor = () => {
    setEditingId(null);
    setShowCreate(false);
    resetForm();
  };

  const reload = () => {
    setConflict(false);
    setFormError(null);
    void pagesQuery.refetch();
    if (editingId) void detail.refetch();
  };

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFormError(null);
    setConflict(false);
    setNotice(null);
    const validation = validateForm(form);
    if (validation) {
      setFormError(validation);
      return;
    }
    const payload = {
      slug: form.slug.trim(),
      title: form.title.trim(),
      locale: form.locale.trim(),
      status: form.status,
      body: form.body.trim() ? form.body : null,
      ...(form.status === "scheduled" ? { scheduled_at: form.scheduled_at } : {}),
    };
    const onError = (error: unknown) => {
      const normalized = error instanceof AdminApiError ? error : new AdminApiError({ message: "The page could not be saved." });
      if (normalized.status === 409) setConflict(true);
      else setFormError(normalized.message);
    };
    if (editingId) {
      updatePage.mutate(payload, {
        onSuccess: (page) => {
          if (page) setForm(toForm(page));
          setNotice("Page saved.");
        },
        onError,
      });
      return;
    }
    createPage.mutate(payload, {
      onSuccess: (page) => {
        setShowCreate(false);
        setNotice("Page created. Add blocks below.");
        if (page?.id) {
          setEditingId(page.id);
          setForm(toForm(page));
        }
      },
      onError,
    });
  };

  const addBlockToPage = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setNotice(null);
    setBlockError(null);
    if (!editingId) {
      setBlockError("Save the page before adding blocks.");
      return;
    }
    const selected = BLOCK_TYPES.find((item) => item.value === blockType);
    if (selected && !selected.supported) {
      setBlockError(`The CMS API does not accept the ${selected.label} block type yet. Choose rich_text, hero, or image.`);
      return;
    }
    const invalid = jsonError(blockContent);
    if (invalid) {
      setBlockError(invalid);
      return;
    }
    addBlock.mutate(
      { type: blockType, content: blockContent.trim(), sort: Number(blockSort) || 0 },
      {
        onSuccess: () => {
          setBlockError(null);
          setBlockContent("{\n  \"text\": \"\"\n}");
          setBlockSort(String((Number(blockSort) || 0) + 10));
          setNotice("Block added.");
        },
        onError: (error) => {
          const normalized = error instanceof AdminApiError ? error : new AdminApiError({ message: "The block could not be added." });
          setBlockError(normalized.message);
        },
      },
    );
  };

  const saveBlock = (block: CmsBlock, content: string) => {
    const invalid = jsonError(content);
    if (invalid) {
      setBlockError(invalid);
      return;
    }
    setBlockError(null);
    updateBlock.mutate(
      { id: block.id, data: { content: content.trim() } },
      {
        onSuccess: () => setNotice("Block saved."),
        onError: (error) => {
          const normalized = error instanceof AdminApiError ? error : new AdminApiError({ message: "The block could not be saved." });
          setBlockError(normalized.message);
        },
      },
    );
  };

  const moveBlock = (block: CmsBlock, direction: "up" | "down") => {
    const index = orderedBlocks.findIndex((item) => item.id === block.id);
    const target = direction === "up" ? index - 1 : index + 1;
    if (index < 0 || target < 0 || target >= orderedBlocks.length) return;
    const next = [...orderedBlocks];
    [next[index], next[target]] = [next[target], next[index]];
    const changes = next
      .map((item, position) => ({ id: item.id, sort: (position + 1) * 10, current: item.sort ?? 0 }))
      .filter((change) => change.current !== change.sort);
    if (changes.length === 0) return;
    setBlockBusy(true);
    setBlockError(null);
    void Promise.all(
      changes.map(
        (change) =>
          new Promise<void>((resolve, reject) => {
            updateBlock.mutate(
              { id: change.id, data: { sort: change.sort } },
              { onSuccess: () => resolve(), onError: (error) => reject(error) },
            );
          }),
      ),
    )
      .then(() => setNotice("Block order updated."))
      .catch((error) => {
        const normalized = error instanceof AdminApiError ? error : new AdminApiError({ message: "The block order could not be saved." });
        setBlockError(normalized.message);
      })
      .finally(() => setBlockBusy(false));
  };

  const requestPublish = (page: CmsPage) => {
    setConfirmation({
      title: "Publish page",
      description: `Publish "${page.title}" now? The storefront will serve the published version.`,
      confirmLabel: "Publish",
      run: () => {
        publishPage.mutate(
          { id: page.id },
          {
            onSuccess: () => setNotice("Page published."),
            onError: (error) => {
              const normalized = error instanceof AdminApiError ? error : new AdminApiError({ message: "The page could not be published." });
              if (normalized.status === 409) setConflict(true);
              else setNotice(normalized.message);
            },
          },
        );
      },
    });
  };

  const requestRestore = (revisionId: string, version: number) => {
    if (!editingId) return;
    setConfirmation({
      title: "Restore revision",
      description: `Restore page to revision v${version}? Current content will be replaced and a new revision will be recorded.`,
      confirmLabel: "Restore",
      run: () => {
        restoreRevision.mutate(
          { id: editingId, revision: revisionId },
          {
            onSuccess: () => setNotice(`Revision v${version} restored.`),
            onError: (error) => {
              const normalized = error instanceof AdminApiError ? error : new AdminApiError({ message: "The revision could not be restored." });
              if (normalized.status === 409) setConflict(true);
              else setNotice(normalized.message);
            },
          },
        );
      },
    });
  };

  const saving = createPage.isPending || updatePage.isPending;
  const revisionItems = revisions.isError ? [] : (revisions.data ?? []).slice(0, 5);

  return (
    <div className="space-y-6">
      <PageHeader title="Pages" description="Create CMS pages, edit structured blocks, preview the storefront, and publish revisions.">
        <Button onClick={startCreate}>
          <Plus className="mr-2 h-4 w-4" />
          New page
        </Button>
      </PageHeader>

      {notice && <p role="status" className="rounded-md border bg-muted px-3 py-2 text-sm">{notice}</p>}
      {conflict && (
        <div role="alert" className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-900 dark:text-amber-200">
          <strong>Stale page state (HTTP 409).</strong> This page changed since it was loaded. Reload before saving your changes.
          <Button size="sm" variant="outline" className="ml-3" onClick={reload}>Reload</Button>
        </div>
      )}

      {(showCreate || editing) && (
        <Card>
          <CardHeader>
            <CardTitle>{editing ? "Edit page" : "Create page"}</CardTitle>
            <CardDescription>Slug, title, and locale identify the page. Status controls storefront visibility.</CardDescription>
          </CardHeader>
          <CardContent>
            <form className="space-y-4" onSubmit={handleSubmit} noValidate>
              <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="page-slug">Slug</Label>
                  <Input id="page-slug" value={form.slug} onChange={(event) => setForm({ ...form, slug: event.target.value })} placeholder="about-us" aria-invalid={Boolean(formError)} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="page-title">Title</Label>
                  <Input id="page-title" value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} placeholder="About us" aria-invalid={Boolean(formError)} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="page-locale">Locale</Label>
                  <Input id="page-locale" value={form.locale} onChange={(event) => setForm({ ...form, locale: event.target.value })} placeholder="en" />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="page-status">Status</Label>
                  <select id="page-status" className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm" value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value as CmsScheduleState })}>
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                    <option value="scheduled">Scheduled</option>
                    <option value="archived">Archived</option>
                  </select>
                </div>
                {form.status === "scheduled" && (
                  <div className="space-y-2 md:col-span-2">
                    <Label htmlFor="page-scheduled">Scheduled at</Label>
                    <Input id="page-scheduled" type="datetime-local" value={form.scheduled_at} onChange={(event) => setForm({ ...form, scheduled_at: event.target.value })} />
                  </div>
                )}
              </div>
              <div className="space-y-2">
                <Label htmlFor="page-body">Body</Label>
                <Textarea id="page-body" rows={6} value={form.body} onChange={(event) => setForm({ ...form, body: event.target.value })} placeholder="<p>Page content</p>" />
              </div>
              {formError && <p role="alert" className="text-sm text-destructive">{formError}</p>}
              <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={saving}>
                  <Save className="mr-2 h-4 w-4" />
                  {saving ? "Saving..." : editing ? "Save changes" : "Create page"}
                </Button>
                <Button type="button" variant="outline" onClick={cancelEditor} disabled={saving}>Cancel</Button>
              </div>
            </form>
          </CardContent>
        </Card>
      )}

      {editing && (
        <Card>
          <CardHeader>
            <CardTitle>Blocks</CardTitle>
            <CardDescription>Blocks are ordered by sort. The CMS API supports create and update for blocks; delete is not exposed, so remove content or repurpose a block instead.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {detail.isLoading ? (
              <p role="status" className="text-sm text-muted-foreground">Loading page blocks...</p>
            ) : detail.isError ? (
              <ApiState error={detail.error} onRetry={() => detail.refetch()} />
            ) : null}
            {!detail.isLoading && !detail.isError && orderedBlocks.length === 0 && (
              <EmptyState title="No blocks yet" description="Add the first block for this page." />
            )}
            {orderedBlocks.length > 0 && (
              <ul className="space-y-3" aria-label="Page blocks">
                {orderedBlocks.map((block, index) => (
                  <BlockCard
                    key={block.id}
                    block={block}
                    canMoveUp={index > 0}
                    canMoveDown={index < orderedBlocks.length - 1}
                    busy={blockBusy || updateBlock.isPending}
                    onMove={(direction) => moveBlock(block, direction)}
                    onSave={(content) => saveBlock(block, content)}
                  />
                ))}
              </ul>
            )}
            <form className="space-y-3 rounded-lg border p-4" onSubmit={addBlockToPage} noValidate>
              <h3 className="font-medium">Add block</h3>
              <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="block-type">Block type</Label>
                  <select id="block-type" className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm" value={blockType} onChange={(event) => setBlockType(event.target.value)}>
                    {BLOCK_TYPES.map((item) => (
                      <option key={item.value} value={item.value} disabled={!item.supported}>
                        {item.label}{item.supported ? "" : " (not accepted by API yet)"}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="block-sort">Sort</Label>
                  <Input id="block-sort" type="number" value={blockSort} onChange={(event) => setBlockSort(event.target.value)} />
                </div>
              </div>
              <div className="space-y-2">
                <Label htmlFor="block-content">Content (JSON)</Label>
                <Textarea id="block-content" rows={5} value={blockContent} onChange={(event) => setBlockContent(event.target.value)} placeholder={'{"text":"Hello"}'} />
              </div>
              {blockError && <p role="alert" className="text-sm text-destructive">{blockError}</p>}
              <Button type="submit" disabled={addBlock.isPending || blockBusy}>{addBlock.isPending ? "Adding..." : "Add block"}</Button>
            </form>
          </CardContent>
        </Card>
      )}

      {editing && revisionItems.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Recent revisions</CardTitle>
            <CardDescription>Restore an earlier snapshot after confirming.</CardDescription>
          </CardHeader>
          <CardContent>
            <ul className="space-y-2" aria-label="Recent page revisions">
              {revisionItems.map((revision) => (
                <li key={revision.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3 text-sm">
                  <span>
                    <strong>v{revision.version}</strong> · {revision.action}
                    <span className="ml-2 text-xs text-muted-foreground">{formatDate(revision.created_at)}</span>
                  </span>
                  <Button size="sm" variant="outline" onClick={() => requestRestore(revision.id, revision.version)}>Restore v{revision.version}</Button>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>All pages</CardTitle>
          <CardDescription>Filter by status or slug. Preview links open the storefront page in a new tab.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex flex-wrap items-end gap-3">
            <div className="space-y-2">
              <Label htmlFor="filter-status">Filter by status</Label>
              <select id="filter-status" className="flex h-9 rounded-md border border-input bg-transparent px-3 text-sm" value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
                <option value="">All statuses</option>
                <option value="draft">Draft</option>
                <option value="published">Published</option>
                <option value="scheduled">Scheduled</option>
                <option value="archived">Archived</option>
              </select>
            </div>
            <div className="min-w-[220px] space-y-2">
              <Label htmlFor="filter-slug">Filter by slug</Label>
              <Input id="filter-slug" value={slugFilter} onChange={(event) => setSlugFilter(event.target.value)} placeholder="about-us" />
            </div>
          </div>

          {pagesQuery.isLoading ? (
            <p role="status" className="py-8 text-center text-sm text-muted-foreground">Loading pages...</p>
          ) : pagesQuery.isError ? (
            <ApiState error={pagesQuery.error} onRetry={() => pagesQuery.refetch()} />
          ) : pagesQuery.pages.length === 0 ? (
            <EmptyState
              title="No pages yet"
              description="Create the first CMS page for this commerce scope."
              action={<Button onClick={startCreate}><Plus className="mr-2 h-4 w-4" />New page</Button>}
            />
          ) : pageItems.length === 0 ? (
            <EmptyState title="No pages match the filters" description="Change the status or slug filter to see more pages." />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <caption className="sr-only">CMS pages</caption>
                <thead>
                  <tr className="border-b text-left text-muted-foreground">
                    <th scope="col" className="p-3">Title</th>
                    <th scope="col" className="p-3">Slug</th>
                    <th scope="col" className="p-3">Status</th>
                    <th scope="col" className="p-3">Locale</th>
                    <th scope="col" className="p-3">Updated</th>
                    <th scope="col" className="p-3 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {pageItems.map((page) => (
                    <tr key={page.id} className="border-b last:border-0">
                      <td className="p-3 font-medium">{page.title}</td>
                      <td className="p-3 text-muted-foreground">{page.slug}</td>
                      <td className="p-3"><StatusBadge status={page.status} /></td>
                      <td className="p-3"><Badge variant="secondary">{page.locale ?? "en"}</Badge></td>
                      <td className="p-3 text-xs text-muted-foreground">{formatDate(page.updated_at ?? page.created_at)}</td>
                      <td className="p-3">
                        <div className="flex flex-wrap justify-end gap-1">
                          <Button size="sm" variant="outline" onClick={() => startEdit(page)} aria-label={`Edit ${page.title}`}>
                            <Edit className="mr-1 h-4 w-4" />Edit
                          </Button>
                          <Button size="sm" variant="outline" asChild>
                            <a href={pagePreviewHref(page.slug)} target="_blank" rel="noopener noreferrer" aria-label={`Preview ${page.title} in a new tab`}>
                              <ExternalLink className="mr-1 h-4 w-4" />Preview
                            </a>
                          </Button>
                          <Button size="sm" variant="outline" onClick={() => requestPublish(page)} aria-label={`Publish ${page.title}`}>
                            <Send className="mr-1 h-4 w-4" />Publish
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      <ConfirmDialog
        open={Boolean(confirmation)}
        title={confirmation?.title ?? ""}
        description={confirmation?.description}
        confirmLabel={confirmation?.confirmLabel}
        isPending={publishPage.isPending || restoreRevision.isPending}
        onCancel={() => setConfirmation(null)}
        onConfirm={() => {
          const action = confirmation?.run;
          setConfirmation(null);
          action?.();
        }}
      />
    </div>
  );
}

function BlockCard({
  block,
  canMoveUp,
  canMoveDown,
  busy,
  onMove,
  onSave,
}: {
  block: CmsBlock;
  canMoveUp: boolean;
  canMoveDown: boolean;
  busy: boolean;
  onMove: (direction: "up" | "down") => void;
  onSave: (content: string) => void;
}) {
  const [content, setContent] = useState(block.content ?? "");
  const [error, setError] = useState<string | null>(null);
  const contentId = `block-content-${block.id}`;

  useEffect(() => {
    setContent(block.content ?? "");
  }, [block.content]);

  const save = () => {
    const invalid = jsonError(content);
    if (invalid) {
      setError(invalid);
      return;
    }
    setError(null);
    onSave(content);
  };

  return (
    <li className="space-y-3 rounded-lg border p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <Badge>{block.type}</Badge>
          <span className="text-xs text-muted-foreground">Sort {block.sort ?? 0}</span>
        </div>
        <div className="flex gap-1">
          <Button type="button" size="icon" variant="outline" onClick={() => onMove("up")} disabled={!canMoveUp || busy} aria-label={`Move ${block.type} block up`}>
            <ArrowUp className="h-4 w-4" />
          </Button>
          <Button type="button" size="icon" variant="outline" onClick={() => onMove("down")} disabled={!canMoveDown || busy} aria-label={`Move ${block.type} block down`}>
            <ArrowDown className="h-4 w-4" />
          </Button>
        </div>
      </div>
      <div className="space-y-2">
        <Label htmlFor={contentId}>Content (JSON)</Label>
        <Textarea id={contentId} rows={4} value={content} onChange={(event) => setContent(event.target.value)} />
      </div>
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <Button type="button" size="sm" onClick={save} disabled={busy} aria-label={`Save ${block.type} block`}>Save block</Button>
    </li>
  );
}
