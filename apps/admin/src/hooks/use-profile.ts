"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { profileService } from "@/services/profile.service";
import { useAuthStore } from "@/store/auth.store";
import { scopedQueryKey } from "@/services/query-keys";
import type { components } from "@/types/api";
import { toast } from "@/hooks/use-toast";
import { reconcileMutationError } from "@/services/api-errors";
import { idempotencyKey } from "@/services/merchant.service";

export function useUpdateProfile() {
  const queryClient = useQueryClient();
  const setUser = useAuthStore((s) => s.setUser);

  return useMutation({
    mutationFn: (data: components["schemas"]["UpdateProfileRequest"]) => profileService.update(data, idempotencyKey()),
    onSuccess: (user) => {
      setUser(user ?? null);
      queryClient.invalidateQueries({ queryKey: scopedQueryKey("auth", "session") });
      toast({ title: "Profile updated", description: "Your profile has been updated." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "profile", error);
      toast({ title: "Error", description: "Failed to update profile.", variant: "destructive" });
    },
  });
}

export function useChangePassword() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: components["schemas"]["ChangePasswordRequest"]) => profileService.changePassword(data, idempotencyKey()),
    onSuccess: () => {
      toast({ title: "Password changed", description: "Your password has been changed." });
    },
    onError: (error) => {
      reconcileMutationError(queryClient, "profile", error);
      toast({ title: "Error", description: "Failed to change password.", variant: "destructive" });
    },
  });
}
