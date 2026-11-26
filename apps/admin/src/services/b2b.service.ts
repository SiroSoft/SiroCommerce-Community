import api from "./api";

export type B2bRecord = Record<string, unknown>;

const unwrap = <T>(value: { data?: T }) => value.data;
const idempotency = (operation: string, id: string, body?: unknown) =>
  `${operation}_${id}_${JSON.stringify(body ?? {})}`.slice(0, 128);

export const b2bService = {
  async createCompany(body: { name: string; status?: string }) {
    const response = await api.post<{ data?: B2bRecord }>("/api/admin/b2b/companies", body, {
      idempotencyKey: idempotency("company", body.name),
    });
    return unwrap(response.data);
  },
  async addMember(companyId: string, body: { user_id: number; role: string }) {
    const response = await api.post<{ data?: B2bRecord }>(`/api/admin/b2b/companies/${encodeURIComponent(companyId)}/members`, body, {
      idempotencyKey: idempotency("member", companyId, body),
    });
    return unwrap(response.data);
  },
  async setApprovalScope(companyId: string, body: { scope: string; channel_id?: string; approval_limit_minor?: number }) {
    const response = await api.post<{ data?: B2bRecord }>(`/api/admin/b2b/companies/${encodeURIComponent(companyId)}/approval-scopes`, body, {
      idempotencyKey: idempotency("approval-scope", companyId, body),
    });
    return unwrap(response.data);
  },
  async createCatalog(companyId: string, body: { code: string; name: string; currency?: string; scope?: string }) {
    const response = await api.post<{ data?: B2bRecord }>(`/api/admin/b2b/companies/${encodeURIComponent(companyId)}/catalogs`, body, {
      idempotencyKey: idempotency("catalog", companyId, body),
    });
    return unwrap(response.data);
  },
  async addCatalogItem(companyId: string, catalog: string, body: { variant_id: string; minimum_qty?: number }) {
    const response = await api.post<{ data?: B2bRecord }>(`/api/admin/b2b/companies/${encodeURIComponent(companyId)}/catalogs/${encodeURIComponent(catalog)}/items`, body, {
      idempotencyKey: idempotency("catalog-item", `${companyId}_${catalog}`, body),
    });
    return unwrap(response.data);
  },
  async setNegotiatedPrice(companyId: string, body: { variant_id: string; currency?: string; unit_price_minor: number; minimum_qty?: number }) {
    const response = await api.post<{ data?: B2bRecord }>(`/api/admin/b2b/companies/${encodeURIComponent(companyId)}/negotiated-prices`, body, {
      idempotencyKey: idempotency("negotiated-price", companyId, body),
    });
    return unwrap(response.data);
  },
  async credit(companyId: string) {
    const response = await api.get<{ data?: B2bRecord }>(`/api/admin/b2b/companies/${encodeURIComponent(companyId)}/credit`);
    return unwrap(response.data);
  },
  async createQuote(companyId: string, body: { currency: string; lines: Record<string, never>[]; expires_at?: string; purchase_order_number?: string; payment_terms_days?: number }) {
    const response = await api.post<{ data?: B2bRecord }>(`/api/v2/b2b/companies/${encodeURIComponent(companyId)}/quotes`, body, {
      idempotencyKey: idempotency("quote", companyId, body),
    });
    return unwrap(response.data);
  },
  async quote(id: string) {
    const response = await api.get<{ data?: B2bRecord }>(`/api/v2/b2b/quotes/${encodeURIComponent(id)}`);
    return unwrap(response.data);
  },
  async quoteAction(id: string, action: "approve" | "reject") {
    const response = await api.post<{ data?: B2bRecord }>(`/api/v2/b2b/quotes/${encodeURIComponent(id)}/${action}`, undefined, {
      idempotencyKey: idempotency(`quote-${action}`, id),
    });
    return unwrap(response.data);
  },
  async convertQuote(id: string) {
    const response = await api.post<{ data?: B2bRecord }>(`/api/v2/b2b/quotes/${encodeURIComponent(id)}/convert`, {}, {
      idempotencyKey: idempotency("quote-convert", id),
    });
    return unwrap(response.data);
  },
  async approveOrder(id: string, companyApproval = false) {
    const route = companyApproval ? "approve-company" : "approve";
    const response = await api.post<{ data?: B2bRecord }>(`/api/v2/b2b/orders/${encodeURIComponent(id)}/${route}`, undefined, {
      idempotencyKey: idempotency(`order-${route}`, id),
    });
    return unwrap(response.data);
  },
  async approveContract(id: string) {
    const response = await api.post<{ data?: B2bRecord }>(`/api/v2/b2b/contracts/${encodeURIComponent(id)}/approve`, undefined, {
      idempotencyKey: idempotency("contract-approve", id),
    });
    return unwrap(response.data);
  },
  async vendors() {
    const response = await api.get<{ data?: B2bRecord[] }>("/api/v2/admin/vendors");
    return unwrap(response.data) ?? [];
  },
};
