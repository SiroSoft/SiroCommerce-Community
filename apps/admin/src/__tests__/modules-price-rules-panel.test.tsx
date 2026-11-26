import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const hooks = vi.hoisted(() => ({
  usePromotionRules: vi.fn(),
  useCreatePromotionRule: vi.fn(),
  useUpdatePromotionRule: vi.fn(),
  useDeletePromotionRule: vi.fn(),
  useCategories: vi.fn(),
}));
vi.mock("@/hooks/use-promotion-rules", () => ({
  usePromotionRules: hooks.usePromotionRules,
  useCreatePromotionRule: hooks.useCreatePromotionRule,
  useUpdatePromotionRule: hooks.useUpdatePromotionRule,
  useDeletePromotionRule: hooks.useDeletePromotionRule,
}));
vi.mock("@/hooks/use-categories", () => ({ useCategories: hooks.useCategories }));

import { PriceRulesPanel } from "@/modules/promotions/price-rules-panel";
import { useAuthStore } from "@/store/auth.store";

const rules = [
  {
    id: "r-1", name: "Ten off", kind: "cart_percent", value: 10, min_subtotal_minor: 5000,
    category_slugs: ["skincare"], starts_at: "2026-11-01T00:00:00Z", ends_at: "2026-12-01T00:00:00Z",
    max_uses: 100, uses: 3, state: "active",
  },
  {
    id: "r-2", name: "Five off", kind: "cart_fixed", value: 500, category_slugs: null,
    starts_at: null, ends_at: null, max_uses: null, uses: 0, state: "disabled",
  },
  {
    id: "r-3", name: "Catalog ten", kind: "catalog_percent", value: 10, state: "active",
  },
];

function defaults() {
  hooks.usePromotionRules.mockReturnValue({
    rules, isLoading: false, isError: false, error: null, refetch: vi.fn(),
  });
  hooks.useCategories.mockReturnValue({
    categories: [
      { id: 1, slug: "skincare", name: "Skincare" },
      { id: 2, slug: "hair", name: "Hair" },
      { id: 3, name: "No slug" },
    ],
  });
  return {
    create: vi.fn().mockResolvedValue({}),
    update: vi.fn().mockResolvedValue({}),
    remove: vi.fn(),
  };
}

function wireMutations(actions: ReturnType<typeof defaults>) {
  hooks.useCreatePromotionRule.mockReturnValue({ mutateAsync: actions.create, isPending: false });
  hooks.useUpdatePromotionRule.mockReturnValue({ mutateAsync: actions.update, isPending: false });
  hooks.useDeletePromotionRule.mockReturnValue({ mutate: actions.remove, isPending: false });
}

beforeEach(() => {
  vi.clearAllMocks();
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true, isLoading: false });
  const actions = defaults();
  wireMutations(actions);
});

afterEach(() => {
  cleanup();
});

