import type { components } from "@/types/api";

type MerchantUser =
  | (Pick<components["schemas"]["User"], "role"> & { permissions?: readonly string[] | null })
  | null
  | undefined;

type BackendRole = "owner" | "admin" | "marketer" | "warehouse" | "support" | "finance" | "viewer";

export const ROLE_CAPABILITIES: Readonly<Record<BackendRole, readonly string[]>> = {
  owner: ["*"],
  admin: ["products", "orders", "inventory", "customers", "coupons", "campaigns", "seo", "cms", "users", "reports", "search", "analytics", "exports", "operations", "subscriptions", "ai_suggestions", "admin_workbench"],
  marketer: ["products", "coupons", "campaigns", "seo", "cms", "analytics", "ai_suggestions"],
  warehouse: ["inventory", "orders", "operations"],
  support: ["orders", "customers", "operations", "subscriptions"],
  finance: ["finance", "invoices", "payments", "refunds", "credit_memos", "exports", "operations", "subscriptions", "ai_suggestions"],
  viewer: [],
};

const LEGACY_CAPABILITY_ALIASES: Readonly<Record<string, string>> = {
  b2b: "admin_workbench",
  vendors: "admin_workbench",
};

const KNOWN_CAPABILITIES: ReadonlySet<string> = new Set(
  Object.values(ROLE_CAPABILITIES).flat().filter((capability) => capability !== "*"),
);

export type BackendCapability =
  | "products" | "orders" | "inventory" | "customers" | "coupons" | "seo"
  | "cms" | "users" | "reports" | "search" | "analytics" | "exports" | "campaigns"
  | "finance" | "invoices" | "payments" | "refunds" | "credit_memos" | "operations"
  | "subscriptions" | "ai_suggestions" | "admin_workbench";

export function normalizeCapability(capability: string): BackendCapability | null {
  const raw = capability.trim().toLowerCase();
  const candidate = LEGACY_CAPABILITY_ALIASES[raw] ?? raw;
  return KNOWN_CAPABILITIES.has(candidate) ? (candidate as BackendCapability) : null;
}

export function serverPermissions(user: MerchantUser): readonly string[] | null {
  return Array.isArray(user?.permissions) ? user.permissions : null;
}

export function roleCapabilities(role: string | null | undefined): readonly string[] {
  if (!role) return [];
  return ROLE_CAPABILITIES[role.toLowerCase() as BackendRole] ?? [];
}

function roleHasCapability(role: string, capability: BackendCapability): boolean {
  const capabilities = roleCapabilities(role);
  if (capabilities.includes("*") || capabilities.includes(capability)) return true;
  return role === "finance" && (capability === "reports" || capability === "search");
}

export function canOperateMerchant(user: MerchantUser, capability: BackendCapability | string): boolean {
  const requested = normalizeCapability(capability);
  if (!requested) return false;

  const role = user?.role?.toLowerCase();
  if (!role || !(role in ROLE_CAPABILITIES)) return false;

  const permissions = serverPermissions(user);
  if (permissions !== null) {
    return permissions.some((permission) => {
      if (typeof permission !== "string") return false;
      if (permission === "*") return true;
      return normalizeCapability(permission) === requested;
    });
  }

  return roleHasCapability(role, requested);
}

export function canUseRoute(user: MerchantUser, roles: readonly string[]): boolean {
  const role = user?.role?.toLowerCase();
  return !!role && roles.includes(role);
}
