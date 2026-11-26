"use client";

import { useAuthStore } from "@/store/auth.store";
import { canOperateMerchant, canUseRoute, type BackendCapability } from "@/modules/merchant/permissions";
import { PermissionState } from "@/modules/merchant/api-state";

export function CapabilityGate({ capability, children }: { capability: BackendCapability; children: React.ReactNode }) {
  const user = useAuthStore((state) => state.user);
  if (!canOperateMerchant(user, capability)) return <PermissionState />;
  return <>{children}</>;
}

export function RoleGate({ roles, children }: { roles: readonly string[]; children: React.ReactNode }) {
  const user = useAuthStore((state) => state.user);
  if (!canUseRoute(user, roles)) return <PermissionState />;
  return <>{children}</>;
}
