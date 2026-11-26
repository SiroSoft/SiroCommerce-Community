"use client";

import { useCallback, useEffect, useMemo, useSyncExternalStore } from "react";
import { hasStoredSession } from "@/lib/session-hint";
import type { Product } from "@/types";

/** Customer API is only needed for signed-in sync, so keep it out of card bundles. */
async function customerApi() {
  const { customer } = await import("@/services/customer");
  return customer();
}

export type WishlistEntry = { slug: string; added_at: string };
export type WishlistProductRef = Pick<Product, "id" | "slug">;
export type WishlistToggleResult = { saved: boolean; offline: boolean };

const WISHLIST_KEY = "sirocommerce:wishlist";
const WISHLIST_EVENT = "siro:wishlist-updated";
const EMPTY_ENTRIES: WishlistEntry[] = [];
const EMPTY_STATE: WishlistState = { entries: EMPTY_ENTRIES, remoteIds: [], status: "idle", migrationSynced: false };

type WishlistState = {
  entries: WishlistEntry[];
  remoteIds: string[];
  status: "idle" | "loading" | "ready";
  migrationSynced: boolean;
};

let state: WishlistState = EMPTY_STATE;
let hydrated = false;
let booted = false;
let refreshPromise: Promise<void> | undefined;
let refreshGeneration = 0;
let sessionSignedIn: boolean | undefined;
const listeners = new Set<() => void>();

function emit(next: WishlistState): void {
  state = next;
  listeners.forEach((listener) => listener());
}

function readLocal(): WishlistEntry[] {
  if (typeof window === "undefined") return EMPTY_ENTRIES;
  try {
    const parsed = JSON.parse(window.localStorage.getItem(WISHLIST_KEY) ?? "null") as unknown;
    if (!Array.isArray(parsed)) return EMPTY_ENTRIES;
    return parsed.filter((entry): entry is WishlistEntry => {
      if (entry === null || typeof entry !== "object") return false;
      const value = entry as Partial<WishlistEntry>;
      return typeof value.slug === "string" && value.slug.trim() !== "" && typeof value.added_at === "string";
    });
  } catch {
    return EMPTY_ENTRIES;
  }
}

function writeLocal(entries: WishlistEntry[]): void {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.setItem(WISHLIST_KEY, JSON.stringify(entries));
  } catch {
    return;
  }
  window.dispatchEvent(new Event(WISHLIST_EVENT));
}

function clearLocal(): void {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.removeItem(WISHLIST_KEY);
  } catch {
    return;
  }
  window.dispatchEvent(new Event(WISHLIST_EVENT));
}

function syncFromStorage(): void {
  const entries = readLocal();
  if (JSON.stringify(entries) !== JSON.stringify(state.entries)) emit({ ...state, entries });
}

function matches(entry: WishlistEntry, product: WishlistProductRef): boolean {
  return entry.slug === product.slug || entry.slug === product.id;
}

function isSavedIn(current: WishlistState, product: WishlistProductRef): boolean {
  return current.entries.some((entry) => matches(entry, product)) || current.remoteIds.includes(product.id) || current.remoteIds.includes(product.slug);
}

function getSnapshot(): WishlistState {
  if (!hydrated && typeof window !== "undefined") {
    hydrated = true;
    const entries = readLocal();
    if (entries.length > 0) state = { ...state, entries };
  }
  return state;
}

