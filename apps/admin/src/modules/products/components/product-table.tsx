"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  useReactTable,
  getCoreRowModel,
  getSortedRowModel,
  createColumnHelper,
} from "@tanstack/react-table";
import { useServerSorting } from "@/hooks/use-server-sorting";
import { Edit, Trash2, Archive, RotateCcw } from "lucide-react";
import { DataTable } from "@/components/data-table";
import { Pagination } from "@/components/pagination";
import { Button } from "@/components/ui/button";
import { StatusBadge } from "@/components/status-badge";
import { Badge } from "@/components/ui/badge";
import { formatCurrency, formatDate, formatNumber } from "@/lib/utils";
import { useI18n } from "@/providers/i18n-provider";
import { DeleteDialog } from "@/components/delete-dialog";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { EmptyState } from "@/components/empty-state";
import { ErrorState } from "@/components/error-state";
import { useProducts, useDeleteProduct, useTransitionProduct } from "@/hooks/use-products";
import type { components } from "@/types/api";

type Product = components["schemas"]["Product"];
type PaginationMeta = components["schemas"]["PaginationMeta"];

interface ProductTableProps {
  onEdit: (product: Product) => void;
  onCreate: () => void;
  params: Record<string, unknown>;
  onParamsChange: (params: Record<string, unknown>) => void;
}

const columnHelper = createColumnHelper<Product>();

