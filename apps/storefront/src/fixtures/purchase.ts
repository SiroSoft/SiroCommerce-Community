import type { ProductHandoff } from "../purchase/contracts";

export const fixtureProduct: ProductHandoff = {
  product_id: "sku-siro-001",
  slug: "siro-dao",
  name: "Siro dao",
  unit_price: { amount_minor: 125000, currency: "USD" },
  quantity: 1,
};
