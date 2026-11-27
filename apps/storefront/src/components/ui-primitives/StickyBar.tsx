"use client";

import { useEffect } from "react";

export interface StickyBarProps {
  /** Product (or offer) name. Rendered on one line with ellipsis. */
  title: string;
  /** Pre-formatted price string (e.g. "$68.00"). The primitive never computes money. */
  price?: string;
  /** Label of the primary action button. */
  actionLabel: string;
  onAction: () => void;
  actionDisabled?: boolean;
  actionBusy?: boolean;
  /** Label shown while `actionBusy` instead of `actionLabel`. Defaults to `actionLabel`. */
  actionBusyLabel?: string;
  /** When false nothing renders (no bar, no page padding). Default true. */
  visible?: boolean;
  /** Accessible name of the bar region. Defaults to `title`. */
  ariaLabel?: string;
  /** Optional secondary action (e.g. "Details"). Only rendered with `onSecondary`. */
  secondaryLabel?: string;
  onSecondary?: () => void;
}

/**
 * Fixed bottom action bar for the Sticky ATC plugin (`pdp.below-atc` slot).
 *
 * - `role="region"` with an accessible name; the action button is a real
 *   `<button>` with `min-height: 44px` and full-width behaviour on mobile.
 * - Never covers page content: while visible it adds the
 *   `ui-has-sticky-bar` class to `<html>`, and CSS reserves exactly the bar
 *   height (`--ui-sticky-bar-height`, safe-area aware) as `body` bottom
 *   padding. The class is removed on hide/unmount.
 * - Height is deterministic by design (single-line title/price, fixed
 *   paddings), so the reserved space always matches the bar.
 * - The lane controls `visible` (e.g. show only after the main ATC scrolls
 *   out of view, or only on small screens) — the primitive itself is
 *   viewport-agnostic.
 */
export function StickyBar({
  title,
  price,
  actionLabel,
  onAction,
  actionDisabled = false,
  actionBusy = false,
  actionBusyLabel,
  visible = true,
  ariaLabel,
  secondaryLabel,
  onSecondary,
}: StickyBarProps) {
  useEffect(() => {
    if (typeof document === "undefined") return;
    document.documentElement.classList.toggle("ui-has-sticky-bar", visible);
    return () => {
      document.documentElement.classList.remove("ui-has-sticky-bar");
    };
  }, [visible]);

  if (!visible) return null;

  return (
    <div className="ui-sticky-bar" role="region" aria-label={ariaLabel ?? title}>
      <div className="ui-sticky-bar-info">
        <p className="ui-sticky-bar-title">{title}</p>
        {price ? <p className="ui-sticky-bar-price">{price}</p> : null}
      </div>
      {secondaryLabel && onSecondary ? (
        <button type="button" className="ui-sticky-bar-secondary" onClick={onSecondary}>
          {secondaryLabel}
        </button>
      ) : null}
      <button
        type="button"
        className="button ui-sticky-bar-action"
        onClick={onAction}
        disabled={actionDisabled || actionBusy}
        aria-busy={actionBusy || undefined}
      >
        {actionBusy ? (actionBusyLabel ?? actionLabel) : actionLabel}
      </button>
    </div>
  );
}
