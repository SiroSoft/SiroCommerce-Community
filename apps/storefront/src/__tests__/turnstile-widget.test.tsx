import "@testing-library/jest-dom/vitest";
import { render } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { TurnstileWidget, turnstileConfigured } from "@/components/TurnstileWidget";

describe("turnstile widget", () => {
  it("renders nothing when no site key is configured", () => {
    const { container } = render(<TurnstileWidget onToken={() => undefined} />);
    expect(container.innerHTML).toBe("");
    expect(turnstileConfigured()).toBe(false);
  });
});
