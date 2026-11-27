import { useEffect, useRef, useState } from "react";
import type { RefObject } from "react";

/**
 * Shared focus/scroll helpers for the batch-1 UI primitives.
 *
 * Kept in one place so Modal, Popover (and later plugin components) trap,
 * lock and restore focus exactly the same way. Same selector as CartDrawer.
 */
export const FOCUSABLE_SELECTOR =
  'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

export function focusableElements(container: HTMLElement | null): HTMLElement[] {
  if (!container) return [];
  return Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR));
}

/** Cycle Tab/Shift+Tab inside `containerRef` while `active`. No-op when inactive. */
export function useFocusTrap(containerRef: RefObject<HTMLElement | null>, active: boolean): void {
  useEffect(() => {
    if (!active) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== "Tab") return;
      const items = focusableElements(containerRef.current);
      if (items.length === 0) {
        event.preventDefault();
        return;
      }
      const first = items[0]!;
      const last = items[items.length - 1]!;
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      } else if (!containerRef.current?.contains(document.activeElement)) {
        event.preventDefault();
        first.focus();
      }
    };
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [active, containerRef]);
}

/**
 * Lock body scroll while `locked`, restoring the previous overflow value on
 * cleanup. Same save/restore pattern as CartDrawer.
 */
export function useBodyScrollLock(locked: boolean): void {
  useEffect(() => {
    if (!locked || typeof document === "undefined") return;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.body.style.overflow = previousOverflow;
    };
  }, [locked]);
}

/**
 * Remember the element focused when `active` turns true; when it turns false
 * (or the component unmounts) move focus back — but only when focus would
 * otherwise be lost (activeElement is <body>). If the user already moved
 * focus somewhere meaningful (e.g. clicked another control) we leave it alone.
 */
export function useFocusReturn(active: boolean): void {
  const previousRef = useRef<HTMLElement | null>(null);
  useEffect(() => {
    if (!active) return;
    previousRef.current =
      document.activeElement instanceof HTMLElement ? document.activeElement : null;
    return () => {
      if (document.activeElement === document.body) {
        previousRef.current?.focus();
      }
    };
  }, [active]);
}

/** True when the user asked the OS/browser to reduce motion. */
export function usePrefersReducedMotion(): boolean {
  const [reduced, setReduced] = useState(
    () =>
      typeof window !== "undefined" &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches,
  );
  useEffect(() => {
    const query = window.matchMedia("(prefers-reduced-motion: reduce)");
    const onChange = () => setReduced(query.matches);
    query.addEventListener("change", onChange);
    return () => query.removeEventListener("change", onChange);
  }, []);
  return reduced;
}
