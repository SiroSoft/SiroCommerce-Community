import api, { fetchWithAuth } from "./api";
import { idempotencyKey } from "./merchant.service";

export type ExtensionItem = {
  id: string;
  version: string;
  manifest_version: string;
  state: string;
  last_error: string | null;
  capabilities: string[];
  permissions: string[];
  migrations: string[];
  routes: string[];
  requires: Record<string, string>;
  admin_ui: { label: string; section: string; href: string; panels: ExtensionPanel[] } | null;
};

export type ExtensionPanel = { key: string; title: string; type: "stats" | "table"; endpoint: string; permission: string };
export type ExtensionView = { id: string; label: string; section: string; href: string; panels: ExtensionPanel[] };

export type ExtensionPackageReport = {
  ok: boolean;
  errors: string[];
  warnings: string[];
  id?: string;
  version?: string;
  signed?: boolean;
  files?: number;
};

export type ExtensionQualityCheck = {
  name: string;
  ok: boolean;
  details: string[];
};

export type ExtensionQualityReport = {
  ok: boolean;
  checks: ExtensionQualityCheck[];
};

export type ExtensionLicenseSource = "env" | "storage" | "none";

export type ExtensionLicenseStatus = {
  ok: boolean;
  edition: string;
  license_id: string | null;
  extensions: string[];
  features: string[];
  expires_at: string | null;
  grace_until: string | null;
  reason: string;
  source: ExtensionLicenseSource;
  stored: boolean;
};

export type ProArtifact = {
  id?: string;
  version?: string;
  available?: boolean;
  filename?: string | null;
  sha256?: string | null;
  size?: number | null;
  signed?: boolean;
  reason?: string | null;
  download_token?: string;
  download_expires_in?: number;
};

export type ProFeedItem = {
  id: string;
  type?: CatalogItemType;
  name?: string;
  description?: string;
  edition?: CatalogItemEdition;
  latest_version?: string;
  installed_version?: string | null;
  installed_state?: string;
  update_available?: boolean;
  compatible?: boolean;
  license_sku?: string | null;
  license_status?: CatalogLicenseStatus;
  entitlement?: "valid" | "grace" | "missing";
  signature?: string | null;
  artifact?: ProArtifact | null;
  installable?: boolean;
  can_update?: boolean;
  action_reason?: string | null;
  tags?: string[];
};

export type ProFeed = {
  schema?: string;
  generated_at?: string;
  count?: number;
  items?: ProFeedItem[];
};

export type CatalogItemType = "plugin" | "theme";
export type CatalogItemEdition = "free" | "pro";
export type CatalogLicenseStatus = "not_required" | "valid" | "missing" | "expired" | "grace";

export type CatalogItem = {
  id: string;
  type: CatalogItemType;
  name: string;
  description: string;
  vendor: string;
  edition: CatalogItemEdition;
  status: string;
  latest_version: string;
  installed_version: string | null;
  installed_state: string;
  update_available: boolean;
  compatible: boolean;
  min_core: string | null;
  min_commerce: string | null;
  license_required: boolean;
  license_sku: string | null;
  license_status: CatalogLicenseStatus;
  artifact_url: string | null;
  sha256: string | null;
  signature?: string | null;
  preview_url: string | null;
  changelog_url: string | null;
  changelog: string | null;
  installable: boolean;
  can_update: boolean;
  action_reason: string | null;
  tags: string[];
};

export type EcosystemCatalog = {
  schema: string;
  generated_at: string;
  items: CatalogItem[];
};

export type EcosystemUpdates = {
  items: CatalogItem[];
};

export type CatalogQuery = {
  type?: CatalogItemType | "all";
  edition?: CatalogItemEdition | "all";
  q?: string;
};

type Envelope<T> = { data: T };
const unwrap = <T>(response: { data: Envelope<T> | T }): T => {
  const body = response.data as Envelope<T>;
  return body && typeof body === "object" && "data" in body ? body.data : (response.data as T);
};

