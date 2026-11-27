import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ProductPhotoReviewsSlot } from "@/plugins/product-photo-reviews";

vi.mock("@/services/customer", () => ({
  getCustomerAccessToken: vi.fn(() => ""),
}));

import { getCustomerAccessToken } from "@/services/customer";

const mockedToken = vi.mocked(getCustomerAccessToken);

const GALLERY_PAYLOAD = {
  success: true,
  data: [
    { id: "m-1", review_id: "r-1", product_id: "p-1", url: "/storage/uploads/rev-1.jpg", sort: 0, rating: 5, title: "Great", created_at: "2026-11-26 10:00:00" },
    { id: "m-2", review_id: "r-1", product_id: "p-1", url: "/storage/uploads/rev-2.jpg", sort: 1, rating: 5, title: "Great", created_at: "2026-11-26 10:00:00" },
  ],
};

const EMPTY_PAYLOAD = { success: true, data: [] };

function fetchMockFor(payload: unknown) {
  return vi.fn(async (url: unknown) => {
    if (typeof url === "string" && url.includes("/api/ext/siro/product-photo-reviews/public")) {
      return { ok: true, json: async () => payload } as Response;
    }
    return { ok: false, json: async () => ({}) } as Response;
  });
}

describe("ProductPhotoReviewsSlot", () => {
  beforeEach(() => {
    mockedToken.mockReturnValue("");
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("renders approved photos with alt text and opens a keyboard-navigable lightbox", async () => {
    vi.stubGlobal("fetch", fetchMockFor(GALLERY_PAYLOAD));
    render(<ProductPhotoReviewsSlot data={{ productId: "p-1", productSlug: "test-product", category: "apparel" }} />);

    const first = await screen.findByTestId("photo-review-thumb-0");
    expect(screen.getByTestId("photo-review-gallery")).toBeInTheDocument();
    expect(screen.getByAltText("Photo 1 from a 5-star review: Great")).toBeInTheDocument();
    expect(screen.getByAltText("Photo 2 from a 5-star review: Great")).toBeInTheDocument();

    fireEvent.click(first);
    expect(screen.getByTestId("photo-review-lightbox")).toBeInTheDocument();
    expect(screen.getByText("Photo 1 of 2 — 5 out of 5 stars")).toBeInTheDocument();

    fireEvent.keyDown(window, { key: "ArrowRight" });
    expect(screen.getByText("Photo 2 of 2 — 5 out of 5 stars")).toBeInTheDocument();

    fireEvent.keyDown(window, { key: "ArrowLeft" });
    expect(screen.getByText("Photo 1 of 2 — 5 out of 5 stars")).toBeInTheDocument();

    fireEvent.keyDown(window, { key: "Escape" });
    await waitFor(() => expect(screen.queryByTestId("photo-review-lightbox")).not.toBeInTheDocument());
  });

  it("renders nothing when the product has no photos and nobody is signed in", async () => {
    vi.stubGlobal("fetch", fetchMockFor(EMPTY_PAYLOAD));
    const { container } = render(<ProductPhotoReviewsSlot data={{ productId: "p-1", productSlug: "test-product", category: "apparel" }} />);
    await waitFor(() => expect(screen.queryByText("Loading review photos...")).not.toBeInTheDocument());
    expect(container).toBeEmptyDOMElement();
  });

  it("renders nothing when the gallery endpoint is unavailable and nobody is signed in", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => ({ ok: false, json: async () => ({}) }) as Response));
    const { container } = render(<ProductPhotoReviewsSlot data={{ productId: "p-1", productSlug: "test-product", category: "apparel" }} />);
    await waitFor(() => expect(screen.queryByText("Loading review photos...")).not.toBeInTheDocument());
    expect(container).toBeEmptyDOMElement();
  });

  it("rejects more than five photos and non-image files before any upload", async () => {
    mockedToken.mockReturnValue("tok-1");
    vi.stubGlobal("fetch", fetchMockFor(GALLERY_PAYLOAD));
    render(<ProductPhotoReviewsSlot data={{ productId: "p-1", productSlug: "test-product", category: "apparel" }} />);
    await screen.findByTestId("photo-review-gallery");
    const input = screen.getByLabelText(/Photos \(up to 5\)/) as HTMLInputElement;

    const six = Array.from({ length: 6 }, (_, i) => new File(["x"], `photo-${i}.jpg`, { type: "image/jpeg" }));
    fireEvent.change(input, { target: { files: six } });
    expect(await screen.findByRole("alert")).toHaveTextContent("at most 5 photos");

    const text = [new File(["x"], "notes.txt", { type: "text/plain" })];
    fireEvent.change(input, { target: { files: text } });
    expect(await screen.findByRole("alert")).toHaveTextContent("is not an image file");
  });

  it("uploads photos then submits the review with the returned URLs", async () => {
    mockedToken.mockReturnValue("tok-1");
    const calls: Array<{ url: string; init?: RequestInit }> = [];
    vi.stubGlobal(
      "fetch",
      vi.fn(async (url: unknown, init?: RequestInit) => {
        calls.push({ url: String(url), init });
        if (String(url).includes("/api/ext/siro/product-photo-reviews/public")) {
          return { ok: true, json: async () => GALLERY_PAYLOAD } as Response;
        }
        if (String(url) === "/api/upload") {
          return { ok: true, json: async () => ({ success: true, data: { url: "/storage/uploads/rev-9.jpg" } }) } as Response;
        }
        if (String(url).includes("/api/products/p-1/reviews")) {
          return { ok: true, json: async () => ({ success: true, data: { id: "r-9" } }) } as Response;
        }
        return { ok: false, json: async () => ({}) } as Response;
      }),
    );
    render(<ProductPhotoReviewsSlot data={{ productId: "p-1", productSlug: "test-product", category: "apparel" }} />);
    await screen.findByTestId("photo-review-gallery");

    const input = screen.getByLabelText(/Photos \(up to 5\)/) as HTMLInputElement;
    fireEvent.change(input, { target: { files: [new File(["x"], "pool.jpg", { type: "image/jpeg" })] } });
    await screen.findByTestId("photo-review-staged");

    fireEvent.change(screen.getByLabelText("Order ID"), { target: { value: "ord-1" } });
    fireEvent.change(screen.getByLabelText("Order line ID"), { target: { value: "line-1" } });
    fireEvent.change(screen.getByLabelText("Rating (1–5)"), { target: { value: "5" } });
    fireEvent.change(screen.getByLabelText("Your review"), { target: { value: "Loved it" } });
    fireEvent.click(screen.getByRole("button", { name: "Submit review with photos" }));

    await screen.findByText("Thanks! Your review with photos was submitted and is awaiting moderation.");
    const reviewCall = calls.find((call) => call.url.includes("/api/products/p-1/reviews"));
    expect(reviewCall).toBeDefined();
    const body = JSON.parse(String(reviewCall?.init?.body ?? "{}")) as Record<string, unknown>;
    expect(body.images).toEqual(["/storage/uploads/rev-9.jpg"]);
    expect(body.rating).toBe(5);
    expect(reviewCall?.init?.headers).toMatchObject({ Authorization: "Bearer tok-1" });
  });
});
