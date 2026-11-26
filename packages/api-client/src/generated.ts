// Generated contract projection. Keep synchronized with backend/generated/sirocommerce.ts.
export type ApiVersion = "v1" | "v2";
export type QueryValue = string | number | boolean | null | undefined;
export interface ApiError { success: false; message: string; meta: { timestamp: string; error_code?: string; errors?: Record<string, string[]>; [key: string]: unknown }; }
export interface ApiEnvelope<T> { success: true; message: string; data: T; meta: Record<string, unknown>; }
export interface PaginatedApiEnvelope<T> { success: true; message: string; data: T[]; meta: PaginationMeta; }
export interface PaginationMeta { page: number; per_page: number; total: number; last_page: number; timestamp?: string; }
export interface ApiRequestOptions<TBody = unknown> { token?: string; headers?: Record<string, string>; query?: Record<string, QueryValue>; path?: Record<string, string | number>; body?: TBody; idempotencyKey?: string; signal?: AbortSignal; }
export interface InventoryAdjustRequest { on_hand: number; source_id?: string; }
export interface InventoryTransferRequest { from_source_id: string; to_source_id: string; qty: number; reason?: string; }
export interface FulfillmentAllocateRequest { order_id: string; lines: Array<Record<string, unknown>>; }
export interface FulfillmentShipRequest { lines?: Array<Record<string, unknown>>; }
export interface OrderTransitionRequest { to: string; reason?: string; }
export interface PaymentReconcileRequest { apply?: boolean; }
export interface InventoryItem { variant_id: string; source_id: string; on_hand: number; reserved: number; available?: number; low_threshold?: number; }
export interface InventoryTransfer { id: string; variant_id: string; from_source_id: string; to_source_id: string; qty: number; state: string; idempotency_key?: string; }
export interface WriteResult { id?: string; state?: string; status?: string; message?: string; ok?: boolean; queued?: boolean; count?: number; created_at?: string; }
