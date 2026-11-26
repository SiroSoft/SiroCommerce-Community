import api from "./api";
import type {
  FulfillmentAllocateRequest,
  FulfillmentShipRequest,
  InventoryAdjustRequest,
  InventoryItem,
  InventoryTransfer,
  InventoryTransferRequest,
  WriteResult,
} from "@sirocommerce/api-client/generated";

type Envelope<T> = { data: T };

export const merchantService = {
  async actionCenter() {
    const response = await api.get<Envelope<unknown>>("/api/admin/smart/action-center");
    return response.data.data;
  },
  async inventory(variant: string) {
    const response = await api.get<Envelope<InventoryItem>>(`/api/admin/inventory/${encodeURIComponent(variant)}`);
    return response.data.data;
  },
  async inventorySources(variant: string) {
    const response = await api.get<Envelope<Record<string, unknown>[]>>(`/api/admin/inventory/${encodeURIComponent(variant)}/sources`);
    return (response.data.data ?? []) as Record<string, unknown>[];
  },
  async adjustInventory(variant: string, data: InventoryAdjustRequest, idempotencyKey: string) {
    const response = await api.put<Envelope<InventoryItem>>(`/api/admin/inventory/${encodeURIComponent(variant)}`, data, { idempotencyKey });
    return response.data.data;
  },
  async transferInventory(variant: string, data: InventoryTransferRequest, idempotencyKey: string) {
    const response = await api.post<Envelope<InventoryTransfer>>(`/api/admin/inventory/${encodeURIComponent(variant)}/transfer`, data, { idempotencyKey });
    return response.data.data;
  },
  async allocate(data: FulfillmentAllocateRequest, idempotencyKey: string) {
    const response = await api.post<Envelope<WriteResult>>("/api/admin/fulfillment/allocate", data, { idempotencyKey });
    return response.data.data;
  },
  async ship(id: string, data: FulfillmentShipRequest | undefined, idempotencyKey: string) {
    const response = await api.post<Envelope<WriteResult>>(`/api/admin/fulfillment/${encodeURIComponent(id)}/ship`, data, { idempotencyKey });
    return response.data.data;
  },
  async deliver(id: string, idempotencyKey: string) {
    const response = await api.post<Envelope<WriteResult>>(`/api/admin/fulfillment/${encodeURIComponent(id)}/deliver`, undefined, { idempotencyKey });
    return response.data.data;
  },
  async markReady(id: string, pickupPointCode: string | undefined, idempotencyKey: string) {
    const response = await api.post<Envelope<WriteResult>>(`/api/admin/fulfillment/${encodeURIComponent(id)}/ready`, pickupPointCode ? { pickup_point_code: pickupPointCode } : {}, { idempotencyKey });
    return response.data.data;
  },
  async collect(id: string, idempotencyKey: string) {
    const response = await api.post<Envelope<WriteResult>>(`/api/admin/fulfillment/${encodeURIComponent(id)}/collect`, undefined, { idempotencyKey });
    return response.data.data;
  },
  async cancelFulfillment(id: string, idempotencyKey: string) {
    const response = await api.post<Envelope<WriteResult>>(`/api/admin/fulfillment/${encodeURIComponent(id)}/cancel`, undefined, { idempotencyKey });
    return response.data.data;
  },
  async track(id: string) {
    const response = await api.get<Envelope<unknown>>(`/api/admin/fulfillment/${encodeURIComponent(id)}/track`);
    return response.data.data;
  },
};

export function idempotencyKey() {
  return crypto.randomUUID();
}
