import type { ReactNode } from "react";
export type HomepageSectionType = "campaign_hero" | "collection_directory" | "editorial_story" | "product_collection" | "service_proof" | "trust_strip" | "category_links" | "banner_grid" | "usp_strip" | "faq";
/** Section props are backend-authored. Each renderer narrows the values it
 * accepts; unknown or hostile values are ignored rather than rendered. */
export type HomepageSectionProps = Record<string, unknown>;
export interface HomepageSection { id: string; type: HomepageSectionType; enabled: boolean; order: number; props: HomepageSectionProps; }
export interface HomepageRenderContext { section: HomepageSection; render: (section: HomepageSection) => ReactNode; hasData?: (section: HomepageSection) => boolean; }
