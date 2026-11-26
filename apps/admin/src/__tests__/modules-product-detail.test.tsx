import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const hookMocks = vi.hoisted(() => ({ useProduct: vi.fn(), useUpdateProduct: vi.fn() }));
const pushMock = vi.hoisted(() => vi.fn());
vi.mock("@/hooks/use-products", () => hookMocks);
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: pushMock }), usePathname: () => "/products/1" }));
vi.mock("@/modules/products/components/product-form", () => ({
  ProductForm: ({ onDirtyChange, onSubmit }: { onDirtyChange: (dirty: boolean) => void; onSubmit: (data: unknown) => void }) => (
    <div>
      <button type="button" onClick={() => onDirtyChange(true)}>Mark dirty</button>
      <button type="button" onClick={() => onSubmit({ name: "Updated" })}>Submit product</button>
    </div>
  ),
}));
vi.mock("@/modules/products/components/product-options-editor", () => ({ ProductOptionsEditor: () => <div>Options child</div> }));
vi.mock("@/modules/products/components/product-variants-manager", () => ({ ProductVariantsManager: () => <div>Variants child</div> }));
vi.mock("@/modules/products/components/product-media-manager", () => ({ ProductMediaManager: () => <div>Media child</div> }));
vi.mock("@/modules/products/components/booking-slots-manager", () => ({ BookingSlotsManager: () => <div>Booking child</div> }));

import { ProductDetail } from "@/modules/products/product-detail";
import { I18nProvider } from "@/providers/i18n-provider";

function renderDetail() {
  return render(
    <I18nProvider>
      <ProductDetail id={1} />
    </I18nProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  hookMocks.useUpdateProduct.mockReturnValue({
    mutate: vi.fn(),
    isPending: false,
    isError: false,
    isSuccess: false,
    error: null,
  });
});

afterEach(() => {
  cleanup();
});

describe("ProductDetail", () => {
  it("renders the loading state", () => {
    hookMocks.useProduct.mockReturnValue({ isLoading: true });
    renderDetail();
    expect(screen.getByRole("status")).toHaveTextContent("Loading product...");
  });

  it("renders an error state and retries", () => {
    const refetch = vi.fn();
    hookMocks.useProduct.mockReturnValue({ isLoading: false, isError: true, error: { status: 500 }, refetch });
    renderDetail();
    expect(screen.getByText("Service unavailable")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    expect(refetch).toHaveBeenCalled();
  });

  it("renders a not-found state when the product payload is missing", () => {
    hookMocks.useProduct.mockReturnValue({ isLoading: false, isError: false, data: null });
    renderDetail();
    expect(screen.getByText("Something went wrong")).toBeTruthy();
  });

  it("renders the product and all child managers", () => {
    hookMocks.useProduct.mockReturnValue({ isLoading: false, isError: false, data: { id: 1, name: "Linen shirt" } });
    renderDetail();
    expect(screen.getByText("Linen shirt")).toBeTruthy();
    expect(screen.getByText("Options child")).toBeTruthy();
    expect(screen.getByText("Variants child")).toBeTruthy();
    expect(screen.getByText("Media child")).toBeTruthy();
    expect(screen.getByText("Booking child")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Submit product" }));
    expect(hookMocks.useUpdateProduct).toHaveBeenCalledWith(1);
  });

  it("confirms navigation when the form is dirty", async () => {
    const mutate = vi.fn();
    hookMocks.useUpdateProduct.mockReturnValue({
      mutate, isPending: false, isError: false, isSuccess: false, error: null,
    });
    hookMocks.useProduct.mockReturnValue({ isLoading: false, isError: false, data: { id: 1, name: "Linen shirt" } });
    renderDetail();
    fireEvent.click(screen.getByRole("button", { name: "Mark dirty" }));
    fireEvent.click(screen.getByRole("link", { name: /Back to products/ }));
    const dialog = await screen.findByRole("dialog", { name: "Leave product?" });
    expect(dialog).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/products"));
  });

  it("renders success and mutation error banners", () => {
    hookMocks.useProduct.mockReturnValue({ isLoading: false, isError: false, data: { id: 1, name: "Linen shirt" } });
    hookMocks.useUpdateProduct.mockReturnValue({
      mutate: vi.fn(), isPending: false, isError: true, isSuccess: false, error: { status: 422 },
    });
    const { unmount } = renderDetail();
    expect(screen.getByText("Validation failed")).toBeTruthy();
    unmount();

    hookMocks.useUpdateProduct.mockReturnValue({
      mutate: vi.fn(), isPending: false, isError: false, isSuccess: true, error: null,
    });
    renderDetail();
    expect(screen.getByText("Product saved successfully.")).toBeTruthy();
  });
});
