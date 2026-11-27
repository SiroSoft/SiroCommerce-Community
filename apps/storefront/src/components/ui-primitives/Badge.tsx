export type BadgeKind = "new" | "sale" | "best-seller" | "custom";
export type BadgeTone = "accent" | "success" | "danger" | "neutral";

export interface BadgeProps {
  kind: BadgeKind;
  /** Visible text. Required — a badge with no text is a layout bug, not a style. */
  label: string;
  /** Only used when `kind="custom"`. */
  tone?: BadgeTone;
  size?: "sm" | "md";
  className?: string;
}

const KIND_TONE: Record<Exclude<BadgeKind, "custom">, BadgeTone> = {
  new: "accent",
  sale: "danger",
  "best-seller": "success",
};

/**
 * Product label badge for the `product-card` / `pdp.title` slots.
 *
 * - Colour comes from the kind (or `tone` for `custom`) via theme tokens only;
 *   no free-form colours, so labels can never break the store theme.
 * - Text truncates with an ellipsis and the badge never grows past its
 *   container (`max-width: 100%`), so it cannot cover card content.
 * - Plain `<span>`: it is text, not a control — no tab stop, no click handler.
 */
export function Badge({ kind, label, tone, size = "md", className }: BadgeProps) {
  const resolvedTone = kind === "custom" ? (tone ?? "accent") : KIND_TONE[kind];
  return (
    <span
      className={["ui-badge", `ui-badge--${resolvedTone}`, `ui-badge--${size}`, className]
        .filter(Boolean)
        .join(" ")}
      data-kind={kind}
    >
      {label}
    </span>
  );
}
