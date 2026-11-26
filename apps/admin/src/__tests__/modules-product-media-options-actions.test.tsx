import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const fe1Mock = vi.hoisted(() => ({
  media: vi.fn(),
  addMedia: vi.fn(),
  reorderMedia: vi.fn(),
  deleteMedia: vi.fn(),
  options: vi.fn(),
  createOption: vi.fn(),
  addOptionValue: vi.fn(),
}));
vi.mock("@/services/fe1.service", () => ({ fe1Service: fe1Mock }));

import { ProductMediaManager } from "@/modules/products/components/product-media-manager";
import { ProductOptionsEditor } from "@/modules/products/components/product-options-editor";

const image = {
  id: "m-1",
  url: "/storage/front.jpg",
  alt: "Front",
  type: "image",
  variants: [
    { w: 320, url: "/storage/front_320.webp", format: "webp" },
    { w: 640, url: "/storage/front_640.webp", format: "webp" },
  ],
};
const video = { id: "m-2", url: "https://cdn.test/clip.mp4", type: "video", alt: "Clip" };
const youtube = { id: "m-3", url: "https://youtube.test/watch", type: "youtube", title: "Promo" };

beforeEach(() => {
  vi.clearAllMocks();
  fe1Mock.media.mockResolvedValue([image, video, youtube]);
  fe1Mock.addMedia.mockResolvedValue({ id: "m-4" });
  fe1Mock.reorderMedia.mockResolvedValue(undefined);
  fe1Mock.deleteMedia.mockResolvedValue(undefined);
  fe1Mock.options.mockResolvedValue([{ id: "o-1", name: "Color" }, { id: "o-2", name: "Size" }]);
  fe1Mock.createOption.mockResolvedValue({ id: "o-3" });
  fe1Mock.addOptionValue.mockResolvedValue({ id: "v-1" });
});

afterEach(() => {
  cleanup();
});

describe("ProductMediaManager interactions", () => {
  it("renders previews for images, videos and YouTube assets", async () => {
    render(<ProductMediaManager productId={1} />);
    expect(await screen.findByText("Front")).toBeTruthy();
    expect(screen.getByText("Clip")).toBeTruthy();
    expect(screen.getByText("Promo")).toBeTruthy();
    const img = screen.getByRole("img") as HTMLImageElement;
    expect(img.getAttribute("src")).toBe("/storage/front_320.webp");
    expect(screen.getByText(/2 derivatives/)).toBeTruthy();
    expect(screen.getAllByRole("button", { name: "Move up" })[0]).toBeDisabled();
  });

  it("reorders media and persists the new order", async () => {
    render(<ProductMediaManager productId={1} />);
    await screen.findByText("Front");
    fireEvent.click(screen.getAllByRole("button", { name: "Move down" })[0]);
    await waitFor(() => expect(fe1Mock.reorderMedia).toHaveBeenCalledWith(1, ["m-2", "m-1", "m-3"]));
  });

  it("adds an asset with alt text and type", async () => {
    render(<ProductMediaManager productId={1} />);
    await screen.findByText("Front");
    fireEvent.change(screen.getByLabelText("Media URL"), { target: { value: "  https://cdn.test/new.jpg  " } });
    fireEvent.change(screen.getByLabelText("Media alt text"), { target: { value: "  New  " } });
    fireEvent.change(screen.getByLabelText("Media type"), { target: { value: "video" } });
    fireEvent.click(screen.getByRole("button", { name: "Add media" }));
    await waitFor(() => expect(fe1Mock.addMedia).toHaveBeenCalledWith(1, {
      url: "https://cdn.test/new.jpg",
      type: "video",
      alt: "New",
    }));
  });

  it("deletes media only after confirmation", async () => {
    render(<ProductMediaManager productId={1} />);
    await screen.findByText("Front");
    fireEvent.click(screen.getByRole("button", { name: "Delete Front" }));
    expect(await screen.findByRole("dialog", { name: "Delete media" })).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Delete" }));
    await waitFor(() => expect(fe1Mock.deleteMedia).toHaveBeenCalledWith(1, "m-1"));
  });

  it("renders an empty gallery and surfaces load errors with retry", async () => {
    fe1Mock.media.mockResolvedValueOnce([]);
    const { unmount } = render(<ProductMediaManager productId={1} />);
    expect(await screen.findByText(/No media yet/)).toBeTruthy();
    unmount();

    fe1Mock.media.mockRejectedValueOnce({ status: 403 });
    render(<ProductMediaManager productId={1} />);
    expect(await screen.findByText("Access denied")).toBeTruthy();
    fe1Mock.media.mockResolvedValueOnce([]);
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    expect(await screen.findByText(/No media yet/)).toBeTruthy();
  });
});

describe("ProductOptionsEditor interactions", () => {
  it("loads options, selects one and adds an option and value", async () => {
    render(<ProductOptionsEditor productId={7} />);
    expect(await screen.findByRole("button", { name: "Color" })).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Size" }));

    fireEvent.change(screen.getByLabelText("New option name"), { target: { value: " Material " } });
    fireEvent.click(screen.getByRole("button", { name: "Add option" }));
    await waitFor(() => expect(fe1Mock.createOption).toHaveBeenCalledWith(7, { name: "Material" }));

    fireEvent.change(screen.getByLabelText("New option name"), { target: { value: "" } });
    fireEvent.change(screen.getByLabelText("New option value"), { target: { value: " XL " } });
    fireEvent.click(screen.getByRole("button", { name: "Add value" }));
    await waitFor(() => expect(fe1Mock.addOptionValue).toHaveBeenCalledWith(7, "o-2", { value: "XL" }));
  });

  it("renders the empty state when no options exist", async () => {
    fe1Mock.options.mockResolvedValueOnce([]);
    render(<ProductOptionsEditor productId={7} />);
    expect(await screen.findByText("No options yet.")).toBeTruthy();
  });
});
