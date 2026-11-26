import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const extensionPanelMock = vi.hoisted(() => vi.fn());
vi.mock("@/services/extensions.service", () => ({ extensionsService: { panel: extensionPanelMock } }));

const cmsRevisionsMock = vi.hoisted(() => vi.fn());
vi.mock("@/services/cms.service", () => ({ cmsService: { revisions: cmsRevisionsMock } }));

const merchantHooks = vi.hoisted(() => ({
  useFulfillmentTrack: vi.fn(),
  useFulfillmentAction: vi.fn(),
  useCancelFulfillment: vi.fn(),
}));
vi.mock("@/hooks/use-merchant", () => merchantHooks);

import { CardSkeleton, StatsSkeleton, TableSkeleton } from "@/components/loading-skeleton";
import { ExtensionPanelCard } from "@/components/extension-panel";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import { CmsRevisionSummary } from "@/modules/posts/components/cms-revision-summary";
import { FulfillmentWorkspace } from "@/modules/merchant/fulfillment-ui";

afterEach(() => {
  cleanup();
});

describe("loading skeletons", () => {
  it("renders the default and custom row counts", () => {
    const { container, unmount } = render(<TableSkeleton />);
    expect(container.querySelectorAll(".animate-shimmer")).toHaveLength(3 + 5 * 4);
    unmount();

    const custom = render(<TableSkeleton rows={2} className="p-2" />);
    expect(custom.container.querySelectorAll(".animate-shimmer")).toHaveLength(3 + 2 * 4);
    expect(custom.container.querySelector(".space-y-3")?.className).toContain("p-2");
    custom.unmount();

    const cards = render(<StatsSkeleton />);
    expect(cards.container.querySelectorAll(".animate-shimmer")).toHaveLength(8);
    cards.unmount();

    const card = render(<CardSkeleton className="m-1" />);
    expect(card.container.querySelector(".rounded-xl")?.className).toContain("m-1");
  });
});

describe("ExtensionPanelCard", () => {
  const panel = { key: "k", title: "Extension metrics", type: "stats" as const, endpoint: "stats", permission: "reports" };

  beforeEach(() => {
    extensionPanelMock.mockReset();
  });

  it("renders stats panels and ignores non-text values", async () => {
    extensionPanelMock.mockResolvedValue({ stats: [{ label: "Orders", value: 12 }, { label: null, value: { nested: true } }] });
    render(<ExtensionPanelCard extensionId="vendor/one" panel={panel} />);
    expect(await screen.findByText("Orders")).toBeTruthy();
    expect(screen.getByText("12")).toBeTruthy();
    expect(extensionPanelMock).toHaveBeenCalledWith("vendor/one", "stats");
  });

  it("renders declared table columns and rows", async () => {
    extensionPanelMock.mockResolvedValue({
      table: { columns: [{ key: "sku", label: "SKU" }], rows: [{ sku: "SKU-1", extra: "ignored" }] },
    });
    render(<ExtensionPanelCard extensionId="ext" panel={{ ...panel, type: "table", title: "Table panel" }} />);
    expect(await screen.findByText("SKU")).toBeTruthy();
    expect(screen.getByText("SKU-1")).toBeTruthy();
  });

  it("derives columns from the first row and shows empty rows", async () => {
    extensionPanelMock.mockResolvedValueOnce({ table: { rows: [{ a: 1, b: "two" }] } });
    const first = render(<ExtensionPanelCard extensionId="ext" panel={{ ...panel, type: "table" }} />);
    expect(await screen.findByText("two")).toBeTruthy();
    first.unmount();

    extensionPanelMock.mockResolvedValueOnce({ table: { rows: [] } });
    render(<ExtensionPanelCard extensionId="ext" panel={{ ...panel, type: "table" }} />);
    expect(await screen.findByText("No rows.")).toBeTruthy();
  });

  it("shows an error state and retries", async () => {
    extensionPanelMock.mockRejectedValueOnce({ status: 403 });
    render(<ExtensionPanelCard extensionId="ext" panel={panel} />);
    expect(await screen.findByText("Access denied")).toBeTruthy();
    extensionPanelMock.mockResolvedValueOnce({ stats: [{ label: "After retry", value: 1 }] });
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    expect(await screen.findByText("After retry")).toBeTruthy();
  });
});

