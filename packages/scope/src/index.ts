export interface CommerceScope {
  store_id: string;
  channel_id: string;
  locale: string;
  currency: string;
}

export type ScopeKey = keyof CommerceScope;

export function createCommerceScope(value: CommerceScope): CommerceScope {
  for (const key of ["store_id", "channel_id", "locale", "currency"] as const) {
    if (!value[key]) throw new Error(`CommerceScope.${key} is required`);
  }
  return Object.freeze({ ...value });
}

export function scopeHeaders(scope?: CommerceScope): Record<string, string> {
  return scope
    ? {
        "X-Store-Id": scope.store_id,
        "X-Channel-Id": scope.channel_id,
        "X-Locale": scope.locale,
        "X-Currency": scope.currency,
      }
    : {};
}

export class ScopeCoordinator {
  private current?: CommerceScope;
  private generation = 0;

  get value(): CommerceScope | undefined { return this.current; }
  get version(): number { return this.generation; }

  set(scope: CommerceScope): number {
    this.current = createCommerceScope(scope);
    return ++this.generation;
  }

  clear(): number { this.current = undefined; return ++this.generation; }

  isCurrent(version: number): boolean { return version === this.generation; }
  capture(): { scope?: CommerceScope; version: number } { return { scope: this.current ? createCommerceScope(this.current) : undefined, version: this.generation }; }
}
