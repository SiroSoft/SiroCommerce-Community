"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { promotionRulesService, type PromotionRule, type PromotionRuleInput } from "@/services/promotion-rules.service";
import { toast } from "@/hooks/use-toast";
import { isRetryableQueryError, reconcileMutationError, retryDelay } from "@/services/api-errors";
import { scopedQueryKey } from "@/services/query-keys";

const QUERY_KEY = "promotion-rules";

export function usePromotionRules() {
  const query = useQuery({
    queryKey: scopedQueryKey(QUERY_KEY),
    queryFn: () => promotionRulesService.list(),
    retry: isRetryableQueryError,
    retryDelay,
  });

  return {
    rules: Array.isArray(query.data) ? (query.data as PromotionRule[]) : [],
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}

export function useCreatePromotionRule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: PromotionRuleInput) => promotionRulesService.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [QUERY_KEY] });
      toast({ title: "Price rule created", description: "The rule will be evaluated on the next quote." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, QUERY_KEY, error);
      toast({ title: "Error", description: "Failed to create price rule.", variant: "destructive" });
    },
  });
}

export function useUpdatePromotionRule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<PromotionRuleInput> }) => promotionRulesService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [QUERY_KEY] });
      toast({ title: "Price rule updated", description: "The rule changes are now authoritative." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, QUERY_KEY, error);
      toast({ title: "Error", description: "Failed to update price rule.", variant: "destructive" });
    },
  });
}

export function useDeletePromotionRule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => promotionRulesService.remove(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [QUERY_KEY] });
      toast({ title: "Price rule deleted", description: "The rule no longer participates in quotes." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, QUERY_KEY, error);
      toast({ title: "Error", description: "Failed to delete price rule.", variant: "destructive" });
    },
  });
}