describe("Tooltip", () => {
  it("renders tooltip content when open", async () => {
    render(
      <TooltipProvider>
        <Tooltip open>
          <TooltipTrigger asChild><button type="button">Hover me</button></TooltipTrigger>
          <TooltipContent sideOffset={8} className="custom">Helpful text</TooltipContent>
        </Tooltip>
      </TooltipProvider>,
    );
    expect(screen.getByRole("button", { name: "Hover me" })).toBeTruthy();
    expect(await screen.findByRole("tooltip")).toHaveTextContent("Helpful text");
  });
});

describe("CmsRevisionSummary", () => {
  function renderSummary() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
      <QueryClientProvider client={client}>
        <CmsRevisionSummary pageId="p-1" />
      </QueryClientProvider>,
    );
  }

  it("lists at most five revisions", async () => {
    cmsRevisionsMock.mockReset();
    cmsRevisionsMock.mockResolvedValue(Array.from({ length: 6 }, (_, index) => ({
      id: `r-${index}`, version: index + 1, action: index % 2 ? "update" : "create", created_at: "2026-11-20T10:00:00Z",
    })));
    const { container } = renderSummary();
    expect(await screen.findByLabelText("CMS revision history")).toBeTruthy();
    expect(container.textContent).toContain("v5 · create");
    expect(container.textContent).not.toContain("v6");
    expect(cmsRevisionsMock).toHaveBeenCalledWith("page", "p-1");
  });

  it("renders the error message when revisions fail", async () => {
    cmsRevisionsMock.mockReset();
    cmsRevisionsMock.mockRejectedValue({ status: 500 });
    renderSummary();
    expect(await screen.findByText("Revision history unavailable.")).toBeTruthy();
  });
});

describe("FulfillmentWorkspace", () => {
  const ship = { mutate: vi.fn(), isPending: false, isError: false, error: null };
  const deliver = { mutate: vi.fn(), isPending: false, isError: false, error: null };
  const cancel = { mutate: vi.fn(), isPending: false, isError: false, error: null };
  let track: Record<string, unknown>;

  beforeEach(() => {
    vi.clearAllMocks();
    track = { data: undefined, isLoading: false, isError: false, error: null, isFetching: false, refetch: vi.fn() };
    merchantHooks.useFulfillmentTrack.mockImplementation(() => track);
    merchantHooks.useFulfillmentAction.mockImplementation((action: string) => action === "ship" ? ship : deliver);
    merchantHooks.useCancelFulfillment.mockReturnValue(cancel);
  });

  it("prompts for an ID and refreshes the selected record", () => {
    const { rerender } = render(<FulfillmentWorkspace />);
    expect(screen.getByText("Enter an ID to load fulfillment status.")).toBeTruthy();
    fireEvent.change(screen.getByLabelText("Fulfillment ID"), { target: { value: "f-1" } });

    track = { ...track, data: { id: "f-1", state: "allocated" } };
    rerender(<FulfillmentWorkspace />);
    fireEvent.click(screen.getByRole("button", { name: "Refresh" }));
    expect(track.refetch).toHaveBeenCalled();
    expect(screen.getByText(/allocated/)).toBeTruthy();

    fireEvent.click(screen.getByRole("button", { name: "Ship" }));
    fireEvent.click(screen.getByRole("button", { name: "Deliver" }));
    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    expect(ship.mutate).toHaveBeenCalledWith({ id: "f-1" });
    expect(deliver.mutate).toHaveBeenCalledWith({ id: "f-1" });
    expect(cancel.mutate).toHaveBeenCalledWith({ id: "f-1" });
  });

  it("shows loading, error and retry for the tracked record", () => {
    track = { ...track, isLoading: true };
    const loading = render(<FulfillmentWorkspace />);
    expect(screen.getByRole("status")).toHaveTextContent("Loading fulfillment...");
    loading.unmount();

    const refetch = vi.fn();
    track = { ...track, isLoading: false, isError: true, error: { status: 500 }, refetch };
    render(<FulfillmentWorkspace />);
    fireEvent.change(screen.getByLabelText("Fulfillment ID"), { target: { value: "f-2" } });
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    expect(refetch).toHaveBeenCalled();
  });

  it("disables actions while a mutation is pending", () => {
    track = { ...track, data: { id: "f-1" } };
    ship.isPending = true;
    const { rerender } = render(<FulfillmentWorkspace />);
    rerender(<FulfillmentWorkspace />);
    expect(screen.getByRole("button", { name: "Ship" })).toBeDisabled();
    ship.isPending = false;
  });
});
