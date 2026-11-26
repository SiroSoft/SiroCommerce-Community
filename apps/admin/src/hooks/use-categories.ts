"use client";

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { categoriesService } from "@/services/categories.service";
import type { components } from "@/types/api";
import { toast } from "@/hooks/use-toast";
import { isRetryableQueryError, reconcileMutationError, retryDelay } from "@/services/api-errors";
import { idempotencyKey } from "@/services/merchant.service";
import { scopedQueryKey } from "@/services/query-keys";

export function useCategories(params?: Record<string, unknown>) {
  const query = useQuery({
    queryKey: scopedQueryKey("categories", params),
    queryFn: () => categoriesService.list(params),
    retry: isRetryableQueryError,
    retryDelay,
  });

  return {
    categories: Array.isArray(query.data?.data) ? query.data.data : [],
    meta: query.data?.meta,
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}

export function useCategory(id: number) {
  return useQuery({
    queryKey: scopedQueryKey("categories", id),
    queryFn: () => categoriesService.get(id),
    enabled: !!id,
    retry: isRetryableQueryError,
    retryDelay,
  });
}

export function useCreateCategory() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: components["schemas"]["CreateCategoryRequest"]) => categoriesService.create(data, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["categories"] });
      toast({ title: "Category created", description: "Category has been created successfully." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "categories", error);
      toast({ title: "Error", description: "Failed to create category.", variant: "destructive" });
    },
  });
}

export function useUpdateCategory(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: components["schemas"]["UpdateCategoryRequest"]) => categoriesService.update(id, data, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["categories"] });
      toast({ title: "Category updated", description: "Category has been updated successfully." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "categories", error);
      toast({ title: "Error", description: "Failed to update category.", variant: "destructive" });
    },
  });
}

export function useDeleteCategory() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => categoriesService.delete(id, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["categories"] });
      toast({ title: "Category deleted", description: "Category has been deleted successfully." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "categories", error);
      toast({ title: "Error", description: "Failed to delete category.", variant: "destructive" });
    },
  });
}
