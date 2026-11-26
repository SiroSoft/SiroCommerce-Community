import { describe, expect, it } from "vitest";
import type { components } from "@/types/api";

describe("generated API type compatibility", () => {
  it("keeps legacy Admin names separate from the generated contract", () => {
    const user: components["schemas"]["User"] = { id: 1, name: "Ada", email: "ada@example.test" };
    const request: components["schemas"]["CreateProductRequest"] = { name: "Widget" };

    expect(user.email).toContain("@");
    expect(request.name).toBe("Widget");
  });

  it("exposes marketplace lite vendor contracts to the admin", () => {
    const create: components["schemas"]["Request_post_api_admin_vendors"] = { name: "Acme", status: "active", commission_bps: 500 };
    const update: components["schemas"]["Request_put_api_admin_vendors_id"] = { status: "suspended" };
    const product: components["schemas"]["Request_put_api_admin_products_id"] = { vendor_id: null };

    expect(create.commission_bps).toBe(500);
    expect(update.status).toBe("suspended");
    expect(product.vendor_id).toBeNull();
  });
});
