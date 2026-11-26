import "@testing-library/jest-dom/vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { ProductGallery } from "@/components/ProductGallery";

describe("product gallery — video and youtube", () => {
  it("renders mp4 with a player and keeps images working", () => {
    const { container } = render(<ProductGallery
      frames={[
        { url: "/a.jpg", alt: "A" },
        { url: "https://cdn.example.test/clip.mp4", alt: "Clip", type: "video" },
      ]}
      name="P" label="Gallery" counter="{index}/{total}" />);
    expect(container.querySelector("img")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Gallery 2" }));
    expect(container.querySelector("video")).toBeVisible();
    expect(container.querySelector("video")?.getAttribute("src")).toBe("https://cdn.example.test/clip.mp4");
  });

  it("embeds youtube allowlisted hosts and rejects others", () => {
    const { container, rerender } = render(<ProductGallery
      frames={[{ url: "https://www.youtube.com/watch?v=dQw4w9WgXcQ", alt: "YT", type: "youtube" }]}
      name="P" label="Gallery" counter="{index}/{total}" />);
    const iframe = container.querySelector("iframe");
    expect(iframe).toBeVisible();
    expect(iframe?.getAttribute("src")).toBe("https://www.youtube.com/embed/dQw4w9WgXcQ");
    rerender(<ProductGallery
      frames={[{ url: "https://evil.example.test/x", alt: "X", type: "youtube" }]}
      name="P" label="Gallery" counter="{index}/{total}" />);
    expect(container.querySelector("iframe")).toBeNull();
  });
});
