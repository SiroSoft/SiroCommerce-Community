"use client";

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ordersService } from "@/services/orders.service";
import type { components } from "@/types/api";
import { toast } from "@/hooks/use-toast";
import { idempotencyKey } from "@/services/merchant.service";
import { isRetryableQueryError, reconcileMutationError, retryDelay } from "@/services/api-errors";
import { scopedQueryKey } from "@/services/query-keys";
import { dedupeIds } from "@/modules/merchant/contracts";

export function useOrders(params?: Record<string, unknown>) {
  const query = useQuery({
    queryKey: scopedQueryKey("orders", params),
    queryFn: () => ordersService.list(params),
    retry: isRetryableQueryError,
    retryDelay,
  });

  return {
    orders: Array.isArray(query.data?.data) ? query.data.data.filter((order, index, all) => order.id == null || all.findIndex((item) => item.id === order.id) === index) : [],
    meta: query.data?.meta,
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}

export function useOrder(id: number) {
  return useQuery({
    queryKey: scopedQueryKey("orders", id),
    queryFn: () => ordersService.get(id),
    enabled: !!id,
    retry: isRetryableQueryError,
    retryDelay,
  });
}

export function useCreateOrder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: components["schemas"]["CreateOrderRequest"]) => ordersService.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["orders"] });
      toast({ title: "Order created", description: "Order has been created successfully." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "orders", error);
      toast({ title: "Error", description: "Failed to create order.", variant: "destructive" });
    },
  });
}

export function useUpdateOrder(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: components["schemas"]["UpdateOrderRequest"]) => ordersService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["orders"] });
      toast({ title: "Order updated", description: "Order has been updated successfully." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "orders", error);
      toast({ title: "Error", description: "Failed to update order.", variant: "destructive" });
    },
  });
}

export function useOrderTransition(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (to: components["schemas"]["Order"]["status"]) => {
      if (!to) throw new Error("A target status is required");
      return ordersService.transition(id, { to }, idempotencyKey());
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "orders", error);
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ["orders"] }),
         queryClient.refetchQueries({ queryKey: scopedQueryKey("orders", id) }),
      ]);
    },
  });
}

export function useCancelOrder(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (reason?: string) => ordersService.cancel(id, reason ? { reason } : {}, idempotencyKey()),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["orders"] }),
    onError: (error) => reconcileMutationError(queryClient, "orders", error),
  });
}

export function useRefundOrder(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ amount_minor, reason }: { amount_minor: number; reason?: string }) => ordersService.refund(id, { amount_minor, reason }, idempotencyKey()),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["orders"] }),
    onError: (error) => reconcileMutationError(queryClient, "orders", error),
  });
}

export function useRefundRemaining(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (reason?: string) => ordersService.refundRemaining(id, reason, idempotencyKey()),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["orders"] }),
    onError: (error) => reconcileMutationError(queryClient, "orders", error),
  });
}

export function useRefundToCredit(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (reason?: string) => ordersService.refundToCredit(id, reason, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["orders"] });
      queryClient.invalidateQueries({ queryKey: ["gift-credits"] });
    },
    onError: (error) => reconcileMutationError(queryClient, "orders", error),
  });
}

export function useBulkOrderTransition() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ ids, to, useBulkEndpoint = false }: { ids: number[]; to: components["schemas"]["Order"]["status"]; useBulkEndpoint?: boolean }) => {
      const uniqueIds = dedupeIds(ids);
      if (!uniqueIds.length || !to) throw new Error("Select at least one order and a target status");
      if (useBulkEndpoint) {
        const result = await ordersService.bulkTransition({ ids: uniqueIds.map(String), to: String(to) }, idempotencyKey());
        if (result.failed.length) {
          throw Object.assign(new Error(`${result.failed.length} of ${uniqueIds.length} order transitions failed`), {
            partialFailure: result.failed.length < uniqueIds.length,
            failures: result.failed,
          });
        }
        return result;
      }
      // Roles without the admin_workbench capability keep the per-order path.
      const results = await Promise.allSettled(uniqueIds.map((id) => ordersService.transition(id, { to }, idempotencyKey())));
      const failed = results.filter((result): result is PromiseRejectedResult => result.status === "rejected");
      if (failed.length) throw Object.assign(new Error(`${failed.length} of ${uniqueIds.length} order transitions failed`), { partialFailure: failed.length < uniqueIds.length });
      const succeeded = results.filter((result): result is PromiseFulfilledResult<Awaited<ReturnType<typeof ordersService.transition>>> => result.status === "fulfilled").map((result) => result.value);
      return { succeeded, failed: [] };
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["orders"] }),
    onError: (error) => {
      // A partial bulk write must be reconciled from the server before another attempt.
      reconcileMutationError(queryClient, "orders", error);
    },
  });
}

export function useDeleteOrder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => ordersService.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["orders"] });
      toast({ title: "Order deleted", description: "Order has been deleted successfully." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "orders", error);
      toast({ title: "Error", description: "Failed to delete order.", variant: "destructive" });
    },
  });
}
