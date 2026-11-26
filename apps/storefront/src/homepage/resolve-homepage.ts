import type { HomepageSection, HomepageSectionType } from "./types";
import { isHomepageSectionType } from "./registry";
const sectionTypes: HomepageSectionType[] = ["campaign_hero", "collection_directory", "editorial_story", "product_collection", "service_proof", "trust_strip", "category_links", "banner_grid", "usp_strip", "faq"];
const nestedSectionTypes = new Set<HomepageSectionType>(["banner_grid", "usp_strip", "faq"]);
export const defaultSections: HomepageSection[] = [
  { id: "campaign", type: "campaign_hero", enabled: true, order: 10, props: {} }, { id: "categories", type: "category_links", enabled: true, order: 15, props: {} }, { id: "new-edit", type: "product_collection", enabled: true, order: 20, props: { source: "tag:new" } }, { id: "collections", type: "collection_directory", enabled: true, order: 30, props: {} }, { id: "story", type: "editorial_story", enabled: true, order: 40, props: {} }, { id: "favorites", type: "product_collection", enabled: true, order: 50, props: { source: "featured" } }, { id: "proof", type: "service_proof", enabled: true, order: 60, props: {} }, { id: "trust", type: "trust_strip", enabled: true, order: 65, props: {} },
];
function sanitizeItemValue(value: unknown): string | Record<string, string> | undefined {
  if (typeof value === "string" && value.trim()) return value.trim().slice(0, 500);
  if (value && typeof value === "object" && !Array.isArray(value)) {
    const nested: Record<string, string> = {};
    for (const [key, child] of Object.entries(value as Record<string, unknown>)) {
      if (/^[a-zA-Z_]+$/.test(key) && typeof child === "string" && child.trim()) nested[key] = child.trim().slice(0, 500);
    }
    return Object.keys(nested).length > 0 ? nested : undefined;
  }
  return undefined;
}
function sanitizeItemList(value: unknown[]): Record<string, unknown>[] {
  return value.slice(0, 20).flatMap((entry) => {
    if (!entry || typeof entry !== "object" || Array.isArray(entry)) return [];
    const item: Record<string, unknown> = {};
    for (const [key, itemValue] of Object.entries(entry as Record<string, unknown>)) {
      if (!/^[a-zA-Z_]+$/.test(key)) continue;
      const clean = sanitizeItemValue(itemValue);
      if (clean !== undefined) item[key] = clean;
    }
    return Object.keys(item).length > 0 ? [item] : [];
  });
}
function sanitizeProps(raw: unknown, type: HomepageSectionType): Record<string, unknown> {
  if (!raw || typeof raw !== "object") return {};
  const props: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
    if (!/^[a-z_]+$/.test(key)) continue;
    if (typeof value === "string") props[key] = value.slice(0, 2000);
    else if (typeof value === "number" && Number.isFinite(value)) props[key] = value;
    else if (typeof value === "boolean") props[key] = value;
    else if (nestedSectionTypes.has(type) && Array.isArray(value)) props[key] = sanitizeItemList(value);
  }
  return props;
}
export function resolveHomepageSections(value: unknown): HomepageSection[] {
  if (!Array.isArray(value) || value.length === 0) return defaultSections;
  const resolved = value.flatMap((item, index) => {
    if (!item || typeof item !== "object") return [];
    const raw = item as Record<string, unknown>;
    const type = raw.type;
    const id = typeof raw.id === "string" && /^[a-z0-9][a-z0-9_-]{0,60}$/i.test(raw.id) ? raw.id : `section-${index}`;
    const order = typeof raw.order === "number" && Number.isFinite(raw.order) ? Math.max(0, Math.min(1000, raw.order)) : index;
    if (!sectionTypes.includes(type as HomepageSectionType) || !isHomepageSectionType(type)) return [];
    return [{ id, type, enabled: raw.enabled !== false, order, props: sanitizeProps(raw.props, type as HomepageSectionType) } as HomepageSection];
  }).sort((a, b) => a.order - b.order || a.id.localeCompare(b.id));
  return resolved.length > 0 ? resolved : defaultSections;
}
