import type { Content } from "./types";

export const de: Content = {
  meta: {
    title: "SiroCommerce — Self-hosted E-Commerce für D2C, B2B und Marktplätze",
    description:
      "SiroCommerce ist Open-Source-E-Commerce zum Selbsthosten für D2C-Shops, B2B-Großhandel und Marktplätze. Katalog, Checkout, Zahlungen und Daten bleiben in Ihrer Hand.",
    keywords: [
      "self-hosted ecommerce",
      "open source shopsoftware",
      "B2B commerce",
      "marktplatz software",
      "headless commerce",
      "vietnam zahlungsanbieter",
      "SiroCommerce",
    ],
    ogLocale: "de_DE",
    htmlLang: "de",
  },
  ui: {
    skip: "Zum Inhalt springen",
    menu: "Menü",
    close: "Schließen",
    language: "Sprache",
    switchTo: "Sprache wechseln",
    currentLanguage: "Deutsch",
    arrow: "→",
  },
  nav: [
    { label: "Plattform", href: "#platform" },
    { label: "Funktionen", href: "#capabilities" },
    { label: "Branchen", href: "#industries" },
    { label: "Betrieb", href: "#operations" },
    { label: "Editionen", href: "#editions" },
    { label: "Kontakt", href: "#contact" },
  ],
  cta: {
    talk: "Sprechen Sie mit uns",
    features: "Funktionen ansehen",
    emailSubject: "SiroCommerce Kennenlernen",
    waitlistSubject: "SiroCommerce Pro Updates",
  },
  hero: {
    eyebrow: "Open Source · Self-hosted · API-first",
    title: "E-Commerce-Software, die Ihnen gehört.",
    lead:
      "SiroCommerce ist ein Commerce-Kern für D2C, B2B-Großhandel und Marktplätze. Betreiben Sie ihn auf Ihrer Infrastruktur, verbinden Sie Ihre Zahlungs- und Versanddienstleister und behalten Sie jede Bestellung, jeden Preis und jede Kundendatei.",
    notes: ["Keine Gebühr pro Bestellung", "Läuft auf Ihrer Infrastruktur", "18 Branchen-Presets"],
    visual: {
      label: "Vorschau von Storefront und Checkout",
      windowTitle: "ihrshop.de",
      storeName: "Nordfeld Apotheke",
      nav: ["Shop", "Pflege", "Abo"],
      heroTitle: "Tägliche Pflege, geliefert.",
      heroCopy: "Apothekengeprüfte Produkte, Versand am selben Tag.",
      products: [
        { name: "Immunität täglich", price: "420.000 ₫", tone: "olive" },
        { name: "Schlafsupport", price: "360.000 ₫", tone: "sand" },
        { name: "Hautbarriere", price: "540.000 ₫", tone: "clay" },
      ],
      cartTitle: "Ihr Warenkorb",
      cartLines: [
        { name: "Immunität täglich", meta: "1 × 60 Kapseln", price: "420.000 ₫" },
        { name: "Schlafsupport", meta: "1 × 30 Tabletten", price: "360.000 ₫" },
      ],
      cartTotal: "780.000 ₫",
      checkout: "Zur Kasse",
      cartNote: "Steuern werden an der Kasse berechnet.",
      foot: "Versandkostenfrei ab 500.000 ₫ · 30 Tage Rückgabe",
    },
  },
  proof: [
    { value: "18", label: "Branchen-Presets, mit einem Klick" },
    { value: "6", label: "Zahlungswege zum Verbinden" },
    { value: "1", label: "Kern für D2C, B2B und Marktplatz" },
  ],
  platform: {
    eyebrow: "Ein Kern, drei Geschäftsmodelle",
    title: "Starten Sie mit einem Modell. Erweitern Sie, ohne neu zu plattformieren.",
    lead:
      "Katalog, Preise, Checkout, Bestellungen und Fulfillment sind gemeinsam. Kanäle und Richtlinien ändern sich pro Shop, sodass Großhandel und Endkunde denselben Katalog nutzen können, ohne sich zu stören.",
    cards: [
      {
        no: "01",
        title: "Direkt an Endkunden",
        copy: "Eine schnelle, redaktionelle Storefront mit Gast-Checkout, Konten, Aktionen und Abonnements.",
        points: ["18 Branchen-Presets", "Gast- und Kundenkonto-Checkout", "Gutscheine, Geschenkkarten, Store-Credit"],
        icon: "bag",
      },
      {
        no: "02",
        title: "B2B und Großhandel",
        copy: "Angebote, Freigaben und Vertragspreise laufen durch dieselbe Bestellpipeline wie der Einzelhandel.",
        points: ["Firmenkonten und Einkäufer", "Preislisten und verhandelte Preise", "Angebot-zu-Bestellung mit Freigabe"],
        icon: "building",
      },
      {
        no: "03",
        title: "Marktplatz lite",
        copy: "Binden Sie Drittanbieter früh ein und wachsen Sie später in das vollständige Marktplatz-Paket.",
        points: ["Händlerkatalog und Sichtbarkeit", "Provisions-Snapshots", "Auszahlungsjournal und Bankexport"],
        icon: "store",
      },
    ],
  },
  capabilities: {
    eyebrow: "Im Lieferumfang",
    title: "Commerce-Tiefe ohne Plugin-Stapel.",
    lead:
      "Der Kern deckt ab, was sonst aus Warenkorb-, Steuer-, Abo- und Reporting-Plugins zusammengesetzt wird.",
    items: [
      { icon: "box", title: "Katalog und Medien", copy: "Varianten, Bestand über mehrere Lager, WebP/AVIF-Ableitungen und CDN-fähige Medien-URLs.", tag: "Enthalten" },
      { icon: "card", title: "Checkout und Zahlungen", copy: "Nachnahme, Überweisung, VNPay, MoMo, Stripe und PayPal. Aktivierung mit Ihren Zugangsdaten.", tag: "Enthalten" },
      { icon: "truck", title: "Versand und Abholung", copy: "GHN-, Viettel-Post- und DHL-Adapter sowie Click-and-Collect mit Abholpunkten.", tag: "Enthalten" },
      { icon: "tag", title: "Preise, Steuern, Aktionen", copy: "B2B-Preislisten, Steuerzonen, Gutscheine, Geschenkkarten, Store-Credit und Mitgliederangebote.", tag: "Enthalten" },
      { icon: "repeat", title: "Abonnements", copy: "Pläne, wiederkehrende Bestellungen, Verlängerungen und ein Kundenportal.", tag: "Enthalten" },
      { icon: "store", title: "Marktplatz lite", copy: "Händler, Provisions-Snapshots und ein Auszahlungsjournal mit Bank-CSV.", tag: "Lite" },
      { icon: "chart", title: "Betrieb", copy: "Health-Checks, Prometheus-Metriken, Backup-Übungen, Audit-Trail und Consent.", tag: "Enthalten" },
      { icon: "code", title: "Headless-API", copy: "REST und OpenAPI, Webhooks, entkoppelte Storefront und separate Admin-App.", tag: "Enthalten" },
    ],
  },
  industries: {
    eyebrow: "Themes, die nah am Ziel starten",
    title: "18 Branchen-Presets, dann machen Sie sie zu Ihren.",
    lead:
      "Preset im Admin anwenden, dann Farbe, Typografie, Header, Footer, Produktkarten und Startseiten-Sektionen anpassen. Kein Theme-Marktplatz nötig.",
    note: "Presets sind Startpunkte, keine gesperrten Templates. Ein Shop kann ein Preset klonen, seinen eigenen Theme-Key behalten und das Layout pro Kanal ändern.",
    items: [
      "Mode",
      "Beauty",
      "Apotheke",
      "Food & Getränke",
      "Elektronik",
      "B2B",
      "Supermarkt",
      "Kosmetik",
      "Schuhe",
      "Schmuck",
      "Möbel",
      "Sport",
      "Haustiere",
      "Baby",
      "Bücher",
      "Blumen",
      "Nahrungsergänzung",
      "Autoteile",
    ],
  },
  operations: {
    eyebrow: "Für die Menschen, die den Shop betreiben",
    title: "Betreiben Sie es wie Infrastruktur, nicht wie eine Blackbox.",
    lead:
      "Sie erhalten Endpunkte, Jobs und Aufzeichnungen für den Betrieb nach dem Launch: Health, Metriken, Backups, Audit und Consent.",
    points: [
      "Ihre Datenbank und Ihre Backups",
      "Health- und Readiness-Endpunkte",
      "Queue-basierte Jobs mit Retries",
      "Audit-Trail und Datenschutz-Kontrollen",
    ],
    panelTitle: "Betriebs-Oberfläche",
    panelTag: "Enthalten",
    panelRows: [
      { label: "API Health und Readiness", value: "Endpunkte enthalten" },
      { label: "Hintergrund-Jobs", value: "Queue-basiert" },
      { label: "Backup und Restore", value: "Runbook bereit" },
      { label: "Metriken", value: "Prometheus" },
      { label: "Audit und Consent", value: "Integriert" },
    ],
  },
  editions: {
    eyebrow: "Open Core",
    title: "Kostenlos starten. Aufsteigen, wenn das Geschäft es braucht.",
    lead:
      "Die Community-Edition ist der vollständige Commerce-Kern zum Selbsthosten. Bezahlte Editionen sind Paketierung, Lizenzierung und Support – kein anderer Codebase.",
    community: {
      name: "Community",
      badge: "Kostenlos",
      price: "Self-hosted",
      copy: "Für Teams, die den Stack selbst besitzen und technisch betreiben können.",
      points: ["Vollständiger Commerce-Kern", "18 Branchen-Presets", "API- und Storefront-Quellcode", "Upgrades im eigenen Tempo"],
      cta: "Sprechen Sie mit uns",
    },
    pro: {
      name: "Pro",
      badge: "In Entwicklung",
      price: "Lizenziert",
      copy: "Für Multi-Store-Betreiber mit Bedarf an paketiertem Theming, Governance und Managed-Optionen.",
      points: ["Paketiertes Multi-Store-Theming", "Lizenz- und Entitlement-Kontrollen", "SSO, Audit-Export, Managed AI", "Priorisierter Support und SLA"],
      cta: "Pro-Updates erhalten",
    },
    note: "Pro ist noch nicht im Verkauf. Finaler Umfang und Preis werden bei Veröffentlichung bekannt gegeben; nichts oben wird heute als bestehendes Pro-Feature verkauft.",
  },
  steps: {
    eyebrow: "Erste Schritte",
    title: "Vom ersten Gespräch zu einem Shop, den Sie betreiben können.",
    lead: "Ein kurzer, technischer Prozess. Keine Migrationsüberraschungen aus dem Verkaufsdeck.",
    items: [
      { no: "01", title: "Umfang", copy: "Geschäftsmodell, Kataloggröße, Dienstleister und der Launch-Termin, den Sie wirklich brauchen." },
      { no: "02", title: "Deployment", copy: "Installation auf Ihrer Infrastruktur, Zahlungs- und Versand-Zugangsdaten verbinden, Daten migrieren." },
      { no: "03", title: "Betrieb", copy: "Team schulen, Runbooks übergeben und entscheiden, ob laufender Support gewünscht ist." },
    ],
  },
  faq: {
    eyebrow: "Fragen, beantwortet",
    title: "Was Teams vor dem Start fragen.",
    lead: "Kurze Antworten für technische und kaufmännische Stakeholder.",
    items: [
      {
        question: "Ist SiroCommerce kostenlos?",
        answer:
          "Die Community-Edition ist kostenlos zum Selbsthosten und enthält den Commerce-Kern. Pro und Enterprise sind in Entwicklung und werden als paketierte Funktionen, Lizenzierung und Support verkauft – nicht als separater Fork.",
      },
      {
        question: "Muss ich die SiroCommerce-Cloud nutzen?",
        answer:
          "Nein. SiroCommerce wird auf Ihrer Infrastruktur installiert. Sie stellen Anwendungsserver, Datenbank, Cache und Objektspeicher; diese Marketingseite ist statisch und kann auf einem CDN laufen.",
      },
      {
        question: "Werden vietnamesische Zahlungen und Versand unterstützt?",
        answer:
          "Ja. VNPay, MoMo, GHN und Viettel Post sind im Code enthalten und werden mit Ihren Händler-Zugangsdaten aktiviert. International stehen Stripe, PayPal und DHL zur Verfügung.",
      },
      {
        question: "Ist das ein SaaS-Produkt?",
        answer:
          "Nein. Es ist Self-hosted-Software. Ihr Team besitzt Deployment, Daten und Upgrades. Bezahlte Editionen ergänzen Lizenzierung, paketierte Funktionen und Support auf demselben Codebase.",
      },
      {
        question: "Können D2C und B2B denselben Katalog nutzen?",
        answer:
          "Ja. Firmen, Preislisten, Angebote und Freigaben stehen neben dem Einzelhandels-Checkout. Store- und Kanal-Scope trennen Katalog, Preise und Themes, wo nötig.",
      },
      {
        question: "Was ist in Pro enthalten?",
        answer:
          "Geplant: paketiertes Multi-Store-Theming, Lizenz- und Entitlement-Kontrollen, SSO/SAML, Audit-Export, Managed-AI-Optionen, priorisierter Support und SLA. Noch nicht im Verkauf; der finale Umfang wird vor dem Verkauf veröffentlicht.",
      },
    ],
  },
  contact: {
    eyebrow: "Kontakt",
    title: "Erzählen Sie uns, was Sie bauen.",
    lead:
      "Senden Sie Geschäftsmodell, ungefähre Kataloggröße und benötigte Dienstleister. Wir antworten per E-Mail mit den nächsten technischen Fragen.",
    button: "E-Mail an das Team",
    note: "Lieber ein kurzes Gespräch? Nennen Sie Ihre Zeitzone, wir schlagen einen Termin vor.",
  },
  footer: {
    tagline: "Self-hosted Commerce für Teams, die den Stack selbst besitzen wollen.",
    columns: [
      {
        title: "Plattform",
        links: [
          { label: "Überblick", href: "#platform" },
          { label: "Funktionen", href: "#capabilities" },
          { label: "Branchen", href: "#industries" },
          { label: "Betrieb", href: "#operations" },
        ],
      },
      {
        title: "Editionen",
        links: [
          { label: "Community", href: "#editions" },
          { label: "Pro (in Entwicklung)", href: "#editions" },
          { label: "FAQ", href: "#faq" },
        ],
      },
      {
        title: "Kontakt",
        links: [
          { label: "E-Mail", href: "#contact" },
          { label: "Sprechen Sie mit uns", href: "#contact" },
        ],
      },
    ],
    rights: "© 2026 SiroCommerce. Alle Rechte vorbehalten.",
    madeIn: "Self-hosted Commerce, made in Vietnam.",
  },
};
