export type Locale = "en" | "vi" | "de" | "ja" | "zh";
export const locales: Locale[] = ["en", "vi", "de", "ja", "zh"];
export const hreflangByLocale: Record<Locale, string> = { en: "en", vi: "vi", de: "de", ja: "ja", zh: "zh-Hans" };
export const defaultLocale: Locale = "en";

export type IconName =
  | "bag"
  | "building"
  | "store"
  | "box"
  | "card"
  | "truck"
  | "tag"
  | "repeat"
  | "chart"
  | "code"
  | "shield"
  | "check";

export type Capability = { icon: IconName; title: string; copy: string; tag: string };

export type Content = {
  meta: {
    title: string;
    description: string;
    keywords: string[];
    ogLocale: string;
    htmlLang: string;
  };
  ui: {
    skip: string;
    menu: string;
    close: string;
    language: string;
    switchTo: string;
    currentLanguage: string;
    arrow: string;
  };
  nav: Array<{ label: string; href: string }>;
  cta: { talk: string; features: string; emailSubject: string; waitlistSubject: string };
  hero: {
    eyebrow: string;
    title: string;
    lead: string;
    notes: string[];
    visual: {
      label: string;
      windowTitle: string;
      storeName: string;
      nav: string[];
      heroTitle: string;
      heroCopy: string;
      products: Array<{ name: string; price: string; tone: "sand" | "olive" | "clay" }>;
      cartTitle: string;
      cartLines: Array<{ name: string; meta: string; price: string }>;
      cartTotal: string;
      checkout: string;
      cartNote: string;
      foot: string;
    };
  };
  proof: Array<{ value: string; label: string }>;
  platform: {
    eyebrow: string;
    title: string;
    lead: string;
    cards: Array<{ no: string; title: string; copy: string; points: string[]; icon: IconName }>;
  };
  capabilities: {
    eyebrow: string;
    title: string;
    lead: string;
    items: Capability[];
  };
  industries: {
    eyebrow: string;
    title: string;
    lead: string;
    note: string;
    items: string[];
  };
  operations: {
    eyebrow: string;
    title: string;
    lead: string;
    points: string[];
    panelTitle: string;
    panelTag: string;
    panelRows: Array<{ label: string; value: string }>;
  };
  editions: {
    eyebrow: string;
    title: string;
    lead: string;
    community: { name: string; badge: string; price: string; copy: string; points: string[]; cta: string };
    pro: { name: string; badge: string; price: string; copy: string; points: string[]; cta: string };
    note: string;
  };
  steps: {
    eyebrow: string;
    title: string;
    lead: string;
    items: Array<{ no: string; title: string; copy: string }>;
  };
  faq: {
    eyebrow: string;
    title: string;
    lead: string;
    items: Array<{ question: string; answer: string }>;
  };
  contact: {
    eyebrow: string;
    title: string;
    lead: string;
    button: string;
    note: string;
  };
  footer: {
    tagline: string;
    columns: Array<{ title: string; links: Array<{ label: string; href: string }> }>;
    rights: string;
    madeIn: string;
  };
};
