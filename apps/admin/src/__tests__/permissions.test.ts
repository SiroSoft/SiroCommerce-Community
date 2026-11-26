import { describe, expect, it } from "vitest";
import { ROLE_CAPABILITIES, canOperateMerchant, canUseRoute, normalizeCapability, serverPermissions } from "@/modules/merchant/permissions";

const roles = ["owner", "admin", "manager", "operator", "viewer"] as const;
const matrix: Record<string, Record<string, boolean>> = {
  orders: { owner: true, admin: true, manager: false, operator: false, viewer: false },
  inventory: { owner: true, admin: true, manager: false, operator: false, viewer: false },
  products: { owner: true, admin: true, manager: false, operator: false, viewer: false },
  reports: { owner: true, admin: true, manager: false, operator: false, viewer: false },
  analytics: { owner: true, admin: true, manager: false, operator: false, viewer: false },
  users: { owner: true, admin: true, manager: false, operator: false, viewer: false },
  cms: { owner: true, admin: true, manager: false, operator: false, viewer: false },
};

describe("backend capability contract", () => {
  it.each(Object.entries(matrix))("evaluates %s for every requested role", (capability, expected) => {
    for (const role of roles) expect(canOperateMerchant({ role }, capability)).toBe(expected[role]);
  });

  it("does not grant access to absent or unknown claims", () => {
    expect(canOperateMerchant(null, "orders")).toBe(false);
    expect(canOperateMerchant({ role: "editor" }, "orders")).toBe(false);
    expect(canOperateMerchant({ role: "admin" }, "orders.transition")).toBe(false);
  });

  it("limits campaign controls to campaign-capable staff", () => {
    expect(canOperateMerchant({ role: "marketer" }, "campaigns")).toBe(true);
    expect(canOperateMerchant({ role: "support" }, "campaigns")).toBe(false);
    expect(canOperateMerchant({ role: "viewer" }, "campaigns")).toBe(false);
  });

  it("keeps explicit backend route role gates separate from capabilities", () => {
    expect(canUseRoute({ role: "owner" }, ["owner", "admin"])).toBe(true);
    expect(canUseRoute({ role: "manager" }, ["owner", "admin"])).toBe(false);
    expect(canUseRoute({ role: "viewer" }, ["owner", "admin"])).toBe(false);
  });
});

describe("server permissions take precedence over the role map", () => {
  it("uses the server permission list when present", () => {
    expect(serverPermissions({ role: "viewer", permissions: ["products"] })).toEqual(["products"]);
    expect(canOperateMerchant({ role: "viewer", permissions: ["products"] }, "products")).toBe(true);
    expect(canOperateMerchant({ role: "viewer", permissions: ["products"] }, "orders")).toBe(false);
  });

  it("does not fall back to the role map when the server returns an empty list", () => {
    expect(canOperateMerchant({ role: "admin", permissions: [] }, "orders")).toBe(false);
  });

  it("honours the wildcard from the server list", () => {
    expect(canOperateMerchant({ role: "viewer", permissions: ["*"] }, "orders")).toBe(true);
    expect(canOperateMerchant({ role: "viewer", permissions: ["*"] }, "made_up_capability")).toBe(false);
  });

  it("ignores unknown server capabilities", () => {
    expect(canOperateMerchant({ role: "owner", permissions: ["settings"] }, "settings")).toBe(false);
  });
});

describe("role fallback matches backend/app/Role.php", () => {
  it("exports the exact backend capability map", () => {
    expect(ROLE_CAPABILITIES).toEqual({
      owner: ["*"],
      admin: ["products", "orders", "inventory", "customers", "coupons", "campaigns", "seo", "cms", "users", "reports", "search", "analytics", "exports", "operations", "subscriptions", "ai_suggestions", "admin_workbench"],
      marketer: ["products", "coupons", "campaigns", "seo", "cms", "analytics", "ai_suggestions"],
      warehouse: ["inventory", "orders", "operations"],
      support: ["orders", "customers", "operations", "subscriptions"],
      finance: ["finance", "invoices", "payments", "refunds", "credit_memos", "exports", "operations", "subscriptions", "ai_suggestions"],
      viewer: [],
    });
  });

  it("mirrors Role::can() read-only finance reporting and search access", () => {
    expect(canOperateMerchant({ role: "finance" }, "reports")).toBe(true);
    expect(canOperateMerchant({ role: "finance" }, "search")).toBe(true);
    expect(canOperateMerchant({ role: "finance" }, "orders")).toBe(false);
  });

  it("does not grant capabilities the backend does not define", () => {
    expect(normalizeCapability("settings")).toBeNull();
    expect(canOperateMerchant({ role: "owner" }, "settings")).toBe(false);
    expect(canOperateMerchant({ role: "admin" }, "settings")).toBe(false);
    expect(canOperateMerchant({ role: "owner" }, "made_up_capability")).toBe(false);
    expect(canOperateMerchant({ role: "admin" }, "vendors")).toBe(true);
    expect(canOperateMerchant({ role: "finance" }, "b2b")).toBe(false);
  });
});
