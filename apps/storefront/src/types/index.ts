export interface ApiResponse<T = unknown> {
  success: boolean;
  message: string;
  data: T;
  meta?: PaginationMeta;
}

export interface PaginationMeta {
  page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface ImageVariant { w: number; url: string; format?: string }

export interface ProductImageFrame { url: string; alt: string; type?: string; variants?: ImageVariant[]; placeholder?: string }

export interface Product {
  id: string;
  name: string;
  slug: string;
  status: "active" | "out_of_stock" | "backorder";
  productType: "simple" | "variable" | "configurable" | "bundle" | "downloadable" | "grouped" | "virtual" | "booking" | "unsupported";
  capability: "available" | "unavailable";
  category: string;
  categoryName: string;
  description: string;
  price: number;
  compareAtPrice?: number;
  currency: string;
  image: string;
  imageAlt: string;
  images?: ProductImageFrame[];
  rating: number;
  reviewCount: number;
  tags: string[];
  stock?: number;
  allowBackorder?: boolean;
  is_preorder?: boolean;
  attributes?: ProductAttribute[];
  options?: ProductOption[];
  optionPricing?: ProductOptionPricing[];
  quantityPricing?: QuantityPrice[];
  delivery?: DeliveryContract;
  vendor?: CommerceParty;
  site?: CommerceSite;
  subscriptionPlans?: SubscriptionPlan[];
  bundleComponents?: BundleComponent[];
  variantOptions?: ProductVariantOption[];
  groupedItems?: GroupedChild[];
  bookingSlots?: BookingSlot[];
  booking?: { variant_id: string; slots_count: number; delivery?: DeliveryContract };
  flashSales?: FlashSaleDeal[];
  memberPrices?: MemberTierPrice[];
  stockBySource?: Record<string, Record<string, number>>;
}

export interface ProductAttribute { name: string; value: string; }
export interface ProductOption { name: string; values: string[]; }
export interface ProductOptionPricing { option: string; value: string; surcharge: number; currency: string; }
export interface ProductVariantOption { variantId: string; options: Record<string, string>; price: number; comparePrice?: number; sku?: string; stock?: number; }
export interface GroupedChild { variantId: string; name: string; slug?: string; price: number; currency: string; image?: string; stock?: number; backorderable?: boolean; }
export interface QuantityPrice { minimum: number; price: number; currency: string; }
export interface BookingSlot { id: string; variant_id: string; starts_at: string; ends_at: string; capacity: number; price_minor?: number | null; currency?: string | null; }
export interface FlashSaleDeal { sale_id: string; sale_name: string; variant_id: string; sale_price_minor: number; starts_at: string; ends_at: string; cap_qty: number; sold_qty: number; remaining: number | null; priority: number; }
export interface MemberTierPrice { id: string; variant_id: string; tier: string; price_minor: number; currency: string; }
export type DeliveryContract = { mode: "physical" | "virtual" | "downloadable" | "scheduled" | "unknown"; state: "available" | "pending" | "unavailable" | "unknown"; scheduled_for?: string; message?: string; warehouse?: string; reservation?: "not_reserved" | "reserved" | "unknown" };
export type CommerceParty = { id: string; name: string; slug?: string };
export type CommerceSite = { id: string; name: string; locale?: string; currency?: string };
export type VendorProfile = { id: string; name: string; slug: string; status: "active" | "suspended"; suspended: boolean; description?: string; contact_email?: string | null; product_count: number };
export type SubscriptionPlan = { id: string; name: string; interval: string; price: number; currency: string; purchasable: false; intervalCount?: number; amountMinor?: number; trialPeriod?: string | null; trialPeriodCount?: number | null };
export type BundleComponent = { id: string; name: string; quantity: number; required: boolean; options?: string[] };

export interface CatalogFacetValue { value: string; label?: string; count: number; }
export interface CatalogFacet { name: string; label?: string; values: CatalogFacetValue[]; }
export interface CatalogResult { products: Product[]; total?: number; facets: CatalogFacet[]; }

export interface ProductQuery { category?: string; search?: string; vendor?: string; sort?: "featured" | "price-asc" | "price-desc"; page?: number; perPage?: number; facets?: Record<string, string[]>; signal?: AbortSignal; }
export interface CatalogAdapter {
  listProducts(query?: ProductQuery): Promise<Product[]>;
  listProductsResult?: (query?: ProductQuery) => Promise<CatalogResult>;
  autocomplete?: (query: string, signal?: AbortSignal) => Promise<Product[]>;
  getProduct(slug: string, signal?: AbortSignal): Promise<Product | undefined>;
  getVendor?: (slug: string, signal?: AbortSignal) => Promise<VendorProfile | undefined>;
  invalidateCache?: () => void;
}

export interface CatalogFailure {
  status: number;
  message: string;
}
