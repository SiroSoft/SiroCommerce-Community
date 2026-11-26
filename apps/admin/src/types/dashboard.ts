import type { components } from "@/types/api";

export type DashboardStats = NonNullable<components["schemas"]["DashboardStatsResponse"]>;
export type ActivityItem = NonNullable<components["schemas"]["ActivityItem"]>;
export type ApiStatus = NonNullable<components["schemas"]["ApiStatus"]>;
export type DashboardBi = components["schemas"]["DashboardBi"];
export type DashboardBiSalesBlock = components["schemas"]["DashboardBiSalesBlock"];
export type DashboardBiRange = DashboardBi["range"];
