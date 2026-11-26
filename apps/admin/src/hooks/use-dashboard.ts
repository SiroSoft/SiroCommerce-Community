"use client";

import { useQuery } from "@tanstack/react-query";
import { dashboardService } from "@/services/dashboard.service";
import { scopedQueryKey } from "@/services/query-keys";
import { isRetryableQueryError, retryDelay } from "@/services/api-errors";

export function useDashboard() {
  const query = useQuery({
    queryKey: scopedQueryKey("dashboard"),
    queryFn: () => dashboardService.getStats(),
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
