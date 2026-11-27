"use client";

import { useCallback, useEffect, useId, useRef, useState } from "react";
import type { ReactNode } from "react";
import { focusableElements, useFocusReturn } from "./focus";

export interface PopoverProps {
  /** Content of the trigger button. */
  triggerLabel: ReactNode;
  /** Accessible name for the trigger when `triggerLabel` alone is not enough. */
  triggerAriaLabel?: string;
  /** Extra class for the trigger button (lane styling). */
  triggerClassName?: string;
  /** Accessible name of the panel. Required — a popover must be named. */
  panelLabel: string;
  children: ReactNode;
  placement?: "below" | "above";
  /** Controlled open state. Omit for uncontrolled. */
  open?: boolean;
  defaultOpen?: boolean;
  onOpenChange?: (open: boolean) => void;
  closeOnEscape?: boolean;
}

/**
 * Non-modal popover anchored to a trigger button (info panels, mini-menus, …).
 * Unlike Modal it does NOT trap Tab or lock scroll — Tab naturally leaves the
 * panel and that closes it.
 *
 * - Trigger: `aria-haspopup="dialog"`, `aria-expanded`, `aria-controls`.
 * - Panel: `role="dialog"` (no `aria-modal`).
 * - ESC closes (when `closeOnEscape`, default true); click-outside closes;
 *   focus leaving the popover closes it; focus returns to the trigger.
 * - The panel is positioned with CSS only (no JS measurement), so it is
 *   SSR-safe and never shifts layout on mount.
 */
export function Popover({
  triggerLabel,
  triggerAriaLabel,
  triggerClassName,
  panelLabel,
  children,
  placement = "below",
  open,
  defaultOpen = false,
  onOpenChange,
  closeOnEscape = true,
}: PopoverProps) {
  const [uncontrolledOpen, setUncontrolledOpen] = useState(defaultOpen);
  const controlled = open !== undefined;
  const isOpen = controlled ? open : uncontrolledOpen;
  const setOpen = useCallback(
    (next: boolean) => {
      if (!controlled) setUncontrolledOpen(next);
      onOpenChange?.(next);
    },
    [controlled, onOpenChange],
  );

  const panelId = useId();
  const wrapRef = useRef<HTMLDivElement | null>(null);
  const panelRef = useRef<HTMLDivElement | null>(null);

  useFocusReturn(isOpen);

  useEffect(() => {
    if (!isOpen) return;
    const first = focusableElements(panelRef.current)[0];
    (first ?? panelRef.current)?.focus();
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape" && closeOnEscape) {
        event.preventDefault();
        setOpen(false);
      }
    };
    const onPointerDown = (event: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    };
    const onFocusIn = (event: FocusEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener("keydown", onKeyDown);
    document.addEventListener("pointerdown", onPointerDown);
    document.addEventListener("focusin", onFocusIn);
    return () => {
      document.removeEventListener("keydown", onKeyDown);
      document.removeEventListener("pointerdown", onPointerDown);
      document.removeEventListener("focusin", onFocusIn);
    };
  }, [isOpen, closeOnEscape, setOpen]);

  return (
    <div className={`ui-popover ui-popover--${placement}`} ref={wrapRef}>
      <button
        type="button"
        className={["ui-popover-trigger", triggerClassName].filter(Boolean).join(" ")}
        aria-haspopup="dialog"
        aria-expanded={isOpen}
        aria-controls={panelId}
        {...(triggerAriaLabel ? { "aria-label": triggerAriaLabel } : {})}
        onClick={() => setOpen(!isOpen)}
      >
        {triggerLabel}
      </button>
      {isOpen ? (
        <div
          id={panelId}
          className="ui-popover-panel"
          role="dialog"
          aria-label={panelLabel}
          ref={panelRef}
          tabIndex={-1}
        >
          {children}
        </div>
      ) : null}
    </div>
  );
}
