"use client";

import { useState, useCallback, useEffect } from "react";
import { Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/components/page-header";
import { SearchInput } from "@/components/search-input";
import { CategoryTable } from "@/modules/categories/components/category-table";
import { CategoryFormDialog } from "@/modules/categories/components/category-form-dialog";
import { useCreateCategory, useUpdateCategory } from "@/hooks/use-categories";
import { useI18n } from "@/providers/i18n-provider";
import type { components } from "@/types/api";
import type { CreateCategoryFormData, UpdateCategoryFormData } from "@/modules/categories/schemas/category.schema";
import { CapabilityGate } from "@/components/capability-gate";

type Category = components["schemas"]["Category"];

export default function CategoriesPage() {
  const { t } = useI18n();
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<string | undefined>(undefined);
  const [order, setOrder] = useState<string | undefined>(undefined);
  const [editCategory, setEditCategory] = useState<Category | null>(null);
  const [showCreate, setShowCreate] = useState(false);

  const createMutation = useCreateCategory();
  const updateMutation = useUpdateCategory(editCategory?.id ?? 0);

  const params = { page, search: search || undefined, sort, order, per_page: 10 };

  useEffect(() => {
    const url = new URL(window.location.href);
    for (const [key, value] of Object.entries({ search, page: page > 1 ? String(page) : "", sort, order })) {
      if (value) url.searchParams.set(key, value);
      else url.searchParams.delete(key);
    }
    window.history.replaceState({}, "", `${url.pathname}${url.search}`);
  }, [search, page, sort, order]);

  const handleEdit = useCallback((category: Category) => setEditCategory(category), []);

  const handleCreateSubmit = useCallback(
    (data: CreateCategoryFormData | UpdateCategoryFormData) => createMutation.mutate(data as components["schemas"]["CreateCategoryRequest"], { onSuccess: () => setShowCreate(false) }),
    [createMutation],
  );

  const handleEditSubmit = useCallback(
    (data: CreateCategoryFormData | UpdateCategoryFormData) => updateMutation.mutate(data as components["schemas"]["UpdateCategoryRequest"], { onSuccess: () => setEditCategory(null) }),
    [updateMutation],
  );

  return <CapabilityGate capability="products">
  <>
    <div className="space-y-4">
      <PageHeader title={t("categories.title")} description={t("categories.title")}>
        <Button onClick={() => setShowCreate(true)}>
          <Plus className="mr-2 h-4 w-4" />
          {t("categories.create")}
        </Button>
      </PageHeader>

      <div className="flex flex-wrap items-center gap-2">
        <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t("common.search") + " " + t("categories.title") + "..."} />
      </div>

      <CategoryTable
        onEdit={handleEdit}
        onCreate={() => setShowCreate(true)}
        params={params}
        onParamsChange={(p) => { if (p.page) setPage(p.page as number);
          if ("sort" in p) setSort(p.sort as string | undefined);
          if ("order" in p) setOrder(p.order as string | undefined); }}
      />

      <CategoryFormDialog
        open={showCreate}
        onOpenChange={setShowCreate}
        onSubmit={handleCreateSubmit}
        isPending={createMutation.isPending}
      />

      {editCategory && (
        <CategoryFormDialog
          open={!!editCategory}
          onOpenChange={() => setEditCategory(null)}
          category={editCategory}
          onSubmit={handleEditSubmit}
          isPending={updateMutation.isPending}
        />
      )}
    </div>
  </>
  </CapabilityGate>;
}
