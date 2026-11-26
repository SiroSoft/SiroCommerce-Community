import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import FinancePage from "@/app/(dashboard)/finance/page";
import SettingsPage from "@/app/(dashboard)/settings/page";
import CampaignsPage from "@/app/(dashboard)/campaigns/page";
import { useAuthStore } from "@/store/auth.store";

afterEach(() => {
  useAuthStore.setState({ user: null, isAuthenticated: false });
  cleanup();
});

function expectReadOnly() {
  expect(screen.getByRole("alert")).toHaveTextContent(/read-only access/i);
}

describe("dashboard pages fail closed without the required capability", () => {
  it("hides the finance workspace from a viewer", () => {
    useAuthStore.setState({ user: { role: "viewer" }, isAuthenticated: true });
    render(<FinancePage />);
    expectReadOnly();
    expect(screen.queryByText(/financial reconciliation/i)).not.toBeInTheDocument();
  });

  it("hides campaign automation from support", () => {
    useAuthStore.setState({ user: { role: "support" }, isAuthenticated: true });
    render(<CampaignsPage />);
    expectReadOnly();
    expect(screen.queryByText(/safety limits/i)).not.toBeInTheDocument();
  });

  it("hides owner/admin settings from a marketer", () => {
    useAuthStore.setState({ user: { role: "marketer" }, isAuthenticated: true });
    render(<SettingsPage />);
    expectReadOnly();
    expect(screen.queryByText(/system info/i)).not.toBeInTheDocument();
  });
});
