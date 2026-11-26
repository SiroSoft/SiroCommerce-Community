"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { storefrontThemeService, type ThemeWrite } from "@/services/storefront-theme.service";
import { isRetryableQueryError, reconcileMutationError, retryDelay } from "@/services/api-errors";
import { idempotencyKey } from "@/services/merchant.service";
import { scopedQueryKey } from "@/services/query-keys";

const key = "storefront-theme";
export function useStorefrontTheme() {
  return useQuery({ queryKey: scopedQueryKey(key, "config"), queryFn: storefrontThemeService.getConfig, retry: isRetryableQueryError, retryDelay });
}
export function useStorefrontThemeOptions() {
  return useQuery({
    queryKey: scopedQueryKey(key, "options"),
    queryFn: async () => Promise.all([storefrontThemeService.themes(), storefrontThemeService.presets()]),
    retry: isRetryableQueryError,
    retryDelay,
  });
}
export function useStorefrontRevisions() {
  return useQuery({ queryKey: scopedQueryKey(key, "revisions"), queryFn: storefrontThemeService.revisions, retry: isRetryableQueryError, retryDelay });
}
function useThemeMutation<T>(mutationFn: (value: T) => Promise<unknown>) {
  const queryClient = useQueryClient();
  return useMutation({ mutationFn, onSuccess: () => queryClient.invalidateQueries({ queryKey: [key] }), onError: (error) => reconcileMutationError(queryClient, key, error) });
}
export const useSaveStorefrontTheme = () => useThemeMutation((body: ThemeWrite) => storefrontThemeService.saveDraft(body, idempotencyKey()));
export const usePreviewStorefrontTheme = () => useThemeMutation((body: ThemeWrite) => storefrontThemeService.preview(body, idempotencyKey()));
export const useApplyStorefrontPreset = () => useThemeMutation((value: { expected_version: number; vertical_key: string; key: string }) => storefrontThemeService.applyPreset(value.expected_version, value.vertical_key, value.key));
export const usePublishStorefrontTheme = () => useThemeMutation((value: { expected_version: number; key: string }) => storefrontThemeService.publish(value.expected_version, value.key));
export const useRestoreStorefrontRevision = () => useThemeMutation((value: { revision: string; expected_version: number; key: string }) => storefrontThemeService.restore(value.revision, value.expected_version, value.key));
