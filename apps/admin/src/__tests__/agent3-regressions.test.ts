import { describe, expect, it } from "vitest";
import { canTransitionOrder, dedupeIds, inventoryAvailable } from "@/modules/merchant/contracts";
import { usersToCsv } from "@/modules/users/csv";

describe("Agent 3 operational boundaries", () => {
  it("accepts only the named order transition matrix", () => {
    expect(canTransitionOrder("pending", "confirmed")).toBe(true);
    expect(canTransitionOrder("pending", "shipped")).toBe(false);
    expect(canTransitionOrder("delivered", "cancelled")).toBe(false);
  });

  it("deduplicates IDs before bulk work", () => {
    expect(dedupeIds([4, 4, null, 0, 2, 2])).toEqual([4, 0, 2]);
  });

  it("fails closed when inventory ATP is inconsistent", () => {
    expect(inventoryAvailable(10, 3, 7)).toBe(7);
    expect(inventoryAvailable(10, 3, 8)).toBeNull();
    expect(inventoryAvailable(2, 3, 0)).toBeNull();
  });

  it("exports empty user data as a header-only CSV", () => {
    expect(usersToCsv([])).toBe("id,name,email,role,status,created_at");
  });
});
