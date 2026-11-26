"use client";

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { tagsService } from "@/services/tags.service";
import type { components } from "@/types/api";
import { toast } from "@/hooks/use-toast";
import { isRetryableQueryError, reconcileMutationError, retryDelay } from "@/services/api-errors";
import { idempotencyKey } from "@/services/merchant.service";
import { scopedQueryKey } from "@/services/query-keys";

export function useTags(params?: Record<string, unknown>) {
  const query = useQuery({
    queryKey: scopedQueryKey("tags", params),
    queryFn: () => tagsService.list(params),
    retry: isRetryableQueryError,
    retryDelay,
  });

  return {
    tags: Array.isArray(query.data?.data) ? query.data.data : [],
    meta: query.data?.meta,
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}

export function useTag(id: number) {
  return useQuery({
    queryKey: scopedQueryKey("tags", id),
    queryFn: () => tagsService.get(id),
    enabled: !!id,
    retry: isRetryableQueryError,
    retryDelay,
  });
}

export function useCreateTag() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: components["schemas"]["CreateTagRequest"]) => tagsService.create(data, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["tags"] });
      toast({ title: "Tag created", description: "Tag has been created successfully." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "tags", error);
      toast({ title: "Error", description: "Failed to create tag.", variant: "destructive" });
    },
  });
}

export function useUpdateTag(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: components["schemas"]["UpdateTagRequest"]) => tagsService.update(id, data, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["tags"] });
      toast({ title: "Tag updated", description: "Tag has been updated successfully." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "tags", error);
      toast({ title: "Error", description: "Failed to update tag.", variant: "destructive" });
    },
  });
}

export function useDeleteTag() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => tagsService.delete(id, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["tags"] });
      toast({ title: "Tag deleted", description: "Tag has been deleted successfully." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "tags", error);
      toast({ title: "Error", description: "Failed to delete tag.", variant: "destructive" });
    },
  });
}
