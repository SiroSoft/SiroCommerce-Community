export interface AuthTokens { token: string; refresh_token: string; token_type?: string; expires_in?: number; }
export interface AuthStorage { getAccessToken(): string | null; setAccessToken(token: string): void; getRefreshToken(): string | null; setTokens(tokens: AuthTokens): void; clear(): void; dispose?(): void; }
export interface BrowserAuthStorageOptions { validateAccessToken?: (token: string) => boolean; storage?: Storage | null; }
export interface AuthTransport { refresh(refreshToken: string, signal?: AbortSignal): Promise<AuthTokens>; logout?(accessToken?: string): Promise<void>; }

export function isValidJwt(value: unknown, now = Date.now()): value is string {
  if (typeof value !== "string") return false;
  const parts = value.split(".");
  if (parts.length !== 3 || parts.some((part) => !part)) return false;
  try {
    const encoded = parts[1]!.replace(/-/g, "+").replace(/_/g, "/").padEnd(Math.ceil(parts[1]!.length / 4) * 4, "=");
    const payload = JSON.parse(typeof atob === "function" ? atob(encoded) : Buffer.from(encoded, "base64").toString("utf8")) as { exp?: unknown };
    return typeof payload === "object" && payload !== null &&
      typeof payload.exp === "number" && Number.isFinite(payload.exp) && payload.exp > 0 && payload.exp * 1000 > now;
  } catch { return false; }
}

export function browserAuthStorage(prefix = "siro_", options: BrowserAuthStorageOptions = {}): AuthStorage {
  const validateAccessToken = options.validateAccessToken ?? isValidJwt;
  let access: string | null = null;
  const storage = (): Storage | null => options.storage ?? (typeof window === "undefined" ? null : window.localStorage);
  const read = (key: string) => { try { return storage()?.getItem(key) ?? null; } catch { return null; } };
  const remove = (key: string) => { try { storage()?.removeItem(key); } catch { /* Storage can be unavailable in private contexts. */ } };
  const write = (key: string, value: string) => { try { storage()?.setItem(key, value); return true; } catch { return false; } };
  const accessKey = `${prefix}access_token`;
  const refreshKey = `${prefix}refresh_token`;
  const userKey = `${prefix}user`;
  const logoutKey = `${prefix}logout_event`;
  remove(accessKey);
  const clearLocal = () => { access = null; remove(accessKey); remove(refreshKey); remove(userKey); };
  const listener = typeof window === "undefined" ? undefined : (event: StorageEvent) => {
    if (event.key === refreshKey && event.newValue === null) access = null;
    if (event.key === logoutKey && event.newValue) clearLocal();
  };
  if (listener) window.addEventListener("storage", listener);
  return {
    getAccessToken: () => access,
    setAccessToken: token => { if (validateAccessToken(token)) access = token; else clearLocal(); },
    getRefreshToken: () => { const token = read(refreshKey); if (token?.trim()) return token; if (token !== null) clearLocal(); return null; },
    setTokens: tokens => { if (!isValidJwt(tokens.token) || typeof tokens.refresh_token !== "string" || !tokens.refresh_token.trim()) throw new Error("Invalid auth tokens"); access = tokens.token; remove(accessKey); write(refreshKey, tokens.refresh_token); },
    clear: () => { write(logoutKey, `${Date.now()}-${Math.random().toString(36).slice(2)}`); clearLocal(); },
    dispose: () => { if (listener) window.removeEventListener("storage", listener); },
  };
}

export class AuthSessionCoordinator {
  private refreshFlight?: Promise<boolean>;
  private generation = 0;
  private readonly channel?: BroadcastChannel;
  constructor(private readonly storage: AuthStorage, private readonly transport: AuthTransport) {
    if (typeof BroadcastChannel !== "undefined") { this.channel = new BroadcastChannel("siro-auth"); this.channel.onmessage = event => { if (event.data === "logout" || event.data?.type === "logout") this.storage.clear(); }; }
  }
  get accessToken(): string | undefined { return this.storage.getAccessToken() ?? undefined; }
  setTokens(tokens: AuthTokens): void { this.generation++; this.storage.setTokens(tokens); }
  async refresh(signal?: AbortSignal): Promise<boolean> {
    this.refreshFlight ??= this.doRefresh(signal).finally(() => { this.refreshFlight = undefined; });
    return this.refreshFlight;
  }
  private async doRefresh(signal?: AbortSignal): Promise<boolean> {
    const token = this.storage.getRefreshToken();
    const generation = this.generation;
    if (!token) return false;
    try {
      const tokens = await this.transport.refresh(token, signal);
      if (generation !== this.generation || this.storage.getRefreshToken() !== token) return false;
       if (!tokens?.token || !tokens.refresh_token || !isValidJwt(tokens.token)) throw new Error("Invalid refresh response");
      this.storage.setTokens(tokens);
      return true;
    } catch {
      if (generation === this.generation && this.storage.getRefreshToken() === token) this.storage.clear();
      return false;
    }
  }
  async logout(): Promise<void> { const generation = ++this.generation; const token = this.accessToken; try { await this.transport.logout?.(token); } finally { if (generation === this.generation) this.storage.clear(); this.channel?.postMessage({ type: "logout" }); } }
  dispose(): void { this.channel?.close(); this.storage.dispose?.(); }
}
