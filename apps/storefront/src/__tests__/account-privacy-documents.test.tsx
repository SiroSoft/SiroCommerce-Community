import "@testing-library/jest-dom/vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

let search = "";

const customerAdapter = vi.hoisted(() => ({
  recordConsent: vi.fn(async () => ({})),
  requestDeletion: vi.fn(async () => ({ customer_id: "c-1", state: "anonymized", financial_records: "retained" })),
  exportPrivacy: vi.fn(async () => ({ customer: { id: "c-1" }, orders: [], addresses: [], notification_preferences: {}, consents: [] })),
  getFinancialDocuments: vi.fn(async () => ({
    order_id: "o-1",
    currency: "USD",
    totals: { grand_total_minor: 1250, refunded_minor: 250 },
    invoices: [{ id: "inv-1", number: "INV-1", state: "issued", currency: "USD", minor_unit: 2, amount_minor: 1000 }],
    receipts: [],
    credit_memos: [{ id: "cm-1", number: "CM-1", state: "issued", currency: "USD", minor_unit: 2, amount_minor: 250 }],
    refund_timeline: [{ id: "rf-1", state: "processed", currency: "USD", minor_unit: 2, amount_minor: 250, reason: "damaged" }],
    available: false,
  })),
  getMessageHistory: vi.fn(async () => ({
    items: [
      { event: "order_placed", status: "sent", sent_at: "2026-01-01T10:00:00Z" },
      { event: "shipped", status: "processing", sent_at: null },
      { event: "delivered", status: "not_delivered", created_at: "2026-01-02" },
    ],
    page: 1,
    last_page: 1,
  })),
}));

vi.mock("@/services/customer", () => ({
  customer: () => customerAdapter,
}));

vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams(search),
}));

import { AccountPrivacyControls } from "@/components/AccountPrivacyControls";
import { FinancialDocumentsPanel } from "@/components/FinancialDocumentsPanel";
import { MessageHistory } from "@/components/MessageHistory";

