import { describe, expect, it } from "vitest";
import { mapProductFromApi } from "@/lib/api-mapping";
import { inventoryAvailable } from "@/modules/merchant/contracts";

describe("merchant catalog evidence boundaries", () => {
  it("preserves API image and derives status without inventing price data", () => {
    const product = mapProductFromApi({ id: "p-1", name: "Tea", image: "/tea.jpg", price: 1250, currency: "VND", status: "draft" });
    expect(product).toMatchObject({ image: "/tea.jpg", price: 1250, currency: "VND", is_active: false });
  });

  it("rejects contradictory price evidence and stock availability", () => {
    expect(mapProductFromApi({ id: "p-2", name: "Tea", price: 100, compare_price: 50 })).toBeNull();
    expect(inventoryAvailable(4, 5, 0)).toBeNull();
    expect(inventoryAvailable(4, 1, 3)).toBe(3);
  });
});
