export const siteUrl = (process.env.NEXT_PUBLIC_SITE_URL ?? "https://sirocommerce.com").replace(/\/+$/, "");
export const contactEmail = (process.env.NEXT_PUBLIC_CONTACT_EMAIL ?? "hello@sirocommerce.com").trim();
export const siteName = "SiroCommerce";
export const siteDescription =
  "SiroCommerce là nền tảng thương mại điện tử mã nguồn mở, self-host cho D2C, B2B và marketplace: catalog, checkout, thanh toán Việt Nam, vận chuyển, subscription và API-first.";
