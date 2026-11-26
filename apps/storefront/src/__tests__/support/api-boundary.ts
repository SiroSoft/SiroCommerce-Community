import { vi } from "vitest";
import { ApiPurchaseAdapter } from "../../purchase/api-adapter.js";

export type BoundaryCall = { url: string; init: RequestInit };
export type BoundaryReply = { data: unknown; status?: number } | Error;

export function apiBoundary(replies: BoundaryReply[]) {
  const calls: BoundaryCall[] = [];
  const transport = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    calls.push({ url: String(input), init: init ?? {} });
    const reply = replies.shift();
    if (reply instanceof Error) throw reply;
    const status = reply?.status ?? 200;
    return new Response(JSON.stringify({ success: status < 400, message: status < 400 ? "ok" : "failed", data: reply?.data, meta: { errors: { email: ["Invalid"] } } }), { status, headers: { "Content-Type": "application/json" } });
  });
  return { calls, transport, adapter: () => new ApiPurchaseAdapter({ baseUrl: "https://commerce.test", fetch: transport }) };
}