function getServerSnapshot(): WishlistState {
  return EMPTY_STATE;
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

function isDuplicateError(error: unknown): boolean {
  if (error === null || typeof error !== "object") return false;
  return (error as { status?: unknown }).status === 409;
}

async function migrateLocalWishlist(): Promise<number> {
  const slugs = Array.from(new Set(readLocal().map((entry) => entry.slug.trim()).filter((slug) => slug !== "")));
  if (slugs.length === 0) return 0;
  const api = await customerApi();
  const stored = new Set<string>();
  for (const slug of slugs) {
    try {
      await api.addWishlist(slug);
      stored.add(slug);
    } catch (error) {
      if (isDuplicateError(error)) stored.add(slug);
    }
  }
  if (stored.size === 0) return 0;
  const current = readLocal();
  const remaining = current.filter((entry) => !stored.has(entry.slug.trim()));
  if (remaining.length === 0 && current.length > 0) clearLocal();
  else if (remaining.length !== current.length) writeLocal(remaining);
  return stored.size;
}

function refreshWishlist(): Promise<void> {
  if (typeof window === "undefined") return Promise.resolve();
  const signedIn = hasStoredSession();
  const previous = sessionSignedIn;
  const changed = previous !== undefined && previous !== signedIn;
  sessionSignedIn = signedIn;

  if (!signedIn) {
    refreshGeneration += previous === true ? 1 : 0;
    refreshPromise = undefined;
    emit({
      entries: previous === true ? EMPTY_ENTRIES : readLocal(),
      remoteIds: [],
      status: "ready",
      migrationSynced: false,
    });
    return Promise.resolve();
  }

  if (refreshPromise && !changed) return refreshPromise;
  if (changed) {
    refreshGeneration += 1;
    refreshPromise = undefined;
  }
  const generation = refreshGeneration;
  emit({ ...state, status: "loading" });

  const promise = (async () => {
    const migrated = previous === undefined || previous === false ? await migrateLocalWishlist() : 0;
    if (generation !== refreshGeneration) return;
    try {
      const items = await (await customerApi()).getWishlist();
      if (generation !== refreshGeneration) return;
      const remoteIds = items
        .map((item) => item.product_id)
        .filter((value): value is string => typeof value === "string" && value !== "");
      emit({ entries: readLocal(), remoteIds, status: "ready", migrationSynced: state.migrationSynced || migrated > 0 });
    } catch {
      if (generation !== refreshGeneration) return;
      emit({ entries: readLocal(), remoteIds: [], status: "ready", migrationSynced: state.migrationSynced || migrated > 0 });
    }
  })().finally(() => {
    if (refreshPromise === promise) refreshPromise = undefined;
  });
  refreshPromise = promise;
  return promise;
}

function boot(): void {
  if (booted || typeof window === "undefined") return;
  booted = true;
  window.addEventListener("siro:session-updated", () => void refreshWishlist());
  window.addEventListener("storage", (event) => {
    if (event.key === WISHLIST_KEY) syncFromStorage();
  });
  void refreshWishlist();
}

async function toggleWishlist(product: WishlistProductRef): Promise<WishlistToggleResult> {
  const signedInAtStart = typeof window !== "undefined" && hasStoredSession();
  if (signedInAtStart && refreshPromise) await refreshPromise.catch(() => undefined);

  const current = isSavedIn(getSnapshot(), product);
  const next = !current;
  const signedIn = typeof window !== "undefined" && hasStoredSession();

  if (!signedIn) {
    const entries = readLocal().filter((entry) => !matches(entry, product));
    const nextEntries = next ? [...entries, { slug: product.slug, added_at: new Date().toISOString() }] : entries;
    writeLocal(nextEntries);
    emit({ ...state, entries: nextEntries, status: "ready" });
    return { saved: next, offline: false };
  }

  const optimistic = next
    ? [...getSnapshot().remoteIds, product.id]
    : getSnapshot().remoteIds.filter((value) => value !== product.id && value !== product.slug);
  emit({ ...getSnapshot(), remoteIds: optimistic });

  try {
    const api = await customerApi();
    if (current) await api.removeWishlist(product.id);
    else await api.addWishlist(product.id);
    return { saved: next, offline: false };
  } catch {
    const entries = readLocal().filter((entry) => !matches(entry, product));
    const nextEntries = next ? [...entries, { slug: product.slug, added_at: new Date().toISOString() }] : entries;
    writeLocal(nextEntries);
    emit({ ...getSnapshot(), entries: nextEntries, remoteIds: optimistic, status: "ready" });
    return { saved: next, offline: true };
  }
}

export function useWishlist() {
  const current = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
  useEffect(() => {
    boot();
    syncFromStorage();
  }, []);
  const isSaved = useCallback((product: WishlistProductRef) => isSavedIn(getSnapshot(), product), [current]);
  const toggle = useCallback((product: WishlistProductRef) => toggleWishlist(product), []);
  return useMemo(() => ({ ...current, isSaved, toggle }), [current, isSaved, toggle]);
}