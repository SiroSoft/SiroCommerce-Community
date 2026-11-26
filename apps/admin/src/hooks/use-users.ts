"use client";

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { usersService } from "@/services/users.service";
import type { components } from "@/types/api";
import { toast } from "@/hooks/use-toast";
import { isRetryableQueryError, reconcileMutationError, retryDelay } from "@/services/api-errors";
import { idempotencyKey } from "@/services/merchant.service";
import { scopedQueryKey } from "@/services/query-keys";

export function useUsers(params?: Record<string, unknown>) {
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: scopedQueryKey("users", params),
    queryFn: () => usersService.list(params),
    retry: isRetryableQueryError,
    retryDelay,
  });

  return {
    users: Array.isArray(query.data?.data) ? query.data.data.filter((user, index, all) => user.id == null || all.findIndex((item) => item.id === user.id) === index) : [],
    meta: query.data?.meta,
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}

export function useUser(id: number) {
  return useQuery({
    queryKey: scopedQueryKey("users", id),
    queryFn: () => usersService.get(id),
    enabled: !!id,
    retry: isRetryableQueryError,
    retryDelay,
  });
}

export function useCreateUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data: components["schemas"]["CreateUserRequest"]) => usersService.create(data, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["users"] });
      toast({ title: "User created", description: "User has been created successfully." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "users", error);
      toast({ title: "Error", description: "Failed to create user.", variant: "destructive" });
    },
  });
}

export function useUpdateUser(id: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data: components["schemas"]["UpdateUserRequest"]) => usersService.update(id, data, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["users"] });
      toast({ title: "User updated", description: "User has been updated successfully." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "users", error);
      toast({ title: "Error", description: "Failed to update user.", variant: "destructive" });
    },
  });
}

export function useDeleteUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => usersService.delete(id, idempotencyKey()),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["users"] });
      toast({ title: "User deleted", description: "User has been deleted successfully." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "users", error);
      toast({ title: "Error", description: "Failed to delete user.", variant: "destructive" });
    },
  });
}
