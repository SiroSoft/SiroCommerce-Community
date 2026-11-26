import { beforeEach, describe, expect, it, vi } from "vitest";
import api from "@/services/api";
import { b2bService } from "@/services/b2b.service";

vi.mock("@/services/api", () => ({ default: { get: vi.fn(), post: vi.fn() } }));

beforeEach(() => vi.clearAllMocks());

describe("B2B API boundary", () => {
  it("uses the generated organization, negotiated price, and vendor routes", async () => {
    vi.mocked(api.post).mockResolvedValue({ data: { data: { id: "company-1" } } } as never);
    vi.mocked(api.get).mockResolvedValue({ data: { data: [{ id: "vendor-1", status: "pending" }] } } as never);

    await b2bService.createCompany({ name: "Acme" });
    await b2bService.setNegotiatedPrice("company-1", { variant_id: "v-1", unit_price_minor: 1200 });
    await b2bService.vendors();

    expect(api.post).toHaveBeenNthCalledWith(1, "/api/admin/b2b/companies", { name: "Acme" }, expect.objectContaining({ idempotencyKey: expect.any(String) }));
    expect(api.post).toHaveBeenNthCalledWith(2, "/api/admin/b2b/companies/company-1/negotiated-prices", { variant_id: "v-1", unit_price_minor: 1200 }, expect.objectContaining({ idempotencyKey: expect.any(String) }));
    expect(api.get).toHaveBeenCalledWith("/api/v2/admin/vendors");
  });

  it("does not create unsupported price-list, commission, or payout requests", () => {
    expect(api.get).not.toHaveBeenCalledWith(expect.stringMatching(/price-list|commission|payout/));
    expect(api.post).not.toHaveBeenCalledWith(expect.stringMatching(/price-list|commission|payout/), expect.anything(), expect.anything());
  });

  it("sends idempotent quote and approval actions", async () => {
    vi.mocked(api.post).mockResolvedValue({ data: { data: { id: "quote-1" } } } as never);
    await b2bService.quoteAction("quote-1", "approve");
    await b2bService.approveOrder("order-1", true);
    expect(api.post).toHaveBeenNthCalledWith(1, "/api/v2/b2b/quotes/quote-1/approve", undefined, expect.objectContaining({ idempotencyKey: expect.any(String) }));
    expect(api.post).toHaveBeenNthCalledWith(2, "/api/v2/b2b/orders/order-1/approve-company", undefined, expect.objectContaining({ idempotencyKey: expect.any(String) }));
  });
});
