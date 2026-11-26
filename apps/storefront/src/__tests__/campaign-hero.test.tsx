import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { CampaignHero } from "@/components/merchandising/CampaignHero";
import { products } from "@/services/catalog";

describe("campaign hero", () => {
  it("starts the seeded catalog journey from the campaign product category", () => {
    render(<CampaignHero product={products[0]!} />);

    expect(screen.getByRole("link", { name: "Shop the collection" }).getAttribute("href")).toBe("/category/apparel");
  });

  it("uses only local image assets for the populated hero", () => {
    const { container } = render(<CampaignHero product={products[0]!} />);

    const image = container.querySelector("img");
    expect(image?.getAttribute("src")).toBe("/catalog/b2c/linen-mandarin-shirt-front.svg");
    expect(image?.getAttribute("alt")).toBe("Natural linen shirt illustration");
  });
});
