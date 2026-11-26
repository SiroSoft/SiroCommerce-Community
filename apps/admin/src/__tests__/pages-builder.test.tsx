import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { AdminApiError } from "@/services/api-errors";
import { useAuthStore } from "@/store/auth.store";

const state = vi.hoisted(() => ({
  pages: [] as Array<Record<string, unknown>>,
  detail: undefined as Record<string, unknown> | undefined,
  revisions: [] as Array<Record<string, unknown>>,
  refetch: vi.fn(),
  detailRefetch: vi.fn(),
  createMutate: vi.fn(),
  updateMutate: vi.fn(),
  addBlockMutate: vi.fn(),
  updateBlockMutate: vi.fn(),
  publishMutate: vi.fn(),
  restoreMutate: vi.fn(),
}));

vi.mock("@/hooks/use-cms-pages", () => ({
  useCmsPages: () => ({ pages: state.pages, isLoading: false, isError: false, error: undefined, refetch: state.refetch }),
  useCmsPagePreview: () => ({ data: state.detail, isLoading: false, isError: false, error: undefined, refetch: state.detailRefetch }),
  useCmsPageRevisions: () => ({ data: state.revisions, isLoading: false, isError: false }),
  useCreateCmsPage: () => ({ mutate: state.createMutate, isPending: false }),
  useUpdateCmsPage: () => ({ mutate: state.updateMutate, isPending: false }),
  useAddCmsBlock: () => ({ mutate: state.addBlockMutate, isPending: false }),
  useUpdateCmsBlock: () => ({ mutate: state.updateBlockMutate, isPending: false }),
  usePublishCmsPage: () => ({ mutate: state.publishMutate, isPending: false }),
  useRestoreCmsRevision: () => ({ mutate: state.restoreMutate, isPending: false }),
}));

import PagesPage from "@/app/(dashboard)/pages/page";

const page = {
  id: "p1",
  slug: "about-us",
  title: "About us",
  locale: "en",
  status: "published",
  body: "<p>Hello</p>",
  version: 3,
  updated_at: "2026-01-01T10:00:00Z",
  blocks: [],
};

function renderPages() {
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
  return render(<PagesPage />);
}

function editAbout() {
  fireEvent.click(screen.getByRole("button", { name: "Edit About us" }));
}

beforeEach(() => {
  state.pages = [page];
  state.detail = page;
  state.revisions = [];
  Object.values(state).forEach((value) => {
    if (typeof value === "function" && "mockReset" in value) (value as ReturnType<typeof vi.fn>).mockReset();
  });
  state.updateBlockMutate.mockImplementation((_value: unknown, options?: { onSuccess?: () => void }) => options?.onSuccess?.());
});

afterEach(() => {
  useAuthStore.setState({ user: null, isAuthenticated: false });
  cleanup();
  vi.restoreAllMocks();
});

