import { scopeCoordinator } from "./api";

export function commerceScopeKey(): string {
  const scope = scopeCoordinator.value;
  return scope ? `${scope.store_id}:${scope.channel_id}:${scope.locale}:${scope.currency}` : "unscoped";
}

export function scopedQueryKey(resource: string, ...parts: unknown[]) {
  return [resource, commerceScopeKey(), ...parts] as const;
}
