import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import { ProductForm } from "@/modules/products/components/product-form";
import type { components } from "@/types/api";

vi.mock("@/hooks/use-categories", () => ({ useCategories: () => ({ categories: [] }) }));
vi.mock("@/hooks/use-vendors", () => ({ useVendors: () => ({ vendors: [] }) }));
vi.mock("@/hooks/use-subscription-plans", () => ({
  useSubscriptionPlans: () => ({
    plans: [
      { id: "plan-1", name: "Monthly care", amount_minor: 1200, currency: "USD", interval: "monthly", interval_count: 1, trial_period: null, trial_period_count: 0, state: "active", provider: null },
      { id: "plan-2", name: "Weekly care", amount_minor: 400, currency: "USD", interval: "weekly", interval_count: 1, trial_period: null, trial_period_count: 0, state: "active", provider: null },
    ],
    isLoading: false,
  }),
}));

const product = { id: 1, name: "Widget", sku: "W-1", price: 10, compare_price: null, stock: 4, is_active: true, is_featured: false, subscription_plan_ids: ["plan-1"] } as components["schemas"]["Product"];

describe("product subscription plan multi-select", () => {
  afterEach(() => cleanup());

  it("pre-selects linked plans and submits the replace-all selection", async () => {
    const onSubmit = vi.fn();
    render(<I18nProvider><ProductForm product={product} onSubmit={onSubmit} isPending={false} /></I18nProvider>);
    const monthly = screen.getByLabelText("Link plan Monthly care") as HTMLInputElement;
    expect(monthly.checked).toBe(true);
    fireEvent.click(screen.getByLabelText("Link plan Weekly care"));
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: "Widget" } });
    fireEvent.change(screen.getByLabelText(/^SKU/), { target: { value: "W-1" } });
    fireEvent.change(screen.getByLabelText(/^Price/), { target: { value: "10" } });
    fireEvent.change(screen.getByLabelText(/^Compare-at price/), { target: { value: "10" } });
    fireEvent.click(screen.getByRole("button", { name: /save/i }));
    await waitFor(() => expect(onSubmit).toHaveBeenCalled());
    expect(onSubmit.mock.calls[0][0]).toEqual(expect.objectContaining({ subscription_plan_ids: ["plan-1", "plan-2"] }));
  });
});
