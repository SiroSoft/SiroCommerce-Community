import "@testing-library/jest-dom/vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import OrderPrintPage from "@/app/(dashboard)/orders/print/page";
import { ordersService } from "@/services/orders.service";

vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams("type=packing&ids=7,8"),
  useRouter: () => ({ push: vi.fn() }),
  usePathname: () => "/orders/print",
}));
vi.mock("@/services/orders.service", () => ({
  ordersService: { get: vi.fn(async (id: number) => ({ id, order_number: `SO-${id}`, customer_email: "buyer@example.com", status: "processing", total: 120, created_at: "2026-01-01T00:00:00Z", items: [{ id: "l1", product_name: "Linen shirt", quantity: 2, subtotal: 120 }] })) },
}));

describe("bulk print", () => {
  it("renders one packing slip per order with pack checkboxes", async () => {
    render(<OrderPrintPage />);
    expect(await screen.findByLabelText("Packing slip Order #SO-7")).toBeVisible();
    expect(screen.getByLabelText("Packing slip Order #SO-8")).toBeVisible();
    expect(ordersService.get).toHaveBeenCalledTimes(2);
    expect(screen.getAllByText("Linen shirt").length).toBeGreaterThan(0);
  });

  it("print stylesheet isolates documents", async () => {
    const { readFileSync } = await import("node:fs");
    const { join } = await import("node:path");
    const css = readFileSync(join(process.cwd(), "src/app/globals.css"), "utf8");
    expect(css).toContain("@media print");
    expect(css).toContain(".print-doc + .print-doc");
    expect(css).toContain("break-before: page");
  });
});
