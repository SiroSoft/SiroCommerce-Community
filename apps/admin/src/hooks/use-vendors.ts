"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { vendorsService, type VendorInput, type VendorPayoutInput } from "@/services/vendors.service";
import { idempotencyKey } from "@/services/merchant.service";
import { reconcileMutationError } from "@/services/api-errors";
import { toast } from "@/hooks/use-toast";
import { scopedQueryKey } from "@/services/query-keys";

export function useVendors(params?: Record<string, unknown>) {
  const query = useQuery({
    queryKey: scopedQueryKey("vendors", params),
    queryFn: () => vendorsService.list(params),
  });
  return {
    vendors: Array.isArray(query.data?.data) ? query.data.data : [],
    meta: query.data?.meta,
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}

export function useCreateVendor() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: VendorInput) => vendorsService.create(data, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["vendors"] });
      toast({ title: "Vendor created", description: "The vendor is now available for product assignment." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "vendors", error);
      toast({ title: "Error", description: error instanceof Error ? error.message : "Failed to create vendor.", variant: "destructive" });
    },
  });
}

export function useUpdateVendor() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<VendorInput> }) => vendorsService.update(id, data, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["vendors"] });
      queryClient.invalidateQueries({ queryKey: ["products"] });
      toast({ title: "Vendor updated", description: "The vendor profile was saved." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "vendors", error);
      toast({ title: "Error", description: error instanceof Error ? error.message : "Failed to update vendor.", variant: "destructive" });
    },
  });
}

export function useVendorLedger(vendorId?: string, params?: Record<string, unknown>) {
  const query = useQuery({
    queryKey: scopedQueryKey("vendor-ledger", vendorId, params),
    queryFn: () => vendorsService.ledger(vendorId as string, params),
    enabled: Boolean(vendorId),
  });
  return {
    ledger: query.data,
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}

export function useVendorPayouts(vendorId?: string) {
  const query = useQuery({
    queryKey: scopedQueryKey("vendor-payouts", vendorId),
    queryFn: () => vendorsService.payouts(vendorId as string),
    enabled: Boolean(vendorId),
  });
  return {
    payouts: Array.isArray(query.data) ? query.data : [],
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}

function invalidateVendorFinance(queryClient: ReturnType<typeof useQueryClient>, vendorId: string) {
  queryClient.invalidateQueries({ queryKey: ["vendor-ledger"] });
  queryClient.invalidateQueries({ queryKey: ["vendor-payouts"] });
  queryClient.invalidateQueries({ queryKey: ["vendors"] });
  void queryClient.invalidateQueries({ queryKey: scopedQueryKey("vendor-ledger", vendorId) });
}

export function useCreateVendorPayout(vendorId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: VendorPayoutInput) => vendorsService.createPayout(vendorId, data, idempotencyKey()),
    onSuccess: () => {
      invalidateVendorFinance(queryClient, vendorId);
      toast({ title: "Payout batch created", description: "Available entries in the window are now pending settlement." });
    },
    onError: (error) => {
      toast({ title: "Error", description: error instanceof Error ? error.message : "Failed to create payout.", variant: "destructive" });
    },
  });
}

export function useMarkVendorPayoutPaid(vendorId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payoutId: string) => vendorsService.markPayoutPaid(payoutId),
    onSuccess: () => {
      invalidateVendorFinance(queryClient, vendorId);
      toast({ title: "Payout marked paid", description: "The batch and its ledger entries are settled." });
    },
    onError: (error) => {
      toast({ title: "Error", description: error instanceof Error ? error.message : "Failed to mark payout paid.", variant: "destructive" });
    },
  });
}

export function useCancelVendorPayout(vendorId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payoutId: string) => vendorsService.cancelPayout(payoutId),
    onSuccess: () => {
      invalidateVendorFinance(queryClient, vendorId);
      toast({ title: "Payout cancelled", description: "The ledger entries are available for a future batch." });
    },
    onError: (error) => {
      toast({ title: "Error", description: error instanceof Error ? error.message : "Failed to cancel payout.", variant: "destructive" });
    },
  });
}

export function useReconcileVendorPayouts(vendorId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ csv, dryRun = false }: { csv: string; dryRun?: boolean }) => vendorsService.reconcilePayouts(csv, idempotencyKey(), dryRun),
    onSuccess: (result) => {
      invalidateVendorFinance(queryClient, vendorId);
      toast({
        title: result.ok ? "Payouts reconciled" : "Reconciled with errors",
        description: `${result.paid} paid · ${result.failed} failed · ${result.returned} returned · ${result.skipped} skipped · ${result.errors} errors`,
        variant: result.ok ? undefined : "destructive",
      });
    },
    onError: (error) => {
      toast({ title: "Error", description: error instanceof Error ? error.message : "Failed to reconcile payouts.", variant: "destructive" });
    },
  });
}

export function useDeleteVendor() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => vendorsService.delete(id, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["vendors"] });
      toast({ title: "Vendor deleted", description: "The vendor was removed." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "vendors", error);
      toast({ title: "Error", description: error instanceof Error ? error.message : "Failed to delete vendor.", variant: "destructive" });
    },
  });
}
