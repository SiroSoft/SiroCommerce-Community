"use client";

import { useEffect, useId, useRef, useState } from "react";
import type { ReactNode } from "react";
import { createPortal } from "react-dom";
import { focusableElements, useBodyScrollLock, useFocusReturn, useFocusTrap } from "./focus";

export interface ModalProps {
  /** Controls visibility. Renders nothing (not even a portal) when false. */
  open: boolean;
  /** Called when the user dismisses the modal (ESC, overlay click, close button). */
  onClose: () => void;
  /** Heading rendered inside the dialog; used as its accessible name. Required. */
  title: string;
  /** Optional supporting copy; wired as aria-describedby. */
  description?: string;
  children: ReactNode;
  size?: "sm" | "md" | "lg";
  /** Accessible label of the close button. */
  closeLabel?: string;
  /** When false, ESC / overlay click do nothing; only the close button closes. */
  dismissible?: boolean;
  /** Where focus goes on open: first focusable element, the close button, or nowhere. */
  initialFocus?: "first" | "close" | "none";
}

/**
 * Accessible modal dialog for batch-1 plugins (Email Capture Popup, …).
 *
 * - `role="dialog"`, `aria-modal="true"`, labelled by its title.
 * - Focus trap while open; focus returns to the trigger on close.
 * - ESC / overlay click close when `dismissible` (default true).
 * - Body scroll is locked while open and restored to its previous value.
 * - Rendered through a portal into `document.body`; SSR-safe.
 */
export function Modal({
  open,
  onClose,
  title,
  description,
  children,
  size = "md",
  closeLabel = "Close",
  dismissible = true,
  initialFocus = "first",
}: ModalProps) {
  const titleId = useId();
  const descriptionId = useId();
  const panelRef = useRef<HTMLDivElement | null>(null);
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    setMounted(true);
  }, []);

  useFocusTrap(panelRef, open);
  useBodyScrollLock(open);
  useFocusReturn(open);

  useEffect(() => {
    if (!open || initialFocus === "none") return;
    const target =
      initialFocus === "close"
        ? panelRef.current?.querySelector<HTMLElement>("[data-modal-close]")
        : focusableElements(panelRef.current)[0];
    target?.focus();
  }, [open, initialFocus]);

  useEffect(() => {
    if (!open || !dismissible) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault();
        onClose();
      }
    };
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [open, dismissible, onClose]);

  if (!open || !mounted) return null;

  return createPortal(
    <div className="ui-modal-root">
      <div
        className="ui-modal-overlay"
        aria-hidden="true"
        onClick={dismissible ? onClose : undefined}
      />
      <div
        className={`ui-modal ui-modal--${size}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        {...(description ? { "aria-describedby": descriptionId } : {})}
        ref={panelRef}
      >
        <div className="ui-modal-head">
          <h2 id={titleId} className="ui-modal-title">
            {title}
          </h2>
        </div>
        {description ? (
          <p id={descriptionId} className="ui-modal-description">
            {description}
          </p>
        ) : null}
        <div className="ui-modal-body">{children}</div>
        {/*
          Close button is last in DOM (absolute, top-right) so Tab reaches the
          form/content first and the close button last. initialFocus="first"
          therefore focuses the first field, not the close button.
        */}
        <button
          type="button"
          className="ui-modal-close"
          onClick={onClose}
          aria-label={closeLabel}
          data-modal-close
        >
          ×
        </button>
      </div>
    </div>,
    document.body,
  );
}
