import type { ReactNode } from "react";
import type { HomepageSection } from "@/homepage/types";
import { isHomepageSectionType } from "@/homepage/registry";
export function HomepageRenderer({ sections, render, hasData }: { sections: HomepageSection[]; render: (section: HomepageSection) => ReactNode; hasData?: (section: HomepageSection) => boolean }) {
  return <>{sections.filter((section) => isHomepageSectionType(section.type) && section.enabled && (hasData?.(section) ?? true)).sort((a, b) => a.order - b.order || a.id.localeCompare(b.id)).map((section) => <section id={section.id} key={section.id}>{render(section)}</section>)}</>;
}
