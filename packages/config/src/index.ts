export const API_VERSION = "v1" as const;
export const API_ACCEPT = "application/json";
export const DEFAULT_TIMEOUT_MS = 30_000;

export interface PlatformConfig {
  baseUrl: string;
  feToken?: string;
  locale?: string;
  apiVersion?: string;
}

export function createPlatformConfig(baseUrl: string, overrides: Partial<PlatformConfig> = {}): PlatformConfig {
  return { baseUrl: baseUrl.replace(/\/$/, ""), apiVersion: API_VERSION, ...overrides };
}