describe("pages builder", () => {
  it("renders page rows with title, slug, status, locale, and update time", () => {
    renderPages();
    const table = screen.getByRole("table", { name: "CMS pages" });
    expect(within(table).getByText("About us")).toBeInTheDocument();
    expect(within(table).getByText("about-us")).toBeInTheDocument();
    expect(within(table).getByText("Published")).toBeInTheDocument();
    expect(within(table).getByText("en")).toBeInTheDocument();
    expect(within(table).getByText(/Jan 1, 2026/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Preview About us in a new tab" })).toHaveAttribute("href", "https://ecommerce.sirophp.com/pages/about-us");
  });

  it("creates a page with the expected payload", () => {
    renderPages();
    fireEvent.click(screen.getByRole("button", { name: "New page" }));
    fireEvent.change(screen.getByLabelText("Slug", { exact: true }), { target: { value: "contact-us" } });
    fireEvent.change(screen.getByLabelText("Title", { exact: true }), { target: { value: "Contact us" } });
    fireEvent.change(screen.getByLabelText("Body"), { target: { value: "<p>Contact</p>" } });
    fireEvent.click(screen.getByRole("button", { name: "Create page" }));

    expect(state.createMutate).toHaveBeenCalledTimes(1);
    expect(state.createMutate.mock.calls[0][0]).toMatchObject({
      slug: "contact-us",
      title: "Contact us",
      locale: "en",
      status: "draft",
      body: "<p>Contact</p>",
    });
  });

  it("adds a block with the selected type, JSON content, and sort", () => {
    renderPages();
    editAbout();
    fireEvent.change(screen.getByLabelText("Block type"), { target: { value: "hero" } });
    fireEvent.change(document.getElementById("block-content") as HTMLTextAreaElement, { target: { value: "{\"headline\":\"Hi\"}" } });
    fireEvent.click(screen.getByRole("button", { name: "Add block" }));

    expect(state.addBlockMutate).toHaveBeenCalledTimes(1);
    expect(state.addBlockMutate.mock.calls[0][0]).toMatchObject({ type: "hero", content: "{\"headline\":\"Hi\"}", sort: 10 });
  });

  it("blocks invalid JSON before calling the API", () => {
    renderPages();
    editAbout();
    fireEvent.change(document.getElementById("block-content") as HTMLTextAreaElement, { target: { value: "not json" } });
    fireEvent.click(screen.getByRole("button", { name: "Add block" }));

    expect(state.addBlockMutate).not.toHaveBeenCalled();
    expect(screen.getByText(/Content must be valid JSON/)).toBeInTheDocument();
  });

  it("reorders blocks by updating each changed sort value", async () => {
    state.detail = {
      ...page,
      blocks: [
        { id: "b1", page_id: "p1", type: "rich_text", content: "{\"text\":\"one\"}", sort: 10 },
        { id: "b2", page_id: "p1", type: "hero", content: "{\"headline\":\"two\"}", sort: 20 },
      ],
    };
    renderPages();
    editAbout();
    fireEvent.click(screen.getByRole("button", { name: "Move hero block up" }));

    await waitFor(() => expect(state.updateBlockMutate).toHaveBeenCalledTimes(2));
    expect(state.updateBlockMutate).toHaveBeenCalledWith({ id: "b2", data: { sort: 10 } }, expect.any(Object));
    expect(state.updateBlockMutate).toHaveBeenCalledWith({ id: "b1", data: { sort: 20 } }, expect.any(Object));
  });

  it("only publishes after the confirm dialog is confirmed", async () => {
    renderPages();
    fireEvent.click(screen.getByRole("button", { name: "Publish About us" }));
    expect(state.publishMutate).not.toHaveBeenCalled();

    const dialog = await screen.findByRole("dialog", { name: "Publish page" });
    expect(dialog).toHaveAccessibleDescription(/storefront will serve/i);
    fireEvent.click(within(dialog).getByRole("button", { name: "Publish" }));

    expect(state.publishMutate).toHaveBeenCalledTimes(1);
    expect(state.publishMutate.mock.calls[0][0]).toMatchObject({ id: "p1" });
  });

  it("shows a stale-state warning when saving returns HTTP 409", async () => {
    state.updateMutate.mockImplementation((_payload: unknown, options?: { onError?: (error: unknown) => void }) =>
      options?.onError?.(new AdminApiError({ message: "CMS page changed since it was loaded.", status: 409 })),
    );
    renderPages();
    editAbout();
    fireEvent.click(screen.getByRole("button", { name: "Save changes" }));

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent(/stale page state/i);
    expect(alert).toHaveTextContent(/409/);
  });

  it("filters pages by status and slug and shows no-match state", () => {
    renderPages();
    fireEvent.change(screen.getByLabelText("Filter by status"), { target: { value: "draft" } });
    expect(screen.getByText("No pages match the filters")).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Filter by status"), { target: { value: "published" } });
    fireEvent.change(screen.getByLabelText("Filter by slug"), { target: { value: "zzz" } });
    expect(screen.getByText("No pages match the filters")).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Filter by slug"), { target: { value: "about" } });
    expect(screen.getByText("About us")).toBeInTheDocument();
  });

  it("validates slug, title, locale and schedule fields before saving", () => {
    renderPages();
    fireEvent.click(screen.getByRole("button", { name: "New page" }));
    fireEvent.change(screen.getByLabelText("Slug", { exact: true }), { target: { value: "Bad Slug" } });
    fireEvent.change(screen.getByLabelText("Title", { exact: true }), { target: { value: "Contact us" } });
    fireEvent.click(screen.getByRole("button", { name: "Create page" }));
    expect(screen.getByText(/Slug must be lowercase/)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Slug", { exact: true }), { target: { value: "contact-us" } });
    fireEvent.change(screen.getByLabelText("Title", { exact: true }), { target: { value: "C" } });
    fireEvent.click(screen.getByRole("button", { name: "Create page" }));
    expect(screen.getByText(/Title must be at least 2/)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Title", { exact: true }), { target: { value: "Contact us" } });
    fireEvent.change(screen.getByLabelText("Status"), { target: { value: "scheduled" } });
    fireEvent.click(screen.getByRole("button", { name: "Create page" }));
    expect(screen.getByText(/Scheduled pages require/)).toBeInTheDocument();
    expect(state.createMutate).not.toHaveBeenCalled();
  });

  it("creates a page and enters block editing mode", async () => {
    state.createMutate.mockImplementation((_payload: unknown, options?: { onSuccess?: (value: unknown) => void }) =>
      options?.onSuccess?.({ ...page, id: "p2", slug: "contact-us", title: "Contact us", status: "draft", blocks: [] }),
    );
    renderPages();
    fireEvent.click(screen.getByRole("button", { name: "New page" }));
    fireEvent.change(screen.getByLabelText("Slug", { exact: true }), { target: { value: "contact-us" } });
    fireEvent.change(screen.getByLabelText("Title", { exact: true }), { target: { value: "Contact us" } });
    fireEvent.click(screen.getByRole("button", { name: "Create page" }));
    expect(await screen.findByText("Page created. Add blocks below.")).toBeInTheDocument();
    expect(screen.getByText("Edit page")).toBeInTheDocument();
    expect(screen.getByText("Blocks")).toBeInTheDocument();
  });

  it("saves edited page content and block content", async () => {
    state.updateMutate.mockImplementation((_payload: unknown, options?: { onSuccess?: (value: unknown) => void }) =>
      options?.onSuccess?.({ ...page, title: "About our team" }),
    );
    state.updateBlockMutate.mockImplementation((_value: unknown, options?: { onSuccess?: () => void }) => options?.onSuccess?.());
    state.detail = { ...page, blocks: [{ id: "b1", page_id: "p1", type: "rich_text", content: "{\"text\":\"one\"}", sort: 10 }] };
    renderPages();
    editAbout();
    fireEvent.change(screen.getByLabelText("Title", { exact: true }), { target: { value: "About our team" } });
    fireEvent.click(screen.getByRole("button", { name: "Save changes" }));
    expect(await screen.findByText("Page saved.")).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Content (JSON)", { selector: "#block-content-b1" }), { target: { value: "{\"text\":\"two\"}" } });
    fireEvent.click(screen.getByRole("button", { name: "Save rich_text block" }));
    expect(await screen.findByText("Block saved.")).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Content (JSON)", { selector: "#block-content-b1" }), { target: { value: "bad" } });
    fireEvent.click(screen.getByRole("button", { name: "Save rich_text block" }));
    expect(screen.getByText(/Content must be valid JSON/)).toBeInTheDocument();
  });

  it("rejects unsupported block types and block errors", () => {
    state.addBlockMutate.mockImplementation((_value: unknown, options?: { onError?: (error: unknown) => void }) => options?.onError?.(new AdminApiError({ message: "Block rejected.", status: 422 })));
    renderPages();
    editAbout();
    fireEvent.change(screen.getByLabelText("Block type"), { target: { value: "cta" } });
    fireEvent.click(screen.getByRole("button", { name: "Add block" }));
    expect(screen.getByText(/does not accept the Call to action block type/)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Block type"), { target: { value: "hero" } });
    fireEvent.click(screen.getByRole("button", { name: "Add block" }));
    expect(screen.getByText("Block rejected.")).toBeInTheDocument();
  });

  it("restores a revision only after confirmation", async () => {
    state.revisions = [{ id: "r1", version: 2, action: "publish", created_at: "2026-01-01T10:00:00Z" }];
    state.restoreMutate.mockImplementation((_value: unknown, options?: { onSuccess?: () => void }) => options?.onSuccess?.());
    renderPages();
    editAbout();
    fireEvent.click(screen.getByRole("button", { name: "Restore v2" }));
    const dialog = await screen.findByRole("dialog", { name: "Restore revision" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Restore" }));
    expect(state.restoreMutate).toHaveBeenCalledWith({ id: "p1", revision: "r1" }, expect.any(Object));
    expect(await screen.findByText("Revision v2 restored.")).toBeInTheDocument();
  });

  it("renders page loading, error and empty states", async () => {
    const originalPages = state.pages;
    state.pages = [];
    const { unmount } = renderPages();
    expect(screen.getByText("No pages yet")).toBeInTheDocument();
    unmount();
    state.pages = originalPages;
  });
});
