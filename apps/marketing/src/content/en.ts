import type { Content } from "./types";

export const en: Content = {
  meta: {
    title: "SiroCommerce — Self-hosted ecommerce for D2C, B2B and marketplaces",
    description:
      "SiroCommerce is open-source, self-hosted ecommerce software for direct-to-consumer stores, B2B wholesale and marketplace operations. Own your catalog, checkout, payments and data.",
    keywords: [
      "self-hosted ecommerce",
      "open source ecommerce platform",
      "B2B commerce",
      "marketplace software",
      "headless commerce",
      "Vietnam payment gateway",
      "SiroCommerce",
    ],
    ogLocale: "en_US",
    htmlLang: "en",
  },
  ui: {
    skip: "Skip to content",
    menu: "Menu",
    close: "Close",
    language: "Language",
    switchTo: "Switch to Vietnamese",
    currentLanguage: "English",
    arrow: "→",
  },
  nav: [
    { label: "Platform", href: "#platform" },
    { label: "Capabilities", href: "#capabilities" },
    { label: "Industries", href: "#industries" },
    { label: "Operations", href: "#operations" },
    { label: "Editions", href: "#editions" },
    { label: "Contact", href: "#contact" },
  ],
  cta: {
    talk: "Talk to us",
    features: "Explore capabilities",
    emailSubject: "SiroCommerce introduction",
    waitlistSubject: "SiroCommerce Pro updates",
  },
  hero: {
    eyebrow: "Open source · Self-hosted · API-first",
    title: "Ecommerce software you can own, operate and extend.",
    lead:
      "SiroCommerce is one commerce core for direct-to-consumer, B2B wholesale and marketplace businesses. Run it on your infrastructure, connect your own payment and shipping providers, and keep every order, price and customer record in your hands.",
    notes: ["No per-order platform fee", "Runs on your infrastructure", "18 industry presets"],
    visual: {
      label: "Storefront and checkout preview",
      windowTitle: "yourstore.com",
      storeName: "Northfield Pharmacy",
      nav: ["Shop", "Care", "Subscribe"],
      heroTitle: "Everyday care, delivered.",
      heroCopy: "Pharmacist-reviewed essentials with same-day dispatch.",
      products: [
        { name: "Daily immunity", price: "₫420,000", tone: "olive" },
        { name: "Sleep support", price: "₫360,000", tone: "sand" },
        { name: "Skin barrier", price: "₫540,000", tone: "clay" },
      ],
      cartTitle: "Your bag",
      cartLines: [
        { name: "Daily immunity", meta: "1 × 60 capsules", price: "₫420,000" },
        { name: "Sleep support", meta: "1 × 30 tablets", price: "₫360,000" },
      ],
      cartTotal: "₫780,000",
      checkout: "Checkout",
      cartNote: "Taxes calculated at checkout.",
      foot: "Free delivery over ₫500,000 · 30-day returns",
    },
  },
  proof: [
    { value: "18", label: "industry presets, one click to apply" },
    { value: "6", label: "payment rails ready to connect" },
    { value: "1", label: "core for D2C, B2B and marketplace" },
  ],
  platform: {
    eyebrow: "One core, three ways to sell",
    title: "Start with one business model. Add the next without a replatform.",
    lead:
      "Catalog, pricing, checkout, orders and fulfillment are shared. Channels and policies change per store, so a wholesale account and a retail buyer can run on the same catalog without stepping on each other.",
    cards: [
      {
        no: "01",
        title: "Direct-to-consumer",
        copy: "A fast, editorial storefront with guest checkout, accounts, promotions and subscriptions.",
        points: ["18 industry presets", "Guest and account checkout", "Vouchers, gift cards, store credit"],
        icon: "bag",
      },
      {
        no: "02",
        title: "B2B and wholesale",
        copy: "Quote, approval and contract pricing built into the same order pipeline as retail.",
        points: ["Company accounts and buyers", "Price lists and negotiated pricing", "Quote-to-order approvals"],
        icon: "building",
      },
      {
        no: "03",
        title: "Marketplace lite",
        copy: "Bring third-party sellers in early; grow into the full marketplace package when you need it.",
        points: ["Vendor catalog and visibility", "Commission snapshots", "Payout ledger and bank export"],
        icon: "store",
      },
    ],
  },
  capabilities: {
    eyebrow: "What ships in the box",
    title: "Commerce depth without a stack of plugins.",
    lead:
      "The core covers the work that usually gets stitched together from a cart plugin, a tax plugin, a subscription app and a reporting tool.",
    items: [
      { icon: "box", title: "Catalog and media", copy: "Variants, multi-warehouse stock, WebP/AVIF derivatives and CDN-ready media URLs.", tag: "Included" },
      { icon: "card", title: "Checkout and payments", copy: "COD, bank transfer, VNPay, MoMo, Stripe and PayPal. Activate each with your own credentials.", tag: "Included" },
      { icon: "truck", title: "Shipping and pickup", copy: "GHN, Viettel Post and DHL adapters, plus click-and-collect with pickup points.", tag: "Included" },
      { icon: "tag", title: "Pricing, tax and promotions", copy: "B2B price lists, tax zones, vouchers, gift cards, store credit and member deals.", tag: "Included" },
      { icon: "repeat", title: "Subscriptions", copy: "Plans, recurring orders, renewal handling and a subscriber portal.", tag: "Included" },
      { icon: "store", title: "Marketplace lite", copy: "Vendors, commission snapshots and a payout ledger with bank CSV export.", tag: "Lite" },
      { icon: "chart", title: "Operations", copy: "Health checks, Prometheus metrics, backup drills, audit trail and consent controls.", tag: "Included" },
      { icon: "code", title: "Headless API", copy: "REST and OpenAPI, webhooks, a decoupled storefront and a separate admin app.", tag: "Included" },
    ],
  },
  industries: {
    eyebrow: "Themes that start close to done",
    title: "18 industry presets, then make it yours.",
    lead:
      "Apply a preset in the admin, then adjust color, type, header, footer, product cards and homepage sections. No template marketplace required.",
    note: "Presets are starting points, not locked templates. A store can clone a preset, keep its own theme key and change layout per channel.",
    items: [
      "Fashion",
      "Beauty",
      "Pharmacy",
      "Food & beverage",
      "Electronics",
      "B2B",
      "Grocery",
      "Cosmetics",
      "Footwear",
      "Jewelry",
      "Furniture",
      "Sports",
      "Pets",
      "Baby",
      "Books",
      "Flowers",
      "Supplements",
      "Auto parts",
    ],
  },
  operations: {
    eyebrow: "Built for people who run the store",
    title: "Run it like infrastructure, not a black box.",
    lead:
      "You get the endpoints, jobs and records needed to operate commerce after launch: health, metrics, backups, audit and consent.",
    points: [
      "Your database and your backups",
      "Health and readiness endpoints",
      "Queue-backed jobs with retries",
      "Audit trail and privacy controls",
    ],
    panelTitle: "Operations surface",
    panelTag: "Included",
    panelRows: [
      { label: "API health and readiness", value: "Endpoints included" },
      { label: "Background jobs", value: "Queue-backed" },
      { label: "Backups and restore", value: "Runbook ready" },
      { label: "Metrics", value: "Prometheus" },
      { label: "Audit and consent", value: "Built in" },
    ],
  },
  editions: {
    eyebrow: "Open core",
    title: "Start free. Move up when the business needs it.",
    lead:
      "The Community edition is the full commerce core, self-hosted. Paid editions are packaging, licensing and support, not a different codebase.",
    community: {
      name: "Community",
      badge: "Free",
      price: "Self-hosted",
      copy: "For teams that want to own the stack and have the engineering capacity to run it.",
      points: ["Full core commerce", "18 industry presets", "API and storefront source", "Upgrade at your own pace"],
      cta: "Talk to us",
    },
    pro: {
      name: "Pro",
      badge: "In development",
      price: "Licensed",
      copy: "For multi-store operators that need packaged theming, governance and managed options.",
      points: ["Packaged multi-store theming", "License and entitlement controls", "SSO, audit export, managed AI", "Priority support and SLA"],
      cta: "Get Pro updates",
    },
    note: "Pro is not on sale yet. We publish the final scope and pricing when it ships; nothing above is sold as an existing Pro feature today.",
  },
  steps: {
    eyebrow: "Getting started",
    title: "From first call to a store you can operate.",
    lead: "A short, technical process. No migration surprises hidden in a sales deck.",
    items: [
      { no: "01", title: "Scope", copy: "Business model, catalog size, providers and the launch date you actually need." },
      { no: "02", title: "Deploy", copy: "Install on your infrastructure, connect payment and shipping credentials, migrate data." },
      { no: "03", title: "Operate", copy: "Train the team, hand over runbooks and decide whether you want ongoing support." },
    ],
  },
  faq: {
    eyebrow: "Questions, answered",
    title: "What teams ask before they start.",
    lead: "Short answers for technical and commercial stakeholders.",
    items: [
      {
        question: "Is SiroCommerce free?",
        answer:
          "The Community edition is free to self-host and includes the commerce core. Pro and Enterprise are in development and will be sold as packaged features, licensing and support — not as a separate fork.",
      },
      {
        question: "Do I have to run it on SiroCommerce cloud?",
        answer:
          "No. SiroCommerce is installed on infrastructure you control. You provide the application server, database, cache and object storage; the marketing site you are reading is static and can run on a CDN.",
      },
      {
        question: "Does it support Vietnamese payments and shipping?",
        answer:
          "Yes. VNPay, MoMo, GHN and Viettel Post integrations are in the codebase and are activated with your merchant credentials. International options include Stripe, PayPal and DHL.",
      },
      {
        question: "Is this a SaaS product?",
        answer:
          "No. It is self-hosted software. Your team owns the deployment, data and upgrades. Paid editions add licensing, packaged features and support on top of the same codebase.",
      },
      {
        question: "Can we run D2C and B2B on one catalog?",
        answer:
          "Yes. Companies, price lists, quotes and approvals sit next to retail checkout. Store and channel scope keep catalogs, pricing and themes separate where they need to be.",
      },
      {
        question: "What is in the Pro edition?",
        answer:
          "Planned: packaged multi-store theming, license and entitlement controls, SSO/SAML, audit export, managed AI options, priority support and SLA. It is not on sale yet, and we will publish final scope before selling it.",
      },
    ],
  },
  contact: {
    eyebrow: "Contact",
    title: "Tell us what you are building.",
    lead:
      "Send the business model, rough catalog size and the providers you need. We will reply by email with the next technical questions.",
    button: "Email the team",
    note: "Prefer a short call? Mention your timezone and we will propose a slot.",
  },
  footer: {
    tagline: "Self-hosted commerce for teams that want to own the stack.",
    columns: [
      {
        title: "Platform",
        links: [
          { label: "Overview", href: "#platform" },
          { label: "Capabilities", href: "#capabilities" },
          { label: "Industries", href: "#industries" },
          { label: "Operations", href: "#operations" },
        ],
      },
      {
        title: "Editions",
        links: [
          { label: "Community", href: "#editions" },
          { label: "Pro (in development)", href: "#editions" },
          { label: "FAQ", href: "#faq" },
        ],
      },
      {
        title: "Contact",
        links: [
          { label: "Email", href: "#contact" },
          { label: "Talk to us", href: "#contact" },
        ],
      },
    ],
    rights: "© 2026 SiroCommerce. All rights reserved.",
    madeIn: "Self-hosted commerce, made in Vietnam.",
  },
};
