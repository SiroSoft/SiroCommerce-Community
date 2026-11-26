import type { NextConfig } from "next";

const isProduction = process.env.NODE_ENV === "production";

const nextConfig: NextConfig = {
  poweredByHeader: false,
  devIndicators: false,
  ...(isProduction
    ? { turbopack: { resolveAlias: { "@/purchase/fixture-adapter": "./src/purchase/fixture-adapter.prod.ts" } } }
    : {}),
  async headers() {
    return [{ source: "/(.*)", headers: [
      { key: "X-Frame-Options", value: "DENY" },
      { key: "X-Content-Type-Options", value: "nosniff" },
      { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
      { key: "X-XSS-Protection", value: "0" },
      { key: "Permissions-Policy", value: "geolocation=(), microphone=(), camera=()" },
      { key: "X-Permitted-Cross-Domain-Policies", value: "none" },
      { key: "Strict-Transport-Security", value: "max-age=63072000; includeSubDomains; preload" },
    ] }];
  },
  async redirects() {
    return [
// Legacy Vietnamese slugs (and old English aliases) 301 to canonical English.
    { source: "/category/thoi-trang", destination: "/category/apparel", permanent: true },
    { source: "/category/nha-cua-doi-song", destination: "/category/home-living", permanent: true },
    { source: "/category/cham-soc-ca-nhan", destination: "/category/personal-care", permanent: true },
    { source: "/category/do-uong-thuc-pham", destination: "/category/food-beverage", permanent: true },
    { source: "/product/ao-so-mi-linen", destination: "/product/linen-mandarin-shirt", permanent: true },
    { source: "/product/ao-thun-cot-lon-organic", destination: "/product/organic-cotton-crew-tee", permanent: true },
    { source: "/product/quan-jean-indigo-organic", destination: "/product/rigid-indigo-denim-trousers", permanent: true },
    { source: "/product/ao-khoac-wool-melange", destination: "/product/melange-wool-overshirt", permanent: true },
    { source: "/product/vay-linen-khoa", destination: "/product/linen-tie-front-blouse", permanent: true },
    { source: "/product/mu-lua-rsom", destination: "/product/packable-rope-sun-hat", permanent: true },
    { source: "/product/tui-vai-canvas-tote", destination: "/product/structured-canvas-tote", permanent: true },
    { source: "/product/tui-deu-cong-con-lining", destination: "/product/lined-leather-utility-sling", permanent: true },
    { source: "/product/nem-cao-su-thien-nhien", destination: "/product/natural-latex-mattress", permanent: true },
    { source: "/product/binh-giu-nhiet-inox", destination: "/product/stainless-steel-flask-500ml", permanent: true },
    { source: "/product/chen-ceramic-handmade", destination: "/product/hand-thrown-stoneware-bowl", permanent: true },
    { source: "/product/chan-linen-cotton", destination: "/product/stonewashed-linen-bedding-set", permanent: true },
    { source: "/product/den-sau-rubber-fsc", destination: "/product/fsc-oak-desk-tray", permanent: true },
    { source: "/product/lo-san-tho-gom", destination: "/product/speckled-stoneware-serving-jug", permanent: true },
    { source: "/product/tham-wool-lai", destination: "/product/brushed-wool-throw-blanket", permanent: true },
    { source: "/product/sua-rua-mat-diu-nhe", destination: "/product/gentle-green-tea-facial-cleanser", permanent: true },
    { source: "/product/kem-chong-nang-spf50", destination: "/product/broad-spectrum-spf50-sunscreen", permanent: true },
    { source: "/product/dau-duong-tam-sa", destination: "/product/calming-rosehip-body-wash", permanent: true },
    { source: "/product/kem-tay-am-shea", destination: "/product/shea-repair-hand-cream", permanent: true },
    { source: "/product/mui-loc-thien-nhien", destination: "/product/refillable-bamboo-toothbrush-set", permanent: true },
    { source: "/product/ca-phe-rang-moc-arabica", destination: "/product/single-origin-arabica-coffee", permanent: true },
    { source: "/product/tra-olong-hoa-lai", destination: "/product/jasmine-oolong-tea", permanent: true },
    { source: "/product/matcha-ceremony", destination: "/product/ceremonial-grade-matcha", permanent: true },
    { source: "/product/mat-hong-sen-kho", destination: "/product/dried-lotus-seed-tea", permanent: true },
    { source: "/product/socola-don-dau-viet-nam", destination: "/product/single-origin-cacao-drinking-chocolate", permanent: true },
    { source: "/product/duong-mat-ong-rong", destination: "/product/wildflower-honey-jar", permanent: true },
    { source: "/product/bo-qua-tang-buoi-sang", destination: "/product/morning-ritual-gift-set", permanent: true },
    { source: "/product/huong-dan-pha-che", destination: "/product/home-brew-guide-ebook", permanent: true },
    { source: "/product/bo-khoi-dau-linen", destination: "/product/linen-starter-set", permanent: true },
    { source: "/product/dich-vu-goi-qua", destination: "/product/gift-wrapping-service", permanent: true },
    { source: "/category/home", destination: "/category/home-living", permanent: true },
    { source: "/category/accessories", destination: "/category/personal-care", permanent: true },
    ];
  },
  images: {
    qualities: [75],
    // `srcSetFor` sends local catalog raster assets through `/_next/image`.
    // The optimizer only accepts widths present in these lists, so the widths
    // used by cards, gallery thumbs, gallery main, and hero frames must be
    // allow-listed here.
    deviceSizes: [640, 750, 828, 960, 1080, 1200, 1280, 1600, 1920, 2048, 3840],
    imageSizes: [96, 192, 320, 480, 800],
    remotePatterns: [{ protocol: "https", hostname: "images.unsplash.com" }],
    // Catalog media may be merchant-supplied SVG. Serving it through the
    // optimizer requires an explicit opt-in, so it is paired with the
    // documented sandbox policy and an attachment disposition.
    dangerouslyAllowSVG: true,
    contentDispositionType: "attachment",
    contentSecurityPolicy: "default-src 'self'; script-src 'none'; sandbox;",
  },
};

export default nextConfig;

