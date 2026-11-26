import { z } from "zod";
import { tSchema } from "@/lib/i18n";

export const createProductSchema = z.object({
  name: z.string().min(2, tSchema("validation.nameMin2")),
  description: z.string().optional(),
  short_description: z.string().optional(),
  price: z.coerce.number().min(0, tSchema("validation.priceMin")),
  compare_price: z.coerce.number().min(0).optional(),
  cost_price: z.coerce.number().min(0).optional(),
  sku: z.string().min(1, tSchema("validation.skuRequired")),
  barcode: z.string().optional(),
  stock: z.coerce.number().int().min(0, "Stock cannot be negative").default(0),
  stock_min: z.coerce.number().int().min(0, "Minimum stock cannot be negative").optional(),
  weight: z.coerce.number().min(0, "Weight cannot be negative").optional(),
  width: z.coerce.number().min(0, "Width cannot be negative").optional(),
  height: z.coerce.number().min(0, "Height cannot be negative").optional(),
  length: z.coerce.number().min(0, "Length cannot be negative").optional(),
  is_active: z.boolean().default(true),
  min_tier: z.string().optional(),
  is_featured: z.boolean().default(false),
  is_preorder: z.boolean().optional(),
  category_id: z.coerce.number().optional(),
  vendor_id: z.string().nullable().optional(),
  subscription_plan_ids: z.array(z.string()).optional(),
}).refine((value) => value.compare_price === undefined || value.compare_price >= value.price, {
  path: ["compare_price"], message: "Compare-at price must be greater than or equal to price",
});

export const updateProductSchema = z.object({
  name: z.string().min(2, tSchema("validation.nameMin2")).optional(),
  description: z.string().optional(),
  short_description: z.string().optional(),
  price: z.coerce.number().min(0, tSchema("validation.priceMin")).optional(),
  compare_price: z.coerce.number().min(0, tSchema("validation.priceMin")).optional(),
  cost_price: z.coerce.number().min(0, tSchema("validation.priceMin")).optional(),
  sku: z.string().optional(),
  barcode: z.string().optional(),
  stock: z.coerce.number().int().min(0, "Stock cannot be negative").optional(),
  stock_min: z.coerce.number().int().min(0, "Minimum stock cannot be negative").optional(),
  weight: z.coerce.number().min(0, "Weight cannot be negative").optional(),
  width: z.coerce.number().min(0, "Width cannot be negative").optional(),
  height: z.coerce.number().min(0, "Height cannot be negative").optional(),
  length: z.coerce.number().min(0, "Length cannot be negative").optional(),
  is_active: z.boolean().optional(),
  is_featured: z.boolean().optional(),
  min_tier: z.string().optional(),
  is_preorder: z.boolean().optional(),
  category_id: z.coerce.number().optional(),
  vendor_id: z.string().nullable().optional(),
  subscription_plan_ids: z.array(z.string()).optional(),
}).refine((value) => value.compare_price === undefined || value.price === undefined || value.compare_price >= value.price, {
  path: ["compare_price"], message: "Compare-at price must be greater than or equal to price",
});

export type CreateProductFormData = z.infer<typeof createProductSchema>;
export type UpdateProductFormData = z.infer<typeof updateProductSchema>;