describe("PriceRulesPanel", () => {
  it("renders rules with labels, windows and usage counters", () => {
    render(<PriceRulesPanel />);
    expect(screen.getByText("Ten off")).toBeTruthy();
    expect(screen.getByText("Min 5000 minor")).toBeTruthy();
    expect(screen.getByText("Cart percent")).toBeTruthy();
    expect(screen.getByText("Cart fixed")).toBeTruthy();
    expect(screen.getByText("Catalog percent")).toBeTruthy();
    expect(screen.getByText("500 minor")).toBeTruthy();
    expect(screen.getByText("3 / 100")).toBeTruthy();
    expect(screen.getByText("disabled")).toBeTruthy();
  });

  it("blocks writes and shows the read-only notice for viewers", () => {
    useAuthStore.setState({ user: { role: "viewer" } as never, isAuthenticated: true, isLoading: false });
    render(<PriceRulesPanel />);
    expect(screen.getByRole("button", { name: /New rule/ })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Edit Ten off" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Delete Ten off" })).toBeDisabled();
    expect(screen.getByRole("alert")).toHaveTextContent("Read-only");
  });

  it("validates the create form before submitting", async () => {
    const actions = defaults();
    wireMutations(actions);
    render(<PriceRulesPanel />);
    fireEvent.click(screen.getByRole("button", { name: /New rule/ }));
    const dialog = await screen.findByRole("dialog");

    fireEvent.click(within(dialog).getByRole("button", { name: "Create rule" }));
    expect(await within(dialog).findByRole("alert")).toHaveTextContent("Name is required.");

    fireEvent.change(within(dialog).getByLabelText("Name *"), { target: { value: "Summer" } });
    fireEvent.change(within(dialog).getByLabelText("Value *"), { target: { value: "95" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Create rule" }));
    expect(await within(dialog).findByRole("alert")).toHaveTextContent("Percent must be an integer from 1 to 90.");

    fireEvent.change(within(dialog).getByLabelText("Value *"), { target: { value: "10" } });
    fireEvent.change(within(dialog).getByLabelText("Starts at"), { target: { value: "2026-12-02T10:00" } });
    fireEvent.change(within(dialog).getByLabelText("Ends at"), { target: { value: "2026-12-01T10:00" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Create rule" }));
    expect(await within(dialog).findByRole("alert")).toHaveTextContent("Start must be before end.");

    fireEvent.change(within(dialog).getByLabelText("Starts at"), { target: { value: "" } });
    fireEvent.change(within(dialog).getByLabelText("Ends at"), { target: { value: "" } });
    fireEvent.change(within(dialog).getByLabelText("Max uses"), { target: { value: "0" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Create rule" }));
    expect(await within(dialog).findByRole("alert")).toHaveTextContent("Max uses must be at least 1.");

    fireEvent.change(within(dialog).getByLabelText("Max uses"), { target: { value: "50" } });
    fireEvent.click(within(dialog).getByLabelText("Hair"));
    fireEvent.click(within(dialog).getByRole("button", { name: "Create rule" }));
    await waitFor(() => expect(actions.create).toHaveBeenCalled());
    const payload = actions.create.mock.calls[0][0];
    expect(payload).toMatchObject({
      name: "Summer", kind: "cart_percent", value: 10, category_slugs: ["hair"],
      starts_at: null, ends_at: null, max_uses: 50, state: "active",
    });
  });

  it("edits an existing rule with its populated form", async () => {
    const actions = defaults();
    wireMutations(actions);
    render(<PriceRulesPanel />);
    fireEvent.click(screen.getByRole("button", { name: "Edit Ten off" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByLabelText("Name *")).toHaveValue("Ten off");
    expect(within(dialog).getByLabelText("Starts at")).toHaveValue("2026-11-01T00:00");
    expect(within(dialog).getByLabelText("Ends at")).toHaveValue("2026-12-01T00:00");
    expect(within(dialog).getByLabelText("Hair")).not.toBeChecked();

    fireEvent.change(within(dialog).getByLabelText("Name *"), { target: { value: "Ten off v2" } });
    fireEvent.change(within(dialog).getByLabelText("Kind"), { target: { value: "cart_fixed" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Save rule" }));
    await waitFor(() => expect(actions.update).toHaveBeenCalled());
    expect(actions.update.mock.calls[0][0]).toMatchObject({ id: "r-1", data: { name: "Ten off v2", kind: "cart_fixed" } });
  });

  it("validates the fixed-value bound", async () => {
    const actions = defaults();
    wireMutations(actions);
    render(<PriceRulesPanel />);
    fireEvent.click(screen.getByRole("button", { name: /New rule/ }));
    const dialog = await screen.findByRole("dialog");
    fireEvent.change(within(dialog).getByLabelText("Name *"), { target: { value: "Big fixed" } });
    fireEvent.change(within(dialog).getByLabelText("Kind"), { target: { value: "cart_fixed" } });
    fireEvent.change(within(dialog).getByLabelText("Value *"), { target: { value: "0" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Create rule" }));
    expect(await within(dialog).findByRole("alert")).toHaveTextContent("Fixed value must be 1..1000000000 minor.");
  });

  it("deletes a rule after confirmation", async () => {
    const actions = defaults();
    wireMutations(actions);
    render(<PriceRulesPanel />);
    fireEvent.click(screen.getByRole("button", { name: "Delete Ten off" }));
    expect(await screen.findByRole("dialog", { name: "Delete price rule?" })).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(actions.remove).toHaveBeenCalledWith("r-1"));
  });

  it("shows empty, loading and error states", () => {
    hooks.usePromotionRules.mockReturnValueOnce({ rules: [], isLoading: false, isError: false, error: null, refetch: vi.fn() });
    const { unmount } = render(<PriceRulesPanel />);
    expect(screen.getByText("No price rules configured.")).toBeTruthy();
    unmount();

    hooks.usePromotionRules.mockReturnValueOnce({ rules: [], isLoading: true, isError: false, error: null, refetch: vi.fn() });
    const loading = render(<PriceRulesPanel />);
    expect(screen.getByRole("status")).toHaveTextContent("Loading price rules...");
    loading.unmount();

    const refetch = vi.fn();
    hooks.usePromotionRules.mockReturnValueOnce({ rules: [], isLoading: false, isError: true, error: { status: 500 }, refetch });
    render(<PriceRulesPanel />);
    expect(screen.getByText("Service unavailable")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    expect(refetch).toHaveBeenCalled();
  });
});