describe("account privacy and financial documents", () => {
  beforeEach(() => {
    search = "";
    window.localStorage.clear();
    customerAdapter.recordConsent.mockReset().mockResolvedValue({});
    customerAdapter.requestDeletion.mockReset().mockResolvedValue({ customer_id: "c-1", state: "anonymized", financial_records: "retained" });
    customerAdapter.exportPrivacy.mockReset().mockResolvedValue({ customer: { id: "c-1" }, orders: [], addresses: [], notification_preferences: {}, consents: [] });
    customerAdapter.getFinancialDocuments.mockClear();
    customerAdapter.getMessageHistory.mockReset().mockResolvedValue({
      items: [
        { event: "order_placed", status: "sent", sent_at: "2026-01-01T10:00:00Z" },
        { event: "shipped", status: "processing", sent_at: null },
        { event: "delivered", status: "not_delivered", created_at: "2026-01-02" },
      ],
      page: 1,
      last_page: 1,
    });
  });

  afterEach(() => {
    window.localStorage.clear();
    vi.restoreAllMocks();
  });

  describe("privacy controls", () => {
    it("records an analytics grant and persists the local gate", async () => {
      render(<AccountPrivacyControls />);
      fireEvent.click(screen.getByRole("button", { name: "Allow analytics" }));
      expect(await screen.findByRole("status")).toHaveTextContent("Analytics enabled.");
      expect(customerAdapter.recordConsent).toHaveBeenCalledWith(true);
      expect(window.localStorage.getItem("sirocommerce:analytics-consent")).toBe("granted");
    });

    it("records an opt-out and reports a save failure", async () => {
      customerAdapter.recordConsent.mockRejectedValueOnce(new Error("offline"));
      render(<AccountPrivacyControls />);
      fireEvent.click(screen.getByRole("button", { name: "Opt out of analytics" }));
      expect(await screen.findByRole("status")).toHaveTextContent("could not be saved");
      expect(customerAdapter.recordConsent).toHaveBeenCalledWith(false);
    });

    it("requires confirmation before requesting deletion", async () => {
      vi.spyOn(window, "confirm").mockReturnValueOnce(false);
      render(<AccountPrivacyControls />);
      fireEvent.click(screen.getByRole("button", { name: "Request account deletion" }));
      expect(customerAdapter.requestDeletion).not.toHaveBeenCalled();

      vi.spyOn(window, "confirm").mockReturnValueOnce(true);
      fireEvent.click(screen.getByRole("button", { name: "Request account deletion" }));
      expect(await screen.findByRole("status")).toHaveTextContent("Request completed: anonymized. Financial records: retained.");
    });

    it("reports a failed deletion request", async () => {
      vi.spyOn(window, "confirm").mockReturnValue(true);
      customerAdapter.requestDeletion.mockRejectedValueOnce(new Error("forbidden"));
      render(<AccountPrivacyControls />);
      fireEvent.click(screen.getByRole("button", { name: "Request account deletion" }));
      expect(await screen.findByRole("status")).toHaveTextContent("could not be submitted");
    });

    it("downloads a JSON export and reports export failures", async () => {
      const createObjectURL = vi.fn(() => "blob:export");
      const revokeObjectURL = vi.fn();
      Object.defineProperty(URL, "createObjectURL", { configurable: true, value: createObjectURL });
      Object.defineProperty(URL, "revokeObjectURL", { configurable: true, value: revokeObjectURL });
      const click = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => undefined);

      render(<AccountPrivacyControls />);
      fireEvent.click(screen.getByRole("button", { name: "Download my data" }));
      expect(await screen.findByRole("status")).toHaveTextContent("personal data export is ready");
      expect(createObjectURL).toHaveBeenCalledOnce();
      expect(click).toHaveBeenCalledOnce();
      expect(revokeObjectURL).toHaveBeenCalledWith("blob:export");

      customerAdapter.exportPrivacy.mockRejectedValueOnce(new Error("boom"));
      fireEvent.click(screen.getByRole("button", { name: "Download my data" }));
      expect(await screen.findByRole("status")).toHaveTextContent("could not be prepared");
    });
  });

  describe("financial documents panel", () => {
    it("shows an unavailable state when no order id is present", async () => {
      render(<FinancialDocumentsPanel />);
      expect(await screen.findByText(/Financial documents are unavailable/)).toBeInTheDocument();
      expect(customerAdapter.getFinancialDocuments).not.toHaveBeenCalled();
    });

    it("reads the order id from the query string and renders totals and documents", async () => {
      search = "?order_id=o-1";
      render(<FinancialDocumentsPanel />);
      expect(screen.getByText("Loading financial documents...")).toBeInTheDocument();
      expect(await screen.findByText("INV-1")).toBeInTheDocument();
      expect(screen.getByText("CM-1")).toBeInTheDocument();
      expect(screen.getByText(/No financial document has been issued/)).toBeInTheDocument();
      expect(screen.getByText(/Refund processed/)).toBeInTheDocument();
      expect(screen.getByText(/damaged/)).toBeInTheDocument();
      expect(screen.getAllByText(/Download unavailable/).length).toBe(2);
      expect(customerAdapter.getFinancialDocuments).toHaveBeenCalledWith("o-1");
    });

    it("prefers a prop order id and degrades to unavailable on failure", async () => {
      customerAdapter.getFinancialDocuments.mockRejectedValueOnce(new Error("boom"));
      render(<FinancialDocumentsPanel orderId="o-2" />);
      expect(await screen.findByText(/Financial documents are unavailable/)).toBeInTheDocument();
      expect(customerAdapter.getFinancialDocuments).toHaveBeenCalledWith("o-2");
    });
  });

  describe("message history", () => {
    it("does not call the API without a stored session", async () => {
      render(<MessageHistory />);
      expect(await screen.findByRole("note")).toHaveTextContent("Message history is unavailable");
      expect(customerAdapter.getMessageHistory).not.toHaveBeenCalled();
    });

    it("renders sent, processing and undelivered events for a signed-in browser", async () => {
      window.localStorage.setItem("siro_storefront_refresh_token", "refresh-token");
      render(<MessageHistory />);
      await act(async () => { window.dispatchEvent(new Event("siro:session-updated")); });
      expect(await screen.findByText("order placed")).toBeInTheDocument();
      expect(screen.getByText("shipped")).toBeInTheDocument();
      expect(screen.getByText("delivered")).toBeInTheDocument();
      expect(screen.getByText(/Sent/)).toBeInTheDocument();
      expect(screen.getByText(/Processing/)).toBeInTheDocument();
      expect(screen.getByText(/Not delivered/)).toBeInTheDocument();
      expect(customerAdapter.getMessageHistory).toHaveBeenCalledOnce();
    });

    it("shows an empty state when no messages exist", async () => {
      window.localStorage.setItem("siro_storefront_refresh_token", "refresh-token");
      customerAdapter.getMessageHistory.mockResolvedValueOnce({ items: [], page: 1, last_page: 1 });
      render(<MessageHistory />);
      await act(async () => { window.dispatchEvent(new Event("siro:session-updated")); });
      expect(await screen.findByText(/No messages have been recorded/)).toBeInTheDocument();
    });

    it("shows unavailable when the message read fails", async () => {
      window.localStorage.setItem("siro_storefront_refresh_token", "refresh-token");
      customerAdapter.getMessageHistory.mockRejectedValueOnce(new Error("401"));
      render(<MessageHistory />);
      await act(async () => { window.dispatchEvent(new Event("siro:session-updated")); });
      expect(await screen.findByRole("note")).toHaveTextContent("Message history is unavailable");
    });

    it("loads when a same-page sign-in restores the session", async () => {
      render(<MessageHistory />);
      expect(await screen.findByRole("note")).toBeInTheDocument();
      window.localStorage.setItem("siro_storefront_refresh_token", "refresh-token");
      await act(async () => {
        window.dispatchEvent(new Event("siro:session-updated"));
      });
      await waitFor(() => expect(screen.getByText("order placed")).toBeInTheDocument());
    });
  });
});
