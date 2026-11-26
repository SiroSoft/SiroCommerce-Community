import api from "./api";

export type CampaignAutomationSettings = { enabled: boolean; max_messages_per_day: number; quiet_hours_start: number; quiet_hours_end: number; allowed_channels: string[]; updated_at?: string | null };

export const campaignAutomationService = {
  async get() { const response = await api.get<{ data: CampaignAutomationSettings }>("/api/v2/admin/campaign-automation"); return response.data.data; },
  async update(data: CampaignAutomationSettings, idempotencyKey: string) { const response = await api.put<{ data: CampaignAutomationSettings }>("/api/v2/admin/campaign-automation", data, { idempotencyKey }); return response.data.data; },
};
