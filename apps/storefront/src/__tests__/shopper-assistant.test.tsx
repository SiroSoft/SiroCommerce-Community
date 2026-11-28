import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ShopperAssistant } from "@/plugins/shopper-assistant";
import { setCustomerAccessToken } from "@/services/customer";

const mocks = vi.hoisted(() => ({ pathname: "/product/linen-camp-shirt" }));

vi.mock("next/navigation", () => ({ usePathname: () => mocks.pathname }));

function replyBody() {
  return {
    success: true,
    data: { status: "available", answer: "Here to help.", sources: [], reason: null },
  };
}

let fetchMock: ReturnType<typeof vi.fn>;

describe("shopper assistant widget", () => {
  beforeEach(() => {
    mocks.pathname = "/product/linen-camp-shirt";
    fetchMock = vi.fn(async () => new Response(JSON.stringify(replyBody()), {
      status: 200,
      headers: { "Content-Type": "application/json" },
    }));
    vi.stubGlobal("fetch", fetchMock);
  });

  afterEach(() => {
    setCustomerAccessToken(undefined);
    vi.unstubAllGlobals();
  });

  function openWidget() {
    fireEvent.click(screen.getByRole("button", { name: "Open shopping assistant" }));
  }

  it("keeps the anonymous product question flow unchanged", async () => {
    render(<ShopperAssistant />);
    openWidget();
    fireEvent.change(screen.getByRole("textbox", { name: "Your question" }), {
      target: { value: "Which size fits me?" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Ask" }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(String(url)).toContain("/api/ext/siro/shopper-assistant/public/ask");
    expect(JSON.parse(String(init.body))).toEqual({
      question: "Which size fits me?",
      product_slug: "linen-camp-shirt",
    });
    expect(await screen.findByText("Here to help.")).toBeInTheDocument();
  });

  it("sends order access fields only after the shopper opts in", async () => {
    render(<ShopperAssistant />);
    openWidget();
    fireEvent.click(screen.getByRole("button", { name: /have an order/i }));
    fireEvent.change(screen.getByLabelText(/order id/i), { target: { value: "order-123" } });
    const tokenInput = screen.getByLabelText(/order access token/i);
    expect(tokenInput).toHaveAttribute("type", "password");
    fireEvent.change(tokenInput, { target: { value: "guest-token" } });
    fireEvent.change(screen.getByRole("textbox", { name: "Your question" }), {
      target: { value: "Where is my order?" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Ask" }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(JSON.parse(String(init.body))).toEqual({
      question: "Where is my order?",
      product_slug: "linen-camp-shirt",
      order_id: "order-123",
      order_token: "guest-token",
    });
  });

  it("sends the signed-in session and an order id without a guest token", async () => {
    setCustomerAccessToken("session-token");
    render(<ShopperAssistant />);
    openWidget();
    fireEvent.click(screen.getByRole("button", { name: /have an order/i }));
    fireEvent.change(screen.getByLabelText(/order id/i), { target: { value: "owned-order" } });
    fireEvent.change(screen.getByRole("textbox", { name: "Your question" }), {
      target: { value: "What is my order status?" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Ask" }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect((init.headers as Record<string, string>).Authorization).toBe("Bearer session-token");
    const body = JSON.parse(String(init.body)) as Record<string, unknown>;
    expect(body.order_id).toBe("owned-order");
    expect(body).not.toHaveProperty("order_token");
  });

  it("does not send an order token without an order id", async () => {
    render(<ShopperAssistant />);
    openWidget();
    fireEvent.click(screen.getByRole("button", { name: /have an order/i }));
    fireEvent.change(screen.getByLabelText(/order access token/i), { target: { value: "orphan-token" } });
    fireEvent.change(screen.getByRole("textbox", { name: "Your question" }), {
      target: { value: "What is your return policy?" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Ask" }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const body = JSON.parse(String(init.body)) as Record<string, unknown>;
    expect(body).not.toHaveProperty("order_id");
    expect(body).not.toHaveProperty("order_token");
  });
});
