import "@testing-library/jest-dom/vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { SearchSuggest } from "@/components/SearchSuggest";

function json(body: unknown): Response {
  return new Response(JSON.stringify(body), { status: 200, headers: { "Content-Type": "application/json" } });
}

beforeEach(() => {
  vi.useFakeTimers();
  vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
});

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe("search suggestions", () => {
  it("ignores a stale response and aborts the slow request when unmounted", async () => {
    let resolveFirst!: (response: Response) => void;
    let firstSignal: AbortSignal | undefined;
    let hangingSignal: AbortSignal | undefined;
    const first = new Promise<Response>((resolve) => {
      resolveFirst = resolve;
    });
    const hanging = new Promise<Response>(() => undefined);
    const transport = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      const query = new URL(String(input)).searchParams.get("q");
      if (query === "sh") {
        firstSignal = init?.signal ?? undefined;
        return first;
      }
      if (query === "shoe") {
        hangingSignal = init?.signal ?? undefined;
        return hanging;
      }
      return Promise.resolve(json({ data: [{ query: "shoes", submits: 2, clicks: 1 }] }));
    });
    vi.stubGlobal("fetch", transport);

    const { unmount } = render(
      <>
        <input id="search-suggest-input" />
        <SearchSuggest inputId="search-suggest-input" onPick={vi.fn()} />
      </>,
    );
    const input = document.getElementById("search-suggest-input") as HTMLInputElement;

    await act(async () => {
      input.focus();
    });
    input.value = "sh";
    await act(async () => {
      fireEvent.input(input);
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(160);
    });
    expect(transport).toHaveBeenCalledTimes(1);

    input.value = "sho";
    await act(async () => {
      fireEvent.input(input);
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(160);
    });
    expect(screen.getByRole("option", { name: "shoes" })).toBeInTheDocument();

    await act(async () => {
      resolveFirst(json({ data: [{ query: "shirt", submits: 9, clicks: 3 }] }));
      await Promise.resolve();
    });
    expect(screen.queryByRole("option", { name: "shirt" })).toBeNull();
    expect(screen.getByRole("option", { name: "shoes" })).toBeInTheDocument();

    input.value = "shoe";
    await act(async () => {
      fireEvent.input(input);
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(160);
    });
    expect(hangingSignal).toBeDefined();

    unmount();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(2000);
    });
    expect(hangingSignal?.aborted).toBe(true);
    expect(firstSignal).toBeDefined();
  });
});
