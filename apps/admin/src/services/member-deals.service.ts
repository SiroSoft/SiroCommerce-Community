import api from "./api";
import { idempotencyKey } from "./merchant.service";

export type MemberDeal = {
  id: string;
  name: string;
  kind: string;
  min_tier: string | null;
  percent_off: number;
  starts_at: string | null;
  ends_at: string | null;
  state: string;
};

type Envelope<T> = { data: T };
const unwrap = <T>(response: { data: Envelope<T> | T }): T => {
  const body = response.data as Envelope<T>;
  return body && typeof body === "object" && "data" in body ? body.data : (response.data as T);
};

export const memberDealsService = {
  async list() {
    return unwrap(await api.get<Envelope<MemberDeal[]> | MemberDeal[]>("/api/admin/member-deals"));
  },
  async create(input: { name: string; kind?: string; min_tier?: string; percent_off: number; starts_at?: string; ends_at?: string }) {
    if (!input.name.trim()) throw new Error("Name is required.");
    if (!Number.isInteger(input.percent_off) || input.percent_off < 1 || input.percent_off > 90) throw new Error("Percent must be 1..90.");
    return unwrap(await api.post<Envelope<MemberDeal> | MemberDeal>("/api/admin/member-deals", input, { idempotencyKey: idempotencyKey() }));
  },
  async update(id: string, input: { name?: string; min_tier?: string; percent_off?: number; starts_at?: string; ends_at?: string; state?: string }) {
    return unwrap(await api.put<Envelope<MemberDeal> | MemberDeal>(`/api/admin/member-deals/${encodeURIComponent(id)}`, input, { idempotencyKey: idempotencyKey() }));
  },
};
