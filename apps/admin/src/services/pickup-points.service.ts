import api from "./api";
import { idempotencyKey } from "./merchant.service";

export type PickupPoint = {
  code: string;
  name: string;
  source_id: string;
  address_line: string | null;
  city: string | null;
  sla_hours: number;
  state: string;
};

type Envelope<T> = { data: T };
const unwrap = <T>(response: { data: Envelope<T> | T }): T => {
  const body = response.data as Envelope<T>;
  return body && typeof body === "object" && "data" in body ? body.data : (response.data as T);
};

export const pickupPointsService = {
  async list() {
    return unwrap(await api.get<Envelope<PickupPoint[]> | PickupPoint[]>("/api/pickup-points"));
  },
  async create(input: { code: string; name: string; source_id?: string; address_line?: string; city?: string; sla_hours?: number }) {
    if (!input.code.trim() || !input.name.trim()) throw new Error("Code and name are required.");
    return unwrap(await api.post<Envelope<PickupPoint> | PickupPoint>("/api/admin/pickup-points", input, { idempotencyKey: idempotencyKey() }));
  },
  async update(code: string, input: { name?: string; source_id?: string; address_line?: string; city?: string; sla_hours?: number; state?: string }) {
    return unwrap(await api.put<Envelope<PickupPoint> | PickupPoint>(`/api/admin/pickup-points/${encodeURIComponent(code)}`, input, { idempotencyKey: idempotencyKey() }));
  },
};
