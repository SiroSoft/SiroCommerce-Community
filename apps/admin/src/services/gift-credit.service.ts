import api from "./api";
import { idempotencyKey } from "./merchant.service";

export type GiftCredit = {
  id: string;
  code: string;
  owner_customer_id: string | null;
  type: string;
  amount_minor: number;
  balance_minor: number;
  currency: string;
  state: string;
  expires_at: string | null;
};

type Envelope<T> = { data: T };
const unwrap = <T>(response: { data: Envelope<T> | T }): T => {
  const body = response.data as Envelope<T>;
  return body && typeof body === "object" && "data" in body ? body.data : (response.data as T);
};

export const giftCreditService = {
  async list(params?: { customer_id?: string; type?: string }) {
    return unwrap(await api.get<Envelope<GiftCredit[]> | GiftCredit[]>("/api/admin/gift-cards", { params }));
  },
  async issue(input: { amount_minor: number; currency?: string; owner_customer_id?: string; code?: string; type?: string; expires_at?: string }) {
    if (!Number.isInteger(input.amount_minor) || input.amount_minor <= 0) throw new Error("Amount must be a positive integer in minor units.");
    return unwrap(await api.post<Envelope<GiftCredit> | GiftCredit>("/api/admin/gift-cards", input, { idempotencyKey: idempotencyKey() }));
  },
  async void(id: string) {
    return unwrap(await api.post<Envelope<GiftCredit> | GiftCredit>(`/api/admin/gift-cards/${encodeURIComponent(id)}/void`, {}, { idempotencyKey: idempotencyKey() }));
  },
};