const CATALOG_TYPES = ["plugin", "theme", "all"] as const;
const CATALOG_EDITIONS = ["free", "pro", "all"] as const;

function catalogType(value: CatalogQuery["type"]): (typeof CATALOG_TYPES)[number] {
  return CATALOG_TYPES.includes(value as (typeof CATALOG_TYPES)[number]) ? (value as (typeof CATALOG_TYPES)[number]) : "all";
}

function catalogEdition(value: CatalogQuery["edition"]): (typeof CATALOG_EDITIONS)[number] {
  return CATALOG_EDITIONS.includes(value as (typeof CATALOG_EDITIONS)[number]) ? (value as (typeof CATALOG_EDITIONS)[number]) : "all";
}

function catalogSearch(value: string | undefined): string | undefined {
  const trimmed = (value ?? "").replace(/[\u0000-\u001f\u007f]/g, "").trim();
  return trimmed ? trimmed.slice(0, 200) : undefined;
}

// Adapter seam for forthcoming generated OpenAPI methods. Extension ids
// contain slashes (vendor/name), so the id travels in the body, never path.
const PRO_ARTIFACT_DOWNLOAD_ERRORS: Record<number, string> = {
  403: "A valid Pro license is required to download this artifact.",
  404: "This Pro artifact is not available.",
  409: "This Pro artifact could not be verified. Try again later.",
  410: "This Pro artifact download link has expired.",
};

