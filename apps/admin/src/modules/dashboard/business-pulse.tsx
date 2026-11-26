"use client";

import { useState } from "react";
import Link from "next/link";
import { Activity, AlertTriangle, ArrowRight, PackageSearch, RefreshCw, RotateCcw, ShoppingCart } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { EmptyState } from "@/components/empty-state";
import { Skeleton } from "@/components/ui/skeleton";
import { useDashboardBi } from "@/hooks/use-dashboard-bi";
import { DASHBOARD_BI_RANGES, normalizeSalesBlocks } from "@/services/dashboard-bi.service";
import { cn, formatMinor, formatNumber } from "@/lib/utils";
import type { DashboardBi, DashboardBiRange, DashboardBiSalesBlock } from "@/types/dashboard";

const FUNNEL_STAGES = [
  { key: "sessions", label: "Sessions" },
  { key: "product_views", label: "Product views" },
  { key: "add_to_cart", label: "Add to cart" },
  { key: "checkout_started", label: "Checkout started" },
  { key: "orders", label: "Orders" },
] as const;

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border bg-muted/30 p-3">
      <p className="text-xs font-medium text-muted-foreground">{label}</p>
      <p className="mt-1 text-lg font-semibold tracking-tight">{value}</p>
    </div>
  );
}

function SalesTrend({ block }: { block: DashboardBiSalesBlock }) {
  const max = Math.max(1, ...block.by_day.map((day) => day.gross_minor));
  return (
    <div aria-label={`${block.currency} sales trend`} className="flex h-24 items-end gap-0.5" role="img">
      {block.by_day.length === 0 ? (
        <p className="text-xs text-muted-foreground">No daily sales.</p>
      ) : (
        block.by_day.map((day) => (
          <div
            key={day.date}
            className="min-w-0 flex-1 rounded-t bg-primary/70"
            data-testid={`sales-bar-${block.currency}-${day.date}`}
            style={{ height: `${Math.max(day.gross_minor > 0 ? 4 : 1, Math.round((day.gross_minor / max) * 100))}%` }}
            title={`${day.date}: ${formatMinor(day.gross_minor, block.currency)} (${formatNumber(day.orders)} orders)`}
          />
        ))
      )}
    </div>
  );
}

function SalesBlocks({ blocks }: { blocks: DashboardBiSalesBlock[] }) {
  const empty = blocks.length === 0 || blocks.every((block) => block.gross_minor === 0 && block.orders === 0 && block.refunds_minor === 0);
  if (empty) {
    return <EmptyState title="No sales in this range" description="Orders and refunds in the selected window will appear here." />;
  }
  return (
    <div className="space-y-4">
      {blocks.map((block) => (
        <div key={block.currency} className="space-y-3 rounded-lg border p-4">
          <div className="flex items-center justify-between">
            <p className="text-sm font-semibold">{block.currency}</p>
            <span className="text-xs text-muted-foreground">{formatNumber(block.orders)} orders</span>
          </div>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Metric label="Gross sales" value={formatMinor(block.gross_minor, block.currency)} />
            <Metric label="Orders" value={formatNumber(block.orders)} />
            <Metric label="Average order value" value={formatMinor(block.aov_minor, block.currency)} />
            <Metric label="Refunds" value={formatMinor(block.refunds_minor, block.currency)} />
          </div>
          <SalesTrend block={block} />
        </div>
      ))}
    </div>
  );
}

function Funnel({ funnel }: { funnel: DashboardBi["funnel"] }) {
  const max = Math.max(1, ...FUNNEL_STAGES.map((stage) => funnel[stage.key]));
  return (
    <div className="space-y-3">
      {FUNNEL_STAGES.map((stage, index) => {
        const count = funnel[stage.key];
        const previous = index === 0 ? null : funnel[FUNNEL_STAGES[index - 1].key];
        const conversion = previous === null || previous <= 0 ? null : Math.round((count / previous) * 100);
        const highlight = stage.key === "orders";
        return (
          <div key={stage.key} className="space-y-1">
            <div className="flex items-center justify-between text-sm">
              <span className={cn("font-medium", highlight && "text-primary")}>{stage.label}</span>
              <span className="text-muted-foreground">
                {formatNumber(count)}
                {conversion !== null && <span className={cn("ml-2 font-medium", highlight ? "text-primary" : "text-foreground")}>{conversion}%</span>}
              </span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-muted">
              <div
                className={cn("h-full rounded-full", highlight ? "bg-primary" : "bg-primary/45")}
                style={{ width: `${count > 0 ? Math.max(2, Math.round((count / max) * 100)) : 0}%` }}
              />
            </div>
          </div>
        );
      })}
      <p className="text-xs text-muted-foreground">Checkout to order is highlighted. Funnel counts are best-effort analytics events.</p>
    </div>
  );
}

