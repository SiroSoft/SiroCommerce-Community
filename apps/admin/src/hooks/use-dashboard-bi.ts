"use client";

import { useQuery } from "@tanstack/react-query";
import { dashboardBiService } from "@/services/dashboard-bi.service";
import { scopedQueryKey } from "@/services/query-keys";
import { isRetryableQueryError, retryDelay } from "@/services/api-errors";
import type { DashboardBiRange } from "@/types/dashboard";

export function useDashboardBi(range: DashboardBiRange) {
  const query = useQuery({
    queryKey: scopedQueryKey("dashboard-bi", range),
    queryFn: () => dashboardBiService.getBi(range),
    retry: isRetryableQueryError,
    retryDelay,
  });

  return {
    data: query.data,
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
    isRefetching: query.isRefetching,
  };
}
