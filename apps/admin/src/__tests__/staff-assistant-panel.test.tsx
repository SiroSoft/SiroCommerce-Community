import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const assistantAskMock = vi.hoisted(() => vi.fn());
vi.mock("@/services/extensions.service", () => ({
  extensionsService: { assistantAsk: assistantAskMock },
}));

import { StaffAssistantPanel } from "@/components/staff-assistant-panel";

const panel = {
  key: "chat",
  title: "Staff assistant",
  type: "assistant" as const,
  endpoint: "admin/ask",
  permission: "staff-assistant:ask",
};

afterEach(() => cleanup());

describe("StaffAssistantPanel", () => {
  beforeEach(() => assistantAskMock.mockReset());

  it("submits scoped ids and renders the grounded answer", async () => {
    assistantAskMock.mockResolvedValue({
      status: "available",
      answer: "The order is paid and ready to ship.",
      sources: [{ label: "Order 1001", url: "/orders/o-1" }],
    });

    render(<StaffAssistantPanel extensionId="siro/staff-assistant" panel={panel} />);
    fireEvent.change(screen.getByLabelText("Assistant question"), { target: { value: "Summarize this order" } });
    fireEvent.change(screen.getByLabelText("Order ID"), { target: { value: "o-1" } });
    fireEvent.change(screen.getByLabelText("Customer ID"), { target: { value: "c-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Ask assistant" }));

    expect(await screen.findByText("The order is paid and ready to ship.")).toBeTruthy();
    await waitFor(() => expect(assistantAskMock).toHaveBeenCalledWith(
      "siro/staff-assistant",
      "admin/ask",
      { question: "Summarize this order", order_id: "o-1", customer_id: "c-1" },
    ));
  });

  it("shows a safe error without leaking provider detail", async () => {
    assistantAskMock.mockResolvedValue(null);
    render(<StaffAssistantPanel extensionId="siro/staff-assistant" panel={panel} />);
    fireEvent.change(screen.getByLabelText("Assistant question"), { target: { value: "Check status" } });
    fireEvent.click(screen.getByRole("button", { name: "Ask assistant" }));
    expect(await screen.findByRole("alert")).toHaveTextContent(/assistant is unavailable/i);
    expect(screen.queryByText(/provider secret detail/i)).toBeNull();
    expect(screen.queryByText("autonomous_mutation")).toBeNull();
  });
});
