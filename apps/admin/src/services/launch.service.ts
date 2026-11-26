import api from "./api";
import { idempotencyKey } from "./merchant.service";

export type LaunchCheckState = "ok" | "warn" | "fail";

export interface LaunchCheck {
  key: string;
  state: LaunchCheckState;
  hint: string;
  href: string;
  weight?: number;
  detail?: string;
}

export interface LaunchStatus {
  ready: boolean;
  score: number;
  checks: LaunchCheck[];
}

export interface ProviderStatus {
  payment: { provider: string; mode: string; configured: boolean };
  shipping: { provider: string; mode: string; configured: boolean };
  mail: { provider: string; configured: boolean };
  search: { provider: string; url_configured: boolean; configured: boolean };
  ai: { provider: string; budget_configured: boolean; configured: boolean };
}

export interface LaunchSeedCounts {
  theme: number;
  categories: number;
  products: number;
  variants: number;
  inventory: number;
  prices: number;
  pages: number;
  menus: number;
  menu_items: number;
}

export interface LaunchSeedResult {
  created: LaunchSeedCounts;
  skipped: LaunchSeedCounts;
}

type Envelope<T> = { data: T };

export const launchService = {
  async status(): Promise<LaunchStatus> {
    const response = await api.get<Envelope<LaunchStatus>>("/api/admin/launch/status");
    return response.data.data;
  },
  async providers(): Promise<ProviderStatus> {
    const response = await api.get<Envelope<ProviderStatus>>("/api/admin/providers/status");
    return response.data.data;
  },
  async seedDemo(key: string = idempotencyKey()): Promise<LaunchSeedResult> {
    const response = await api.post<Envelope<LaunchSeedResult>>("/api/admin/launch/seed-demo", undefined, { idempotencyKey: key });
    return response.data.data;
  },
};
