"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { subscriptionsService, type SubscriptionPlanInput } from "@/services/subscriptions.service";
import { idempotencyKey } from "@/services/merchant.service";
import { reconcileMutationError } from "@/services/api-errors";
import { toast } from "@/hooks/use-toast";
import { scopedQueryKey } from "@/services/query-keys";

export function useSubscriptionPlans(params: { state?: string } = {}) {
  const query = useQuery({
    queryKey: scopedQueryKey("subscription-plans", params),
    queryFn: () => subscriptionsService.plans(params),
  });
  return {
    plans: Array.isArray(query.data) ? query.data : [],
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}

export function useCreateSubscriptionPlan() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: SubscriptionPlanInput) => subscriptionsService.createPlan(data, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["subscription-plans"] });
      toast({ title: "Plan created", description: "The subscription plan is available to link to products." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "subscription-plans", error);
      toast({ title: "Error", description: error instanceof Error ? error.message : "Failed to create the plan.", variant: "destructive" });
    },
  });
}

export function useUpdateSubscriptionPlan() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: SubscriptionPlanInput }) => subscriptionsService.updatePlan(id, data, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["subscription-plans"] });
      queryClient.invalidateQueries({ queryKey: ["products"] });
      toast({ title: "Plan updated", description: "The subscription plan was saved." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "subscription-plans", error);
      toast({ title: "Error", description: error instanceof Error ? error.message : "Failed to update the plan.", variant: "destructive" });
    },
  });
}

export function useDeleteSubscriptionPlan() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => subscriptionsService.deletePlan(id, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["subscription-plans"] });
      toast({ title: "Plan deleted", description: "The subscription plan was removed." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "subscription-plans", error);
      toast({ title: "Error", description: error instanceof Error ? error.message : "Failed to delete the plan. Plans referenced by subscriptions cannot be removed.", variant: "destructive" });
    },
  });
}
