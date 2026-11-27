export interface LiveRegionProps {
  /** Message announced to screen readers. Empty message renders nothing. */
  message: string;
  politeness?: "polite" | "assertive";
  /** When true the message is also visible on screen; default false (sr-only). */
  visible?: boolean;
  id?: string;
}

/**
 * Screen-reader announcement region for form feedback inside popups
 * (subscribe success / error, add-to-cart confirmation, …).
 * `role="status"` + `aria-live`; visually hidden unless `visible`.
 */
export function LiveRegion({ message, politeness = "polite", visible = false, id }: LiveRegionProps) {
  if (!message) return null;
  return (
    <p
      {...(id ? { id } : {})}
      role="status"
      aria-live={politeness}
      className={visible ? "ui-live-region" : "ui-live-region sr-only"}
    >
      {message}
    </p>
  );
}
