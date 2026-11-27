/**
 * Shared UI primitives for batch-1 plugins (Wave 1, Experience lane).
 * Import from "@/components/ui-primitives" — do NOT re-implement modal /
 * popover / badge / sticky-bar behaviour inside a plugin.
 */
export { Modal } from "./Modal";
export type { ModalProps } from "./Modal";
export { Popover } from "./Popover";
export type { PopoverProps } from "./Popover";
export { Badge } from "./Badge";
export type { BadgeProps, BadgeKind, BadgeTone } from "./Badge";
export { StickyBar } from "./StickyBar";
export type { StickyBarProps } from "./StickyBar";
export { LiveRegion } from "./LiveRegion";
export type { LiveRegionProps } from "./LiveRegion";
export {
  FOCUSABLE_SELECTOR,
  focusableElements,
  useBodyScrollLock,
  useFocusReturn,
  useFocusTrap,
  usePrefersReducedMotion,
} from "./focus";
