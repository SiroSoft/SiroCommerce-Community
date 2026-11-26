import "@testing-library/jest-dom/vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { describe, expect, it, vi, beforeEach } from "vitest";
import { AdminGlobalSearch } from "@/components/admin-global-search";
import { adminConsoleService } from "@/services/admin-console.service";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }), usePathname: () => "/" }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ user: { role: "owner" } }) }));
vi.mock("@/services/admin-console.service", () => ({
  adminConsoleService: { search: vi.fn(async () => ({ data: [], meta: { page: 1, per_page: 4, total: 0, last_page: 1 }, type: "orders" })) },
}));

function openPalette() {
  fireEvent.keyDown(window, { key: "k", ctrlKey: true });
}

describe("command palette", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("opens on Ctrl+K and lists actions and pages", async () => {
    render(<AdminGlobalSearch />);
    act(() => openPalette());
    expect(await screen.findByRole("dialog", { name: "Command palette" })).toBeVisible();
    expect(screen.getByText("New order")).toBeVisible();
    expect(screen.getByText("Orders")).toBeVisible();
  });

  it("filters pages by fuzzy query", async () => {
    render(<AdminGlobalSearch />);
    act(() => openPalette());
    const input = await screen.findByLabelText("Command palette search");
    fireEvent.change(input, { target: { value: "theme" } });
    await waitFor(() => expect(screen.getByText("Theme builder")).toBeVisible());
    expect(screen.queryByText("Users")).toBeNull();
  });

  it("shows record results from all three scopes", async () => {
    vi.mocked(adminConsoleService.search).mockImplementation(async (_q: string, type?: string) => ({
      data: type === "orders" ? [{ id: 42, number: "SO-42", state: "paid" }] : [],
      meta: { page: 1, per_page: 4, total: 0, last_page: 1 },
      type: type as never,
    }));
    render(<AdminGlobalSearch />);
    act(() => openPalette());
    const input = await screen.findByLabelText("Command palette search");
    fireEvent.change(input, { target: { value: "42" } });
    expect(await screen.findByText("SO-42")).toBeVisible();
  });

  it("closes on Escape", async () => {
    render(<AdminGlobalSearch />);
    act(() => openPalette());
    expect(await screen.findByRole("dialog", { name: "Command palette" })).toBeVisible();
    const input = await screen.findByLabelText("Command palette search");
    fireEvent.keyDown(input, { key: "Escape" });
    expect(screen.queryByRole("dialog", { name: "Command palette" })).toBeNull();
  });

  it("navigates with keyboard", async () => {
    render(<AdminGlobalSearch />);
    act(() => openPalette());
    const input = await screen.findByLabelText("Command palette search");
    fireEvent.keyDown(input, { key: "ArrowDown" });
    fireEvent.keyDown(input, { key: "Enter" });
    await waitFor(() => expect(screen.queryByRole("dialog", { name: "Command palette" })).toBeNull());
  });
});
