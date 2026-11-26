"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { merchantService, idempotencyKey } from "@/services/merchant.service";
import { isRetryableQueryError, reconcileMutationError, retryDelay } from "@/services/api-errors";
import { scopedQueryKey } from "@/services/query-keys";

export function useActionCenter() {
  return useQuery({ queryKey: scopedQueryKey("merchant", "action-center"), queryFn: merchantService.actionCenter, retry: isRetryableQueryError, retryDelay });
}

export function useInventory(variant: string) {
  return useQuery({
    queryKey: scopedQueryKey("merchant", "inventory", variant),
    queryFn: () => merchantService.inventory(variant),
    enabled: variant.trim().length > 0,
    retry: isRetryableQueryError,
    retryDelay,
  });
}

export function useInventorySources(variant: string) {
  return useQuery({
    queryKey: scopedQueryKey("merchant", "inventory-sources", variant),
    queryFn: () => merchantService.inventorySources(variant),
    enabled: variant.trim().length > 0,
    retry: isRetryableQueryError,
    retryDelay,
  });
}

export function useFulfillmentTrack(id: string) {
  return useQuery({
    queryKey: scopedQueryKey("merchant", "fulfillment", id),
    queryFn: () => merchantService.track(id),
    enabled: id.trim().length > 0,
    retry: isRetryableQueryError,
    retryDelay,
  });
}

export function useAllocateFulfillment() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: Parameters<typeof merchantService.allocate>[0]) => merchantService.allocate(data, idempotencyKey()),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["merchant"] }),
    onError: (error) => { reconcileMutationError(queryClient, "merchant", error); },
  });
}

export function useFulfillmentAction(action: "ship" | "deliver") {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id }: { id: string }) => action === "ship"
      ? merchantService.ship(id, undefined, idempotencyKey())
      : merchantService.deliver(id, idempotencyKey()),
    onSuccess: (_data, variables) => queryClient.invalidateQueries({ queryKey: ["merchant"] }),
    onError: (error) => { reconcileMutationError(queryClient, "merchant", error); },
  });
}

export function useCancelFulfillment() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id }: { id: string }) => merchantService.cancelFulfillment(id, idempotencyKey()),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["merchant"] }),
    onError: (error) => reconcileMutationError(queryClient, "merchant", error),
  });
}

export function useInventoryMutation(action: "adjust" | "transfer", variant: string) {
  const queryClient = useQueryClient();
  return useMutation<unknown, unknown, Parameters<typeof merchantService.adjustInventory>[1] | Parameters<typeof merchantService.transferInventory>[1]>({
    mutationFn: (data: Parameters<typeof merchantService.adjustInventory>[1] | Parameters<typeof merchantService.transferInventory>[1]) =>
      action === "adjust"
        ? merchantService.adjustInventory(variant, data as Parameters<typeof merchantService.adjustInventory>[1], idempotencyKey())
        : merchantService.transferInventory(variant, data as Parameters<typeof merchantService.transferInventory>[1], idempotencyKey()),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["merchant"] }),
    onError: (error) => { reconcileMutationError(queryClient, "merchant", error); },
  });
}
