import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { PurchaseError } from "@/purchase/contracts";

let search = "";

const operations = vi.hoisted(() => ({
  requestPasswordReset: vi.fn(async () => undefined),
  resetPassword: vi.fn(async () => undefined),
  verifyEmail: vi.fn(async () => undefined),
}));

vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams(search),
}));

vi.mock("next/link", () => ({
  default: ({ children, href }: React.PropsWithChildren<{ href: string }>) => <a href={href}>{children}</a>,
}));

vi.mock("@/purchase/fixture-adapter", () => ({
  FixturePurchaseAdapter: class {
    requestPasswordReset = operations.requestPasswordReset;
    resetPassword = operations.resetPassword;
    verifyEmail = operations.verifyEmail;
  },
}));

import { ForgotPasswordForm, ResetPasswordForm, VerifyEmailForm } from "@/components/AuthRecoveryForms";

describe("auth recovery forms", () => {
  beforeEach(() => {
    search = "";
    operations.requestPasswordReset.mockReset().mockResolvedValue(undefined);
    operations.resetPassword.mockReset().mockResolvedValue(undefined);
    operations.verifyEmail.mockReset().mockResolvedValue(undefined);
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  describe("forgot password", () => {
    it("shows the neutral confirmation when the account may not exist", async () => {
      operations.requestPasswordReset.mockRejectedValueOnce(new Error("network"));
      render(<ForgotPasswordForm />);
      fireEvent.change(screen.getByLabelText("Email"), { target: { value: "buyer@example.com" } });
      fireEvent.click(screen.getByRole("button", { name: "Send reset link" }));

      expect(await screen.findByRole("status")).toHaveTextContent("If the account exists");
      expect(operations.requestPasswordReset).toHaveBeenCalledWith("buyer@example.com", undefined);
    });

    it("surfaces validation and rate-limit failures and resets the turnstile token", async () => {
      operations.requestPasswordReset.mockRejectedValueOnce(new PurchaseError("validation", "Enter a valid email", { status: 422 }));
      render(<ForgotPasswordForm />);
      fireEvent.change(screen.getByLabelText("Email"), { target: { value: "buyer@example.com" } });
      fireEvent.click(screen.getByRole("button", { name: "Send reset link" }));
      expect(await screen.findByRole("alert")).toHaveTextContent("Enter a valid email");
      expect(screen.queryByRole("status")).toBeNull();

      operations.requestPasswordReset.mockRejectedValueOnce(new PurchaseError("rate_limited", "Too many attempts", { status: 429 }));
      fireEvent.click(screen.getByRole("button", { name: "Send reset link" }));
      expect(await screen.findByRole("alert")).toHaveTextContent("Too many attempts");
    });

    it("confirms a successful send", async () => {
      render(<ForgotPasswordForm />);
      fireEvent.change(screen.getByLabelText("Email"), { target: { value: "buyer@example.com" } });
      fireEvent.click(screen.getByRole("button", { name: "Send reset link" }));
      expect(await screen.findByRole("status")).toHaveTextContent("Check your inbox");
    });
  });

  describe("reset password", () => {
    it("rejects a missing token without calling the service", () => {
      render(<ResetPasswordForm />);
      expect(screen.getByRole("alert")).toHaveTextContent("reset link is invalid");
      expect(operations.resetPassword).not.toHaveBeenCalled();
    });

    it("resets the password and offers the sign-in link", async () => {
      search = "?token=reset-token";
      render(<ResetPasswordForm />);
      fireEvent.change(screen.getByLabelText("New password"), { target: { value: "longenough" } });
      fireEvent.click(screen.getByRole("button", { name: "Reset password" }));
      expect(await screen.findByRole("status")).toHaveTextContent("Password has been reset");
      expect(screen.getByRole("link", { name: "Back to sign in" })).toHaveAttribute("href", "/account");
      expect(operations.resetPassword).toHaveBeenCalledWith("reset-token", "longenough");
    });

    it("maps a purchase error to the form alert", async () => {
      search = "?token=expired-token";
      operations.resetPassword.mockRejectedValueOnce(new PurchaseError("validation", "Reset link is expired", { status: 422 }));
      render(<ResetPasswordForm />);
      fireEvent.change(screen.getByLabelText("New password"), { target: { value: "longenough" } });
      fireEvent.click(screen.getByRole("button", { name: "Reset password" }));
      expect(await screen.findByRole("alert")).toHaveTextContent("Reset link is expired");
    });

    it("falls back to localized copy for non-error failures", async () => {
      search = "?token=reset-token";
      operations.resetPassword.mockRejectedValueOnce("not-an-error");
      render(<ResetPasswordForm />);
      fireEvent.change(screen.getByLabelText("New password"), { target: { value: "longenough" } });
      fireEvent.click(screen.getByRole("button", { name: "Reset password" }));
      expect(await screen.findByRole("alert")).toHaveTextContent("reset link is invalid or expired");
    });
  });

  describe("email verification", () => {
    it("asks for a complete link when the token is missing", () => {
      render(<VerifyEmailForm />);
      expect(screen.getByRole("alert")).toHaveTextContent("verification link is missing");
      expect(operations.verifyEmail).not.toHaveBeenCalled();
    });

    it("confirms a successful verification", async () => {
      search = "?token=verify-token";
      render(<VerifyEmailForm />);
      expect(screen.getByRole("status")).toHaveTextContent("Confirming your email");
      expect(await screen.findByRole("status")).toHaveTextContent("Email verified");
      expect(operations.verifyEmail).toHaveBeenCalledWith("verify-token");
    });

    it("reports an invalid or expired verification link", async () => {
      search = "?token=stale-token";
      operations.verifyEmail.mockRejectedValueOnce(new PurchaseError("validation", "invalid", { status: 422 }));
      render(<VerifyEmailForm />);
      expect(await screen.findByRole("alert")).toHaveTextContent("verification link is invalid or expired");
    });

    it("supports provider validation errors with the same fallback", async () => {
      search = "?token=stale-token";
      operations.verifyEmail.mockRejectedValueOnce(new Error("boom"));
      render(<VerifyEmailForm />);
      await waitFor(() => expect(screen.getByRole("alert")).toHaveTextContent("verification link is invalid or expired"));
    });
  });
});
