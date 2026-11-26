import "@testing-library/jest-dom/vitest";
import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { FixturePurchaseAdapter } from "../purchase/fixture-adapter";
import { PurchaseService } from "../purchase/service";

vi.mock("next/link", () => ({ default: ({ children, ...props }: React.PropsWithChildren<{ href: string }>) => <a {...props} href={props.href}>{children}</a> }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: () => undefined }), usePathname: () => "/register" }));

describe("customer registration", () => {
  it("creates a fixture session for valid input", async () => {
    const service = new PurchaseService(new FixturePurchaseAdapter());
    await expect(service.register({ name: "New Buyer", email: "new-buyer@example.com", password: "secret123" })).resolves.toMatchObject({ access_token: "customer-token-fixture" });
  });

  it("rejects weak input and duplicate emails without touching the network", async () => {
    const service = new PurchaseService(new FixturePurchaseAdapter());
    await expect(service.register({ name: "", email: "bad", password: "short" })).rejects.toMatchObject({ status: 422 });
    await expect(service.register({ name: "Buyer", email: "buyer@example.com", password: "secret123" })).rejects.toMatchObject({ status: 409 });
  });

  it("renders a stacked register form with help and sign-in fallback", async () => {
    const { RegisterForm } = await import("../components/RegisterForm");
    const { container } = render(<RegisterForm />);
    expect(container.querySelector(".auth-form")).not.toBeNull();
    expect(screen.getByLabelText(/full name/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/password/i)).toHaveAccessibleName("Password");
    expect(screen.getByRole("button", { name: /create account/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /sign in/i })).toHaveAttribute("href", "/account");
  });
});