function TopProducts({ products, currency }: { products: DashboardBi["top_products"]; currency: string }) {
  if (products.length === 0) return <p className="text-sm text-muted-foreground">No product sales in this range.</p>;
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
            <th className="py-2 pr-2 font-medium">Product</th>
            <th className="py-2 pr-2 text-right font-medium">Qty</th>
            <th className="py-2 text-right font-medium">Revenue</th>
          </tr>
        </thead>
        <tbody>
          {products.map((product) => (
            <tr key={product.product_id || product.slug || product.name} className="border-b last:border-0">
              <td className="py-2 pr-2">
                {product.product_id ? (
                  <Link className="font-medium hover:underline" href={`/products/${encodeURIComponent(product.product_id)}`}>
                    {product.name || product.slug || product.product_id}
                  </Link>
                ) : (
                  <span className="font-medium">{product.name}</span>
                )}
              </td>
              <td className="py-2 pr-2 text-right">{formatNumber(product.qty)}</td>
              <td className="py-2 text-right">{formatMinor(product.revenue_minor, currency)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function LowStock({ items }: { items: DashboardBi["low_stock"] }) {
  if (items.length === 0) return <p className="text-sm text-muted-foreground">No variants at or below 5 units.</p>;
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
            <th className="py-2 pr-2 font-medium">Variant</th>
            <th className="py-2 pr-2 font-medium">SKU</th>
            <th className="py-2 text-right font-medium">Available</th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr key={item.variant_id} className="border-b last:border-0">
              <td className="py-2 pr-2">
                <Link className="font-medium hover:underline" href={`/inventory?variant=${encodeURIComponent(item.variant_id)}`}>
                  {item.name || item.variant_id}
                </Link>
              </td>
              <td className="py-2 pr-2 text-muted-foreground">{item.sku || "—"}</td>
              <td className="py-2 text-right font-medium">{formatNumber(item.available)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function OpsRow({ ops }: { ops: DashboardBi["ops"] }) {
  const items = [
    { key: "failed_payments", label: "Failed payments", value: ops.failed_payments, icon: AlertTriangle },
    { key: "pending_refunds", label: "Pending refunds", value: ops.pending_refunds, icon: RotateCcw },
    { key: "queue_dead_letters", label: "Dead letters", value: ops.queue_dead_letters, icon: PackageSearch },
    { key: "open_ops_alerts", label: "Open ops alerts", value: ops.open_ops_alerts, icon: Activity },
  ];
  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      {items.map((item) => (
        <Link key={item.key} href="/operations" className="group rounded-lg border p-3 transition-colors hover:border-primary/60 hover:bg-primary/5">
          <div className="flex items-center justify-between">
            <item.icon className="h-4 w-4 text-muted-foreground" />
            <ArrowRight className="h-4 w-4 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
          </div>
          <p className="mt-2 text-2xl font-semibold">{formatNumber(item.value)}</p>
          <p className="text-xs text-muted-foreground">{item.label}</p>
        </Link>
      ))}
    </div>
  );
}

export function BusinessPulse() {
  const [range, setRange] = useState<DashboardBiRange>("7d");
  const { data, isLoading, isError, refetch, isRefetching } = useDashboardBi(range);
  const blocks = normalizeSalesBlocks(data?.sales);
  const currency = blocks[0]?.currency ?? "VND";

  return (
    <Card data-testid="business-pulse">
      <CardHeader className="gap-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex items-center gap-2">
            <ShoppingCart className="h-4 w-4 text-primary" />
            <CardTitle className="text-sm font-medium">Business pulse</CardTitle>
          </div>
          <div className="flex flex-wrap items-center gap-1">
            <div className="flex rounded-md border p-0.5" role="group" aria-label="Business pulse range">
              {DASHBOARD_BI_RANGES.map((option) => (
                <Button
                  key={option.value}
                  type="button"
                  size="sm"
                  variant={range === option.value ? "default" : "ghost"}
                  className="h-7 px-2 text-xs"
                  aria-label={option.label}
                  aria-pressed={range === option.value}
                  onClick={() => setRange(option.value)}
                >
                  {option.value}
                </Button>
              ))}
            </div>
            <Button variant="outline" size="sm" className="h-7 px-2 text-xs" onClick={() => refetch()} disabled={isRefetching}>
              <RefreshCw className={`mr-1 h-3.5 w-3.5 ${isRefetching ? "animate-spin" : ""}`} />
              Refresh
            </Button>
          </div>
        </div>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <div className="grid gap-4 lg:grid-cols-2">
            <Skeleton className="h-40 w-full" />
            <Skeleton className="h-40 w-full" />
            <Skeleton className="h-40 w-full" />
            <Skeleton className="h-40 w-full" />
          </div>
        ) : isError ? (
          <EmptyState
            title="Business pulse unavailable"
            description="Operational analytics could not be loaded. Retry to try again."
            action={<Button variant="outline" size="sm" onClick={() => refetch()}>Retry</Button>}
          />
        ) : data ? (
          <div className="space-y-6">
            <section aria-label="Sales">
              <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Sales</h3>
              <SalesBlocks blocks={blocks} />
            </section>

            <div className="grid gap-4 lg:grid-cols-2">
              <section aria-label="Conversion funnel" className="rounded-lg border p-4">
                <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Conversion funnel</h3>
                <Funnel funnel={data.funnel} />
              </section>
              <section aria-label="Operations" className="rounded-lg border p-4">
                <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Operations</h3>
                <OpsRow ops={data.ops} />
              </section>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
              <section aria-label="Top products" className="rounded-lg border p-4">
                <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Top products</h3>
                <TopProducts products={data.top_products} currency={currency} />
              </section>
              <section aria-label="Low stock" className="rounded-lg border p-4">
                <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Low stock</h3>
                <LowStock items={data.low_stock} />
              </section>
            </div>
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}