export const extensionsService = {
  async list() {
    return unwrap(await api.get<Envelope<ExtensionItem[]> | ExtensionItem[]>("/api/admin/extensions"));
  },
  async views() {
    return unwrap(await api.get<Envelope<ExtensionView[]> | ExtensionView[]>("/api/admin/extensions/ui"));
  },
  async license() {
    return unwrap(await api.get<Envelope<ExtensionLicenseStatus> | ExtensionLicenseStatus>("/api/admin/extensions/license"));
  },
  async importLicense(licenseText: string) {
    const parsed = JSON.parse(licenseText) as unknown;
    if (parsed === null || typeof parsed !== "object" || Array.isArray(parsed)) {
      throw new Error("License must be a JSON object.");
    }
    return unwrap(await api.post<Envelope<ExtensionLicenseStatus> | ExtensionLicenseStatus>(
      "/api/admin/extensions/license/import",
      { license: parsed },
      { idempotencyKey: idempotencyKey() },
    ));
  },
  async removeLicense() {
    return unwrap(await api.delete<Envelope<ExtensionLicenseStatus> | ExtensionLicenseStatus>(
      "/api/admin/extensions/license",
      { idempotencyKey: idempotencyKey() },
    ));
  },
  async proFeed() {
    return unwrap(await api.get<Envelope<ProFeed> | ProFeed>("/api/admin/ecosystem/pro-feed"));
  },
  async proInstall(id: string, version?: string) {
    return unwrap(await api.post<Envelope<unknown> | unknown>(
      "/api/admin/ecosystem/pro/install",
      version ? { id, version } : { id },
      { idempotencyKey: idempotencyKey() },
    ));
  },
  async proUpdate(id: string, version?: string) {
    return unwrap(await api.post<Envelope<unknown> | unknown>(
      "/api/admin/ecosystem/pro/update",
      version ? { id, version } : { id },
      { idempotencyKey: idempotencyKey() },
    ));
  },
  async proArtifactDownload(token: string): Promise<Blob> {
    const response = await fetchWithAuth("/api/admin/ecosystem/pro-artifacts/download", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ token }),
    });
    if (!response.ok) {
      throw new Error(PRO_ARTIFACT_DOWNLOAD_ERRORS[response.status] ?? "The Pro artifact download failed.");
    }
    return response.blob();
  },
  async catalog(params: CatalogQuery = {}) {
    const query: Record<string, string> = {
      type: catalogType(params.type),
      edition: catalogEdition(params.edition),
    };
    const q = catalogSearch(params.q);
    if (q) query.q = q;
    return unwrap(await api.get<Envelope<EcosystemCatalog> | EcosystemCatalog>("/api/admin/ecosystem/catalog", { params: query }));
  },
  async updates() {
    return unwrap(await api.get<Envelope<EcosystemUpdates> | EcosystemUpdates>("/api/admin/ecosystem/updates"));
  },
  async installFromCatalog(id: string) {
    return unwrap(await api.post<Envelope<unknown> | unknown>("/api/admin/ecosystem/install", { id }, { idempotencyKey: idempotencyKey() }));
  },
  async updateFromCatalog(id: string) {
    return unwrap(await api.post<Envelope<unknown> | unknown>("/api/admin/ecosystem/update", { id }, { idempotencyKey: idempotencyKey() }));
  },
  async panel(extensionId: string, endpoint: string) {
    const clean = endpoint.replace(/^\/+/, "");
    if (!clean || clean.includes("..") || /^[a-z][a-z0-9+.-]*:\/\//i.test(clean)) throw new Error("Panel endpoint is invalid.");
    return unwrap(await api.get<Envelope<unknown> | unknown>(`/api/ext/${extensionId}/${clean}`));
  },
  async install(id: string) {
    return unwrap(await api.post<Envelope<unknown> | unknown>("/api/admin/extensions/install", { id }, { idempotencyKey: idempotencyKey() }));
  },
  async enable(id: string) {
    return unwrap(await api.post<Envelope<unknown> | unknown>("/api/admin/extensions/enable", { id }, { idempotencyKey: idempotencyKey() }));
  },
  async disable(id: string) {
    return unwrap(await api.post<Envelope<unknown> | unknown>("/api/admin/extensions/disable", { id }, { idempotencyKey: idempotencyKey() }));
  },
  async uninstall(id: string, removeData: boolean) {
    return unwrap(await api.post<Envelope<unknown> | unknown>("/api/admin/extensions/uninstall", { id, remove_data: removeData }, { idempotencyKey: idempotencyKey() }));
  },
  async health(id: string) {
    return unwrap(await api.post<Envelope<unknown> | unknown>("/api/admin/extensions/health", { id }));
  },
  async upgrade(id: string) {
    return unwrap(await api.post<Envelope<unknown> | unknown>("/api/admin/extensions/upgrade", { id }, { idempotencyKey: idempotencyKey() }));
  },
  async verifyPackage(file: File) {
    const body = new FormData();
    body.append("package", file);
    const response = await fetchWithAuth("/api/admin/extensions/package/verify", { method: "POST", headers: { "Idempotency-Key": idempotencyKey() }, body });
    const json = await response.json().catch(() => ({})) as { data?: ExtensionPackageReport; message?: string };
    if (!response.ok) throw new Error(json.message ?? "Package verification failed");
    return json.data as ExtensionPackageReport;
  },
  async qitPackage(file: File) {
    const body = new FormData();
    body.append("package", file);
    const response = await fetchWithAuth("/api/admin/extensions/package/qit", { method: "POST", headers: { "Idempotency-Key": idempotencyKey() }, body });
    const json = await response.json().catch(() => ({})) as { data?: ExtensionQualityReport; message?: string };
    if (!response.ok) throw new Error(json.message ?? "Quality gate failed to run");
    return (json.data ?? { ok: false, checks: [] }) as ExtensionQualityReport;
  },
  async installPackage(file: File, force: boolean) {
    const body = new FormData();
    body.append("package", file);
    body.append("force", force ? "1" : "0");
    const response = await fetchWithAuth("/api/admin/extensions/package/install", { method: "POST", headers: { "Idempotency-Key": idempotencyKey() }, body });
    const json = await response.json().catch(() => ({})) as { data?: { id?: string; version?: string; path?: string }; message?: string };
    if (!response.ok) throw new Error(json.message ?? "Package installation failed");
    return json.data ?? {};
  },
};
