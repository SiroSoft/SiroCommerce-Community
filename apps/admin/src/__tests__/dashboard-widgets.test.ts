import { act, renderHook } from "@testing-library/react";
import { describe, expect, it, beforeEach } from "vitest";
import { DASHBOARD_WIDGETS, useDashboardWidgets } from "@/hooks/use-dashboard-widgets";

describe("dashboard widgets", () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it("shows every widget by default", () => {
    const { result } = renderHook(() => useDashboardWidgets());
    expect(DASHBOARD_WIDGETS.length).toBe(6);
    for (const widget of DASHBOARD_WIDGETS) {
      expect(result.current.visible(widget.id)).toBe(true);
    }
  });

  it("toggles visibility and persists across reloads", () => {
    const { result } = renderHook(() => useDashboardWidgets());
    act(() => result.current.toggle("revenue"));
    expect(result.current.visible("revenue")).toBe(false);
    expect(JSON.parse(localStorage.getItem("siro-dashboard-widgets-hidden") ?? "[]")).toEqual(["revenue"]);
    const reloaded = renderHook(() => useDashboardWidgets());
    expect(reloaded.result.current.visible("revenue")).toBe(false);
    expect(reloaded.result.current.visible("audit")).toBe(true);
    act(() => reloaded.result.current.toggle("revenue"));
    expect(reloaded.result.current.visible("revenue")).toBe(true);
  });

  it("ignores corrupt or unknown stored values", () => {
    localStorage.setItem("siro-dashboard-widgets-hidden", JSON.stringify(["revenue", "nope", 42]));
    const { result } = renderHook(() => useDashboardWidgets());
    expect(result.current.visible("revenue")).toBe(false);
    expect(result.current.visible("audit")).toBe(true);
  });
});
