import type { components } from "@/types/api";

export type OrderStatus = components["schemas"]["Order"]["status"];

export const orderTransitions: Readonly<Record<string, readonly string[]>> = {
  pending: ["confirmed", "cancelled"],
  confirmed: ["processing", "cancelled"],
  processing: ["shipped", "cancelled"],
  shipped: ["delivered"],
  delivered: [],
  cancelled: [],
  // Click & Collect lane (backend: ready_to_ship → ready_for_pickup → picked_up).
  ready_to_ship: ["shipped", "ready_for_pickup"],
  ready_for_pickup: ["picked_up"],
  picked_up: [],
};

export function canTransitionOrder(from: OrderStatus | string | null | undefined, to: OrderStatus | string | null | undefined) {
  return !!from && !!to && (orderTransitions[from] ?? []).includes(to);
}

export function dedupeIds(ids: readonly (number | null | undefined)[]) {
  return [...new Set(ids.filter((id): id is number => Number.isInteger(id)))];
}

export function inventoryAvailable(onHand: number, reserved: number, available?: number | null) {
  if (!Number.isFinite(onHand) || !Number.isFinite(reserved) || onHand < 0 || reserved < 0 || onHand < reserved) return null;
  const calculated = onHand - reserved;
  return available == null || available === calculated ? calculated : null;
}
