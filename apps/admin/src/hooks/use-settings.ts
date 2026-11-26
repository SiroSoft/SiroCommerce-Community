"use client";

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { settingsService } from "@/services/settings.service";
import type { components } from "@/types/api";
import { toast } from "@/hooks/use-toast";
import { isRetryableQueryError, reconcileMutationError, retryDelay } from "@/services/api-errors";
import { idempotencyKey } from "@/services/merchant.service";
import { scopedQueryKey } from "@/services/query-keys";

export function useSettings() {
  return useQuery({
    queryKey: scopedQueryKey("settings"),
    queryFn: () => settingsService.get(),
    retry: isRetryableQueryError,
    retryDelay,
  });
}

export function useUpdateSettings() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: components["schemas"]["UpdateSettingsRequest"]) => settingsService.update(data, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["settings"] });
      toast({ title: "Settings updated", description: "Settings have been updated." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "settings", error);
      toast({ title: "Error", description: "Failed to update settings.", variant: "destructive" });
    },
  });
}
