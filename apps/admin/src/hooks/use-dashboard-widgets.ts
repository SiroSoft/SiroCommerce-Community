"use client";

import { useState } from "react";

export const DASHBOARD_WIDGETS = [
  { id: "stats", label: "Stat cards" },
  { id: "ranges", label: "Today & week" },
  { id: "recent", label: "Recent orders" },
  { id: "status", label: "Orders by status" },
  { id: "revenue", label: "Revenue chart" },
  { id: "audit", label: "Audit timeline" },
] as const;

export type WidgetId = (typeof DASHBOARD_WIDGETS)[number]["id"];

const WIDGETS_KEY = "siro-dashboard-widgets-hidden";

function loadHiddenWidgets(): WidgetId[] {
  try {
    const raw = localStorage.getItem(WIDGETS_KEY);
    const parsed: unknown = raw ? JSON.parse(raw) : [];
    const ids = DASHBOARD_WIDGETS.map((widget) => widget.id);
    return Array.isArray(parsed) ? parsed.filter((id): id is WidgetId => typeof id === "string" && ids.includes(id as WidgetId)) : [];
  } catch {
    return [];
  }
}

export function useDashboardWidgets() {
  const [hidden, setHidden] = useState<WidgetId[]>(() => (typeof window === "undefined" ? [] : loadHiddenWidgets()));
  const [customizing, setCustomizing] = useState(false);
  const toggle = (id: WidgetId) => {
    setHidden((current) => {
      const next = current.includes(id) ? current.filter((value) => value !== id) : [...current, id];
      try {
        localStorage.setItem(WIDGETS_KEY, JSON.stringify(next));
      } catch {
        // private mode — customization simply won't persist
      }
      return next;
    });
  };
  const visible = (id: WidgetId) => !hidden.includes(id);
  return { hidden, customizing, setCustomizing, toggle, visible };
}
