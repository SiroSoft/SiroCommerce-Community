import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { CapabilityGate, RoleGate } from "@/components/capability-gate";
import { useAuthStore } from "@/store/auth.store";

let currentRole: string | undefined;

afterEach(() => {
  currentRole = undefined;
  useAuthStore.setState({ user: null, isAuthenticated: false });
  cleanup();
});

describe("capability and route gates", () => {
  it.each([
    ["owner", true], ["admin", true], ["manager", false], ["operator", false], ["viewer", false],
  ])("renders the orders route action for %s according to the backend contract", (role, allowed) => {
    currentRole = role;
    useAuthStore.setState({ user: { role }, isAuthenticated: true });
    render(<CapabilityGate capability="orders"><button>Transition order</button></CapabilityGate>);
    if (allowed) expect(screen.getByRole("button", { name: "Transition order" })).toBeEnabled();
    else {
      expect(screen.queryByRole("button", { name: "Transition order" })).not.toBeInTheDocument();
      expect(screen.getByRole("alert")).toHaveTextContent(/read-only access/i);
    }
  });

  it.each([
    ["owner", true], ["admin", true], ["manager", false], ["operator", false], ["viewer", false],
  ])("blocks the admin-only action center route for %s", (role, allowed) => {
    currentRole = role;
    useAuthStore.setState({ user: { role }, isAuthenticated: true });
    render(<RoleGate roles={["owner", "admin"]}><p>Action center</p></RoleGate>);
    if (allowed) expect(screen.getByText("Action center")).toBeInTheDocument();
    else {
      expect(screen.queryByText("Action center")).not.toBeInTheDocument();
      expect(screen.getByRole("alert")).toHaveTextContent(/read-only access/i);
    }
  });
});
