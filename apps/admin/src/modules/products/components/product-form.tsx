"use client";

import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { RichTextEditor } from "@/components/ui/rich-text-editor";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import {
  createProductSchema,
  updateProductSchema,
  type CreateProductFormData,
  type UpdateProductFormData,
} from "@/modules/products/schemas/product.schema";
import { useCategories } from "@/hooks/use-categories";
import { useVendors } from "@/hooks/use-vendors";
import { useSubscriptionPlans } from "@/hooks/use-subscription-plans";
import { useI18n } from "@/providers/i18n-provider";
import type { components } from "@/types/api";

type Product = components["schemas"]["Product"];

interface ProductFormProps {
  product?: Product;
  onSubmit: (data: CreateProductFormData | UpdateProductFormData) => void;
  isPending: boolean;
  onDirtyChange?: (dirty: boolean) => void;
}

export function ProductForm({ product, onSubmit, isPending, onDirtyChange }: ProductFormProps) {
  const { t } = useI18n();
  const isEdit = !!product;
  const { categories } = useCategories({ per_page: 100 });
  const { vendors } = useVendors({ per_page: 100 });
  const { plans, isLoading: plansLoading } = useSubscriptionPlans({ state: "active" });

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors, isDirty },
  } = useForm<CreateProductFormData | UpdateProductFormData>({
    resolver: zodResolver(isEdit ? updateProductSchema : createProductSchema),
    defaultValues: {
      name: product?.name ?? "",
      description: product?.description ?? "",
      short_description: product?.short_description ?? "",
      price: product?.price ?? 0,
      compare_price: product?.compare_price ?? undefined,
      cost_price: product?.cost_price ?? undefined,
      sku: product?.sku ?? "",
      barcode: product?.barcode ?? "",
      stock: product?.stock ?? 0,
      stock_min: product?.stock_min ?? undefined,
      weight: product?.weight ?? undefined,
      width: product?.width ?? undefined,
      height: product?.height ?? undefined,
      length: product?.length ?? undefined,
      is_active: product?.is_active ?? true,
      is_featured: product?.is_featured ?? false,
      is_preorder: (product as { is_preorder?: unknown })?.is_preorder === true,
      min_tier: ((product as { min_tier?: unknown })?.min_tier ?? undefined) as string | undefined,
      category_id: product?.category_id ?? undefined,
      vendor_id: product?.vendor_id ?? null,
      subscription_plan_ids: product?.subscription_plan_ids ?? [],
    },
  });

  useEffect(() => onDirtyChange?.(isDirty), [isDirty, onDirtyChange]);

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4 max-h-[60vh] overflow-y-auto pr-2">
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="name">{t("products.name")} *</Label>
          <Input id="name" {...register("name")} placeholder={t("forms.productName")} disabled={isPending} />
          {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
        </div>
        <div className="space-y-2">
          <Label htmlFor="sku">SKU *</Label>
          <Input id="sku" {...register("sku")} placeholder={t("forms.productSku")} disabled={isPending} />
          {errors.sku && <p className="text-sm text-destructive">{errors.sku.message}</p>}
        </div>
      </div>

      <div className="space-y-2">
        <Label htmlFor="description">{t("products.description")}</Label>
        <RichTextEditor value={watch("description") ?? ""} onChange={(html) => setValue("description", html, { shouldValidate: true, shouldDirty: true })} placeholder={t("forms.productDescription")} disabled={isPending} minHeight={200} />
      </div>

      <div className="space-y-2">
        <Label htmlFor="short_description">Short Description</Label>
        <Textarea id="short_description" {...register("short_description")} placeholder={t("forms.productSummary")} disabled={isPending} />
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="space-y-2">
          <Label htmlFor="price">{t("products.price")} *</Label>
          <div className="relative">
            <span className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">$</span>
            <Input id="price" type="number" step="0.01" className="pl-7" {...register("price")} disabled={isPending} />
          </div>
          {errors.price && <p className="text-sm text-destructive">{errors.price.message}</p>}
        </div>
        <div className="space-y-2">
          <Label htmlFor="compare_price">Compare-at price</Label>
          <div className="relative">
            <span className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">$</span>
            <Input id="compare_price" type="number" step="0.01" className="pl-7" {...register("compare_price")} disabled={isPending} />
          </div>
          {errors.compare_price && <p className="text-sm text-destructive">{errors.compare_price.message}</p>}
        </div>
        <div className="space-y-2">
          <Label htmlFor="cost_price">Cost price</Label>
          <div className="relative">
            <span className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">$</span>
            <Input id="cost_price" type="number" step="0.01" className="pl-7" {...register("cost_price")} disabled={isPending} />
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="stock">{t("products.stock")}</Label>
          <Input id="stock" type="number" {...register("stock")} disabled={isPending} />
        </div>
        <div className="space-y-2">
          <Label htmlFor="stock_min">{t("products.stock")}</Label>
          <Input id="stock_min" type="number" {...register("stock_min")} disabled={isPending} />
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="space-y-2">
          <Label htmlFor="weight">Weight</Label>
          <Input id="weight" type="number" step="0.01" {...register("weight")} disabled={isPending} />
        </div>
        <div className="space-y-2">
          <Label htmlFor="width">Width</Label>
          <Input id="width" type="number" step="0.01" {...register("width")} disabled={isPending} />
        </div>
        <div className="space-y-2">
          <Label htmlFor="height">Height</Label>
          <Input id="height" type="number" step="0.01" {...register("height")} disabled={isPending} />
        </div>
        <div className="space-y-2">
          <Label htmlFor="length">Length</Label>
          <Input id="length" type="number" step="0.01" {...register("length")} disabled={isPending} />
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="barcode">Barcode</Label>
          <Input id="barcode" {...register("barcode")} disabled={isPending} />
        </div>
        <div className="space-y-2">
            <Label>{t("products.category")}</Label>
<Select value={String(watch("category_id") ?? "")} onValueChange={(v) => setValue("category_id", v ? Number(v) : undefined, { shouldDirty: true, shouldValidate: true })} disabled={isPending}>
            <SelectTrigger>
              <SelectValue placeholder={t("forms.selectCategory")} />
            </SelectTrigger>
            <SelectContent>
              {categories.map((cat, index) => (
                <SelectItem key={`${cat.id}-${index}`} value={String(cat.id)}>{cat.name}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="space-y-2">
        <Label>Vendor</Label>
        <Select value={String(watch("vendor_id") ?? "")} onValueChange={(value) => setValue("vendor_id", value === "" ? null : value, { shouldDirty: true, shouldValidate: true })} disabled={isPending}>
          <SelectTrigger>
            <SelectValue placeholder="House brand (none)" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="">House brand (none)</SelectItem>
            {vendors.map((vendor) => (
              <SelectItem key={vendor.id} value={vendor.id}>{vendor.name}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="rounded-md border border-dashed p-3 text-sm text-muted-foreground" role="note">
        Cover images, variants, and options are managed on the product detail page after saving.
      </div>

      {isEdit && <fieldset className="space-y-2 rounded-md border p-3">
        <legend className="px-1 text-sm font-medium">Subscription plans</legend>
        <p className="text-xs text-muted-foreground">Only active plans can be linked. Saving replaces the full selection.</p>
        {plansLoading ? <p role="status" className="text-sm text-muted-foreground">Loading plans...</p> : plans.length === 0 ? <p className="text-sm text-muted-foreground">No active subscription plans.</p> : <div className="grid gap-2 sm:grid-cols-2">
          {plans.map((plan) => {
            const selected = (watch("subscription_plan_ids") ?? []).includes(plan.id);
            return <label key={plan.id} className="flex items-center gap-2 text-sm">
              <input type="checkbox" aria-label={`Link plan ${plan.name}`} checked={selected} disabled={isPending} onChange={(event) => {
                const current = watch("subscription_plan_ids") ?? [];
                const next = event.target.checked ? [...current, plan.id] : current.filter((id) => id !== plan.id);
                setValue("subscription_plan_ids", next, { shouldDirty: true, shouldValidate: true });
              }} />
              <span>{plan.name} <span className="text-muted-foreground">({plan.amount_minor} {plan.currency} / {plan.interval_count} {plan.interval})</span></span>
            </label>;
          })}
        </div>}
      </fieldset>}

      <div className="flex items-center gap-6">
        <div className="flex items-center gap-2">
<Switch id="is_active" checked={!!watch("is_active")} onCheckedChange={(v) => setValue("is_active", v, { shouldDirty: true })} disabled={isPending} />
          <Label htmlFor="is_active">{t("common.active")}</Label>
        </div>
        <div className="flex items-center gap-2">
<Switch id="is_featured" checked={!!watch("is_featured")} onCheckedChange={(v) => setValue("is_featured", v, { shouldDirty: true })} disabled={isPending} />
          <Label htmlFor="is_featured">Featured</Label>
        </div>
        <div className="flex items-center gap-2">
<Switch id="is_preorder" checked={!!watch("is_preorder")} onCheckedChange={(v) => setValue("is_preorder", v, { shouldDirty: true })} disabled={isPending} />
          <Label htmlFor="is_preorder">Preorder</Label>
        </div>
        <div className="flex items-center gap-2">
          <Label htmlFor="min_tier">Min tier</Label>
          <Select value={String(watch("min_tier") ?? "")} onValueChange={(v) => setValue("min_tier", v, { shouldDirty: true, shouldValidate: true })} disabled={isPending}>
            <SelectTrigger className="w-36">
              <SelectValue placeholder="All shoppers" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="">All shoppers</SelectItem>
              <SelectItem value="bronze">bronze+</SelectItem>
              <SelectItem value="silver">silver+</SelectItem>
              <SelectItem value="gold">gold+</SelectItem>
              <SelectItem value="platinum">platinum</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="flex justify-end gap-2 pt-2">
        <Button type="submit" disabled={isPending}>
          {isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
          {isEdit ? t("common.save") : t("common.create")}
        </Button>
      </div>
    </form>
  );
}
