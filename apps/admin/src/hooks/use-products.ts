"use client";

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { productsService } from "@/services/products.service";
import type { components } from "@/types/api";
import { toast } from "@/hooks/use-toast";
import { reconcileMutationError } from "@/services/api-errors";
import { isRetryableQueryError, retryDelay } from "@/services/api-errors";
import { idempotencyKey } from "@/services/merchant.service";
import { scopedQueryKey } from "@/services/query-keys";

export function useProducts(params?: Record<string, unknown>) {
  const query = useQuery({
    queryKey: scopedQueryKey("products", params),
    queryFn: () => productsService.list(params),
    retry: isRetryableQueryError,
    retryDelay,
  });

  return {
    products: Array.isArray(query.data?.data) ? query.data.data : [],
    meta: query.data?.meta,
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}

export function useProduct(id: number) {
  return useQuery({
    queryKey: scopedQueryKey("products", id),
    queryFn: () => productsService.get(id),
    enabled: !!id,
    retry: isRetryableQueryError,
    retryDelay,
  });
}

export function useCreateProduct() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: components["schemas"]["CreateProductRequest"]) => productsService.create(data, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["products"] });
      toast({ title: "Product created", description: "Product has been created successfully." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "products", error);
      toast({ title: "Error", description: "Failed to create product.", variant: "destructive" });
    },
  });
}

export function useUpdateProduct(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: components["schemas"]["UpdateProductRequest"]) => productsService.update(id, data, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["products"] });
      toast({ title: "Product updated", description: "Product has been updated successfully." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "products", error);
      toast({ title: "Error", description: "Failed to update product.", variant: "destructive" });
    },
  });
}

export function useDeleteProduct() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => productsService.delete(id, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["products"] });
      toast({ title: "Product deleted", description: "Product has been deleted successfully." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "products", error);
      toast({ title: "Error", description: "Failed to delete product.", variant: "destructive" });
    },
  });
}

export function useImportProducts() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: { file?: File; csv?: string; dryRun?: boolean }) => productsService.importCsv(input, idempotencyKey()),
    onSuccess: (result) => {
      if (!result.dry_run) queryClient.invalidateQueries({ queryKey: ["products"] });
      toast({
        title: result.dry_run ? "Dry run complete" : "Product import complete",
        description: `${result.created} created, ${result.updated} updated, ${result.skipped} skipped, ${result.errors.length} error(s).`,
        variant: result.errors.length ? "destructive" : "default",
      });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "products", error);
      toast({ title: "Error", description: "Failed to import product CSV.", variant: "destructive" });
    },
  });
}

export function useTransitionProduct() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, state, version }: { id: number; state: "draft" | "scheduled" | "published" | "archived"; version?: number }) => productsService.transition(id, state, version, idempotencyKey()),
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({ queryKey: ["products"] });
      toast({ title: variables.state === "archived" ? "Product archived" : "Product restored", description: "The product lifecycle state was updated.", variant: "success" });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "products", error);
      toast({ title: "Error", description: "The lifecycle change was not accepted. Refresh before retrying.", variant: "destructive" });
    },
  });
}
