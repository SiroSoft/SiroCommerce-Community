import type { ReactNode } from "react";
import type { HomepageSectionType } from "./types";
export type HomepageRenderer = (props: Record<string, unknown>) => ReactNode;
// These are the only section identities the storefront can render. Components
// may replace the no-op handlers during app composition, but API data cannot.
export const homepageRegistry: Record<HomepageSectionType, HomepageRenderer> = {
  campaign_hero: () => null,
  collection_directory: () => null,
  editorial_story: () => null,
  product_collection: () => null,
  service_proof: () => null,
  trust_strip: () => null,
  category_links: () => null,
  banner_grid: () => null,
  usp_strip: () => null,
  faq: () => null,
};
export function registerHomepageRenderer(type: HomepageSectionType, renderer: HomepageRenderer): void { homepageRegistry[type] = renderer; }
export function isHomepageSectionType(value: unknown): value is HomepageSectionType { return typeof value === "string" && value in homepageRegistry; }
