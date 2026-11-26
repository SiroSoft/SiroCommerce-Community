import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
const fetchWithAuthMock = vi.hoisted(() => vi.fn());
vi.mock("@/services/api", () => ({ default: apiMock, fetchWithAuth: fetchWithAuthMock }));

import { ProductImportDialog } from "@/modules/products/components/product-import-dialog";

function renderDialog() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <ProductImportDialog open onOpenChange={() => undefined} />
    </QueryClientProvider>,
  );
}

describe("product CSV import boundary", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("rejects an oversized CSV locally without calling the import API", () => {
    renderDialog();
    const oversized = new File([new Uint8Array(2 * 1024 * 1024 + 1)], "huge.csv", { type: "text/csv" });
    fireEvent.change(screen.getByLabelText("Product CSV file"), { target: { files: [oversized] } });

    expect(screen.getByRole("alert")).toHaveTextContent("CSV file must be 2MB or smaller.");
    fireEvent.click(screen.getByRole("button", { name: "Import" }));
    expect(screen.getByRole("alert")).toHaveTextContent("CSV file must be 2MB or smaller.");
    expect(apiMock.post).not.toHaveBeenCalled();
  });
});
