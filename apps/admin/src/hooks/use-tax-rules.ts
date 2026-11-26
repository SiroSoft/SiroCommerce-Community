"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { taxRulesService, type TaxRule, type TaxRuleInput } from "@/services/tax-rules.service";
import { toast } from "@/hooks/use-toast";
import { isRetryableQueryError, reconcileMutationError, retryDelay } from "@/services/api-errors";
import { scopedQueryKey } from "@/services/query-keys";

const QUERY_KEY = "tax-rules";

export function useTaxRules(params?: Record<string, unknown>) {
  const query = useQuery({
    queryKey: scopedQueryKey(QUERY_KEY, params),
    queryFn: () => taxRulesService.list(params),
    retry: isRetryableQueryError,
    retryDelay,
  });

  return {
    rules: Array.isArray(query.data) ? (query.data as TaxRule[]) : [],
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}

export function useCreateTaxRule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: TaxRuleInput) => taxRulesService.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [QUERY_KEY] });
      toast({ title: "Tax rule created", description: "The rule applies on the next quote." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, QUERY_KEY, error);
      toast({ title: "Error", description: "Failed to create tax rule.", variant: "destructive" });
    },
  });
}

export function useUpdateTaxRule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<TaxRuleInput> }) => taxRulesService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [QUERY_KEY] });
      toast({ title: "Tax rule updated", description: "The rule changes are now authoritative." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, QUERY_KEY, error);
      toast({ title: "Error", description: "Failed to update tax rule.", variant: "destructive" });
    },
  });
}

export function useDeleteTaxRule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => taxRulesService.remove(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [QUERY_KEY] });
      toast({ title: "Tax rule deleted", description: "The engine ignores the removed rule." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, QUERY_KEY, error);
      toast({ title: "Error", description: "Failed to delete tax rule.", variant: "destructive" });
    },
  });
}
