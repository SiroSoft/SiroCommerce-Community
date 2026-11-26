"use client";

import { useState, useCallback, useEffect } from "react";
import { Download, Plus, Upload } from "lucide-react";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/components/page-header";
import { SearchInput } from "@/components/search-input";
import { ProductTable } from "@/modules/products/components/product-table";
import { ProductFormDialog } from "@/modules/products/components/product-form-dialog";
import { ProductImportDialog } from "@/modules/products/components/product-import-dialog";
import { useCreateProduct, useUpdateProduct } from "@/hooks/use-products";
import { useI18n } from "@/providers/i18n-provider";
import { productsService } from "@/services/products.service";
import { toast } from "@/hooks/use-toast";
import type { components } from "@/types/api";
import type { CreateProductFormData, UpdateProductFormData } from "@/modules/products/schemas/product.schema";
import { CapabilityGate } from "@/components/capability-gate";
import { useCategories } from "@/hooks/use-categories";

type Product = components["schemas"]["Product"];

export default function ProductsPage() {
  const { t } = useI18n();
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<string | undefined>(undefined);
  const [order, setOrder] = useState<string | undefined>(undefined);
  const [categoryId, setCategoryId] = useState<string>("");
  const [minPrice, setMinPrice] = useState("");
  const [maxPrice, setMaxPrice] = useState("");
  const [status, setStatus] = useState("");
  const [editProduct, setEditProduct] = useState<Product | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const [showImport, setShowImport] = useState(false);
  const [isExporting, setIsExporting] = useState(false);

  const createMutation = useCreateProduct();
  const updateMutation = useUpdateProduct(editProduct?.id ?? 0);
  const { categories } = useCategories({ per_page: 100 });

  const params = { page, search: search || undefined, sort, order, status: status || undefined, category_id: categoryId ? Number(categoryId) : undefined, min_price: minPrice ? Number(minPrice) : undefined, max_price: maxPrice ? Number(maxPrice) : undefined, per_page: 10 };

  useEffect(() => {
    const url = new URL(window.location.href);
    for (const [key, value] of Object.entries({ search, page: page > 1 ? String(page) : "", sort, order, status, category_id: categoryId, min_price: minPrice, max_price: maxPrice })) {
      if (value) url.searchParams.set(key, value);
      else url.searchParams.delete(key);
    }
    window.history.replaceState({}, "", `${url.pathname}${url.search}`);
  }, [search, page, sort, order, status, categoryId, minPrice, maxPrice]);

  const handleEdit = useCallback((product: Product) => setEditProduct(product), []);

  const handleExport = useCallback(async () => {
    setIsExporting(true);
    try {
      const result = await productsService.exportCsv();
      const url = URL.createObjectURL(result.blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = result.filename;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);
      toast({
        title: "Product export ready",
        description: result.truncated
          ? `Exported ${result.rows} of ${result.total} products (limit 10,000).`
          : `Exported ${result.rows} products.`,
      });
    } catch (error) {
      toast({ title: "Export failed", description: error instanceof Error ? error.message : "Could not export products.", variant: "destructive" });
    } finally {
      setIsExporting(false);
    }
  }, []);

  const handleCreateSubmit = useCallback(
    (data: CreateProductFormData | UpdateProductFormData) => createMutation.mutate(data as components["schemas"]["CreateProductRequest"], { onSuccess: () => setShowCreate(false) }),
    [createMutation],
  );

  const handleEditSubmit = useCallback(
    (data: CreateProductFormData | UpdateProductFormData) => updateMutation.mutate(data as components["schemas"]["UpdateProductRequest"], { onSuccess: () => setEditProduct(null) }),
    [updateMutation],
  );

  return <CapabilityGate capability="products">
    <>
    <div className="space-y-4">
      <PageHeader title={t("products.title")} description={t("products.title")}>
        <Button variant="outline" onClick={handleExport} disabled={isExporting}>
          <Download className="mr-2 h-4 w-4" />
          {isExporting ? "Exporting..." : "Export CSV"}
        </Button>
        <Button variant="outline" onClick={() => setShowImport(true)}>
          <Upload className="mr-2 h-4 w-4" />
          Import CSV
        </Button>
        <Button onClick={() => setShowCreate(true)}>
          <Plus className="mr-2 h-4 w-4" />
          {t("products.create")}
        </Button>
      </PageHeader>

      <div className="flex flex-wrap items-end gap-2" aria-label="Product filters">
        <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("common.search") + " " + t("products.title") + "..."} />
        <label className="grid gap-1 text-sm">Status<select className="h-10 rounded-md border bg-background px-3" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }} aria-label="Filter by product lifecycle"><option value="">All statuses</option><option value="draft">Draft</option><option value="scheduled">Scheduled</option><option value="published">Published</option><option value="archived">Archived</option></select></label>
        <label className="grid gap-1 text-sm">Category<select className="h-10 rounded-md border bg-background px-3" value={categoryId} onChange={(event) => { setCategoryId(event.target.value); setPage(1); }} aria-label="Filter by category"><option value="">All categories</option>{categories.filter((category, index, values) => category.id != null && values.findIndex((item) => item.id === category.id) === index).map((category) => <option key={category.id} value={String(category.id)}>{category.name}</option>)}</select></label>
        <label className="grid gap-1 text-sm">Min price<input className="h-10 w-28 rounded-md border bg-background px-3" type="number" min="0" step="0.01" value={minPrice} onChange={(event) => { setMinPrice(event.target.value); setPage(1); }} aria-label="Minimum price" /></label>
        <label className="grid gap-1 text-sm">Max price<input className="h-10 w-28 rounded-md border bg-background px-3" type="number" min="0" step="0.01" value={maxPrice} onChange={(event) => { setMaxPrice(event.target.value); setPage(1); }} aria-label="Maximum price" /></label>
      </div>

      <ProductTable
        onEdit={handleEdit}
        onCreate={() => setShowCreate(true)}
        params={params}
        onParamsChange={(p) => { if (p.page) setPage(p.page as number);
          if ("sort" in p) setSort(p.sort as string | undefined);
          if ("order" in p) setOrder(p.order as string | undefined); }}
      />

      <ProductFormDialog
        open={showCreate}
        onOpenChange={setShowCreate}
        onSubmit={handleCreateSubmit}
        isPending={createMutation.isPending}
      />

      <ProductImportDialog open={showImport} onOpenChange={setShowImport} />

      {editProduct && (
        <ProductFormDialog
          open={!!editProduct}
          onOpenChange={() => setEditProduct(null)}
          product={editProduct}
          onSubmit={handleEditSubmit}
          isPending={updateMutation.isPending}
        />
      )}
    </div>
    </>
  </CapabilityGate>;
}