export function ProductTable({ onEdit, onCreate, params, onParamsChange }: ProductTableProps) {
  const { t } = useI18n();
  const { sorting, handleSortingChange } = useServerSorting(onParamsChange);
  const [deleteId, setDeleteId] = useState<number | null>(null);
  const [lifecycleConfirmation, setLifecycleConfirmation] = useState<{
    id: number;
    state: "draft" | "scheduled" | "published" | "archived";
    version?: number;
    name: string | null;
  } | null>(null);

  const { products, meta, isLoading, isError, error, refetch } = useProducts(params);
  const deleteMutation = useDeleteProduct();
  const lifecycleMutation = useTransitionProduct();
  const lastLifecycle = useRef<{ id: number; state: "draft" | "scheduled" | "published" | "archived"; version?: number } | null>(null);
  // The mutation result object is not referentially stable, so route the
  // submit through a ref to keep the callback (and table columns) stable.
  const lifecycleRef = useRef(lifecycleMutation);
  lifecycleRef.current = lifecycleMutation;
  const regionRef = useRef<HTMLDivElement>(null);
  const hadError = useRef(false);
  useEffect(() => {
    if (isError) {
      hadError.current = true;
      return;
    }
    if (hadError.current && !isLoading) {
      hadError.current = false;
      regionRef.current?.focus();
    }
  }, [isError, isLoading]);

  const runLifecycle = useCallback((id: number, state: "draft" | "scheduled" | "published" | "archived", version: number | undefined, name: string | null) => {
    setLifecycleConfirmation({ id, state, version, name });
  }, []);

  const confirmLifecycle = () => {
    const pending = lifecycleConfirmation;
    setLifecycleConfirmation(null);
    if (!pending) return;
    const variables = { id: pending.id, state: pending.state, version: pending.version };
    lastLifecycle.current = variables;
    lifecycleRef.current.mutate(variables);
  };

  const columns = useMemo(
    () => [
      columnHelper.accessor("name", {
        header: t("products.name"),
        cell: (info) => (
          <div className="font-medium max-w-[200px] truncate">{info.getValue()}</div>
        ),
      }),
      columnHelper.accessor("sku", {
        header: () => t("products.sku") || "SKU",
        cell: (info) => <span className="font-mono text-xs">{info.getValue()}</span>,
      }),
      columnHelper.display({
        id: "vendor",
        header: "Vendor",
        cell: (info) => <span className="text-xs">{info.row.original.vendor?.name ?? "House brand"}</span>,
      }),
      columnHelper.accessor("price", {
        header: t("products.price"),
        cell: (info) => <span className="font-mono">{formatCurrency(info.getValue() ?? 0)}</span>,
      }),
      columnHelper.accessor("stock", {
        header: t("products.stock"),
        cell: (info) => {
          const stock = info.getValue() ?? 0;
          const min = info.row.original.stock_min;
          return (
            <Badge variant={min && stock <= min ? "destructive" : stock === 0 ? "secondary" : "success"}>
              {stock}
            </Badge>
          );
        },
      }),
      columnHelper.accessor("is_active", {
        header: t("common.status"),
        cell: (info) => (
          <StatusBadge status={info.getValue() ? "active" : "inactive"} />
        ),
      }),
      columnHelper.accessor("created_at", {
        header: t("users.createdAt"),
        cell: (info) => (
          <span className="text-muted-foreground text-xs">{formatDate(info.getValue() ?? "")}</span>
        ),
      }),
      columnHelper.display({
        id: "actions",
        cell: (info) => (
          <div className="flex justify-end gap-1">
            <Button variant="ghost" size="icon" onClick={() => onEdit(info.row.original)} aria-label={`${t("a11y.edit")} ${info.row.original.name ?? "product"}`}>
              <Edit className="h-4 w-4" />
            </Button>
            <Button variant="ghost" size="icon" onClick={() => setDeleteId(info.row.original.id!)} aria-label={`${t("a11y.delete")} ${info.row.original.name ?? "product"}`}>
              <Trash2 className="h-4 w-4 text-destructive" />
            </Button>
            {info.row.original.status !== "archived" ? (
              <Button variant="ghost" size="icon" disabled={lifecycleMutation.isPending} onClick={() => runLifecycle(info.row.original.id!, "archived", (info.row.original as Product & { version?: number }).version, info.row.original.name ?? null)} aria-label={`Archive ${info.row.original.name ?? "product"}`}>
                <Archive className="h-4 w-4" />
              </Button>
            ) : (
              <Button variant="ghost" size="icon" disabled={lifecycleMutation.isPending} onClick={() => runLifecycle(info.row.original.id!, "draft", (info.row.original as Product & { version?: number }).version, info.row.original.name ?? null)} aria-label={`Restore ${info.row.original.name ?? "product"}`}>
                <RotateCcw className="h-4 w-4" />
              </Button>
            )}
          </div>
        ),
      }),
    ],
    [onEdit, runLifecycle, t],
  );

  const table = useReactTable({
    data: products,
    columns,
    state: { sorting },
    onSortingChange: handleSortingChange,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    manualSorting: true,
  });

  if (isError) {
    return <ErrorState message={(error as Error)?.message} onRetry={() => refetch()} />;
  }

  return (
    <>
      {!isLoading && products.length === 0 ? (
        <EmptyState
          title={t("common.noData")}
          description={t("products.title")}
          action={<Button onClick={onCreate}>{t("products.create")}</Button>}
        />
      ) : (
        <>
          <div ref={regionRef} role="region" tabIndex={-1} aria-label={t("products.title")}>
          <DataTable table={table} isLoading={isLoading} />
          <Pagination
            meta={meta as PaginationMeta}
            onPageChange={(page) => onParamsChange({ ...params, page })}
          />
          </div>
        </>
      )}
      {lifecycleMutation.isError && (
        <div role="alert" className="flex flex-wrap items-center gap-3 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm">
          <span>The lifecycle change was not accepted. Refresh before retrying.</span>
          <Button size="sm" variant="outline" disabled={lifecycleMutation.isPending} onClick={() => { if (lastLifecycle.current) lifecycleMutation.mutate(lastLifecycle.current); }}>
            Retry lifecycle change
          </Button>
        </div>
      )}
      <DeleteDialog
        open={deleteId !== null}
        onOpenChange={() => setDeleteId(null)}
        onConfirm={() => {
          if (deleteId) {
            deleteMutation.mutate(deleteId, { onSettled: () => setDeleteId(null) });
          }
        }}
        isPending={deleteMutation.isPending}
      />
      <ConfirmDialog
        open={lifecycleConfirmation !== null}
        title={lifecycleConfirmation?.state === "archived" ? "Archive product" : "Restore product"}
        description={
          lifecycleConfirmation
            ? `${lifecycleConfirmation.state === "archived" ? "Archive" : "Restore"} product ${lifecycleConfirmation.name ?? lifecycleConfirmation.id}? This changes its lifecycle state.`
            : undefined
        }
        confirmLabel={lifecycleConfirmation?.state === "archived" ? "Archive" : "Restore"}
        destructive={lifecycleConfirmation?.state === "archived"}
        isPending={lifecycleMutation.isPending}
        onCancel={() => setLifecycleConfirmation(null)}
        onConfirm={confirmLifecycle}
      />
    </>
  );
}
