import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
const fetchWithAuthMock = vi.hoisted(() => vi.fn());
vi.mock("@/services/api", () => ({ default: apiMock, fetchWithAuth: fetchWithAuthMock }));

import { ProductImportDialog } from "@/modules/products/components/product-import-dialog";
import { productsService } from "@/services/products.service";

function renderDialog() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><ProductImportDialog open onOpenChange={() => undefined} /></QueryClientProvider>);
}

describe("product CSV transfer", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("uploads a file with an idempotency key and renders the dry-run summary", async () => {
    apiMock.post.mockResolvedValue({
      data: {
        data: { created: 1, updated: 0, skipped: 0, errors: [], dry_run: true, rows_seen: 1 },
      },
    });
    renderDialog();
    const input = screen.getByLabelText("Product CSV file");
    const file = new File(["sku,name\nSKU-1,One"], "products.csv", { type: "text/csv" });
    fireEvent.change(input, { target: { files: [file] } });
    fireEvent.click(screen.getByRole("button", { name: "Dry run" }));

    await waitFor(() => expect(apiMock.post).toHaveBeenCalledTimes(1));
    const [path, body, options] = apiMock.post.mock.calls[0] as [string, FormData, { idempotencyKey: string; params: { dry_run: number } }];
    expect(path).toBe("/api/admin/products/import");
    expect(body).toBeInstanceOf(FormData);
    expect((body as FormData).get("file")).toBe(file);
    expect(options.params).toEqual({ dry_run: 1 });
    expect(options.idempotencyKey).toEqual(expect.any(String));
    expect(await screen.findByText("Dry run result")).toBeVisible();
    expect(await screen.findByText("1 created")).toBeVisible();
  });

  it("renders row errors without aborting the summary", async () => {
    apiMock.post.mockResolvedValue({
      data: {
        data: { created: 0, updated: 1, skipped: 0, errors: [{ row: 3, error: "sku is required" }], dry_run: false, rows_seen: 2 },
      },
    });
    renderDialog();
    const file = new File(["sku,name\nSKU-1,One\n,Missing"], "products.csv", { type: "text/csv" });
    fireEvent.change(screen.getByLabelText("Product CSV file"), { target: { files: [file] } });
    fireEvent.click(screen.getByRole("button", { name: "Import" }));

    expect(await screen.findByText("Import result")).toBeVisible();
    expect(await screen.findByText("1 updated")).toBeVisible();
    expect(await screen.findByText("sku is required")).toBeVisible();
    expect(await screen.findByText("3")).toBeVisible();
  });

  it("downloads CSV with export metadata from response headers", async () => {
    fetchWithAuthMock.mockResolvedValue(new Response("sku,name\nSKU-1,One", {
      status: 200,
      headers: {
        "Content-Disposition": 'attachment; filename="products-20260101.csv"',
        "X-Export-Rows": "1",
        "X-Export-Total": "12",
        "X-Export-Truncated": "1",
      },
    }));

    const result = await productsService.exportCsv();

    expect(fetchWithAuthMock).toHaveBeenCalledWith("/api/admin/products/export.csv", expect.objectContaining({ method: "GET" }));
    expect(result.filename).toBe("products-20260101.csv");
    expect(result.rows).toBe(1);
    expect(result.total).toBe(12);
    expect(result.truncated).toBe(true);
  });
});
