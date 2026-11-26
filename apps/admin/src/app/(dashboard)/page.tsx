"use client";

import { useMemo, useCallback, useRef, useState, useEffect } from "react";
import Link from "next/link";
import { Users, ShoppingCart, DollarSign, Package, TrendingUp, TrendingDown, ArrowRight, Plus, Eye, Settings, RefreshCw, Activity, Server, Database } from "lucide-react";
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from "recharts";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/components/page-header";
import { Skeleton } from "@/components/ui/skeleton";
import { ErrorState } from "@/components/error-state";
import { EmptyState } from "@/components/empty-state";
import { StatusBadge } from "@/components/status-badge";
import { Badge } from "@/components/ui/badge";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { cn, formatCurrency, formatDate, formatMinor, formatNumber, formatRelativeTime } from "@/lib/utils";

import { useI18n } from "@/providers/i18n-provider";
import { useDashboard } from "@/hooks/use-dashboard";
import { useAuth } from "@/hooks/use-auth";
import type { components } from "@/types/api";
import { adminConsoleService, type AuditEvent } from "@/services/admin-console.service";
import { WELCOME_TOUR_ID, WELCOME_TOUR_STEPS, hasSeenTour, useTour } from "@/components/guided-tour";
import { DASHBOARD_WIDGETS, useDashboardWidgets } from "@/hooks/use-dashboard-widgets";
import { BusinessPulse } from "@/modules/dashboard/business-pulse";

type DashboardStats = components["schemas"]["DashboardStatsResponse"];

const iconColors: Record<string, string> = {
  users: "bg-gradient-to-br from-blue-500/10 to-blue-600/20 text-blue-600 dark:from-blue-500/20 dark:to-blue-600/30 dark:text-blue-400 ring-1 ring-blue-500/20",
  orders: "bg-gradient-to-br from-orange-500/10 to-orange-600/20 text-orange-600 dark:from-orange-500/20 dark:to-orange-600/30 dark:text-orange-400 ring-1 ring-orange-500/20",
  products: "bg-gradient-to-br from-purple-500/10 to-purple-600/20 text-purple-600 dark:from-purple-500/20 dark:to-purple-600/30 dark:text-purple-400 ring-1 ring-purple-500/20",
  revenue: "bg-gradient-to-br from-emerald-500/10 to-emerald-600/20 text-emerald-600 dark:from-emerald-500/20 dark:to-emerald-600/30 dark:text-emerald-400 ring-1 ring-emerald-500/20",
};

const gradColors: Record<string, string> = {
  users: "from-blue-600 to-blue-400",
  orders: "from-orange-600 to-orange-400",
  products: "from-purple-600 to-purple-400",
  revenue: "from-emerald-600 to-emerald-400",
};

function StatCard({ id, title, value, icon: Icon, href }: { id: string; title: string; value: string; icon: React.ComponentType<{ className?: string }>; href?: string }) {
  const content = (
    <Card className="relative overflow-hidden transition-all duration-200 hover:-translate-y-1 hover:shadow-xl cursor-pointer group">
      <div className={cn("absolute inset-0 opacity-[0.03] bg-gradient-to-br dark:opacity-[0.08]", gradColors[id])} />
      <CardContent className="p-6 relative">
        <div className="flex items-center justify-between">
          <div className={cn("rounded-xl p-3 transition-transform group-hover:scale-110", iconColors[id] || "bg-muted")}>
            <Icon className="h-5 w-5" />
          </div>
          <div className={cn("h-16 w-16 rounded-full opacity-10 blur-2xl bg-gradient-to-br", gradColors[id])} />
        </div>
        <div className="mt-4">
          <p className="text-sm font-medium text-muted-foreground">{title}</p>
          <p className="text-2xl font-bold tracking-tight mt-1">{value}</p>
        </div>
      </CardContent>
    </Card>
  );

  if (href) return <Link href={href}>{content}</Link>;
  return content;
}

type RangeBlock = { orders: number; completed_orders: number; revenue_minor: number; refunds_minor: number; net_minor: number; aov_minor: number };

function RangeCards({ data, isLoading }: { data: unknown; isLoading: boolean }) {
  const ranges = (data as unknown as { ranges?: Record<string, RangeBlock>; scope?: { currency?: string } } | undefined)?.ranges;
  const currency = (data as unknown as { scope?: { currency?: string } } | undefined)?.scope?.currency ?? "VND";
  if (isLoading) return <div className="grid gap-4 grid-cols-1 sm:grid-cols-2"><StatCardSkeleton /><StatCardSkeleton /></div>;
  if (!ranges) return null;
  const cards = [["today", "Today"], ["week", "Last 7 days"]] as const;
  return <div className="grid gap-4 grid-cols-1 sm:grid-cols-2">{cards.map(([key, label]) => {
    const r = ranges[key];
    if (!r) return null;
    return <Card key={key}><CardHeader><CardTitle className="text-sm font-medium">{label}</CardTitle></CardHeader><CardContent className="space-y-1 text-sm">
      <div className="flex justify-between"><span className="text-muted-foreground">Orders</span><strong>{formatNumber(r.orders)}</strong></div>
      <div className="flex justify-between"><span className="text-muted-foreground">Revenue</span><strong>{formatMinor(r.revenue_minor, currency)}</strong></div>
      <div className="flex justify-between"><span className="text-muted-foreground">Refunds</span><strong>-{formatMinor(r.refunds_minor, currency)}</strong></div>
      <div className="flex justify-between border-t pt-1"><span className="text-muted-foreground">Net</span><strong>{formatMinor(r.net_minor, currency)}</strong></div>
      <div className="flex justify-between"><span className="text-muted-foreground">AOV</span><strong>{formatMinor(r.aov_minor, currency)}</strong></div>
    </CardContent></Card>;
  })}</div>;
}

function StatCardSkeleton() {
  return (
    <Card>
      <CardHeader className="pb-2">
        <Skeleton className="h-4 w-24" />
      </CardHeader>
      <CardContent>
        <Skeleton className="h-8 w-16" />
      </CardContent>
    </Card>
  );
}

export default function DashboardPage() {
  const { data, isLoading, isError, error, refetch, isRefetching } = useDashboard();
  const { user } = useAuth();
  const { t } = useI18n();
  const [lastChecked, setLastChecked] = useState<Date | null>(null);
  const [audit, setAudit] = useState<AuditEvent[]>([]);
  const [auditError, setAuditError] = useState(false);
  const hour = new Date().getHours();
  const greeting = hour < 12 ? t("greeting.morning") : hour < 18 ? t("greeting.afternoon") : t("greeting.evening");

  useEffect(() => {
    adminConsoleService.audit(8).then(setAudit).catch(() => setAuditError(true));
  }, []);

  const tour = useTour();
  const tourStarted = useRef(false);
  useEffect(() => {
    if (!isLoading && !tourStarted.current && !hasSeenTour(WELCOME_TOUR_ID)) {
      tourStarted.current = true;
      const timer = window.setTimeout(() => tour.start(WELCOME_TOUR_ID, WELCOME_TOUR_STEPS), 800);
      return () => window.clearTimeout(timer);
    }
  }, [isLoading, tour]);

  const handleRefresh = useCallback(() => {
    refetch();
    setLastChecked(new Date());
  }, [refetch]);

  const widgets = useDashboardWidgets();

  const stats = useMemo(
    () => [
      { id: "users", title: t("dashboard.totalUsers"), value: formatNumber(data?.total_users ?? 0), icon: Users, href: "/users" },
      { id: "orders", title: t("dashboard.totalOrders"), value: formatNumber(data?.total_orders ?? 0), icon: ShoppingCart, href: "/orders" },
      { id: "products", title: t("dashboard.totalProducts"), value: formatNumber(data?.total_products ?? 0), icon: Package, href: "/products" },
      { id: "revenue", title: t("dashboard.totalRevenue"), value: formatCurrency(data?.total_revenue ?? 0), icon: DollarSign, href: "/orders" },
    ],
    [data, t],
  );

  if (isError) {
    return (
      <div className="space-y-6">
        <PageHeader title={t("dashboard.title")} description={t("dashboard.overview")}>
          <Button variant="outline" size="sm" onClick={handleRefresh} disabled={isRefetching}>
            <RefreshCw className={`mr-2 h-4 w-4 ${isRefetching ? "animate-spin" : ""}`} />
            {t("dashboard.refresh")}
          </Button>
        </PageHeader>
        <ErrorState onRetry={() => refetch()} />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="rounded-xl bg-gradient-to-br from-primary/5 via-primary/10 to-primary/5 p-6 border">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">{greeting}, {user?.name || "there"} 👋</h1>
            <p className="text-muted-foreground mt-1">{t("dashboard.overview")}</p>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={() => widgets.setCustomizing((value) => !value)} aria-expanded={widgets.customizing}>
              Customize
            </Button>
            <Button variant="outline" size="sm" onClick={handleRefresh} disabled={isRefetching}>
              <RefreshCw className={`mr-2 h-4 w-4 ${isRefetching ? "animate-spin" : ""}`} />
              {t("dashboard.refresh")}
            </Button>
          </div>
        </div>
      </div>
      {widgets.customizing && (
        <Card>
          <CardHeader><CardTitle className="text-sm font-medium">Dashboard widgets</CardTitle></CardHeader>
          <CardContent className="flex flex-wrap gap-4">
            {DASHBOARD_WIDGETS.map((widget) => (
              <label key={widget.id} className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={widgets.visible(widget.id)} onChange={() => widgets.toggle(widget.id)} />
                {widget.label}
              </label>
            ))}
          </CardContent>
        </Card>
      )}

      {!isLoading && !isError && (data?.total_users ?? 0) + (data?.total_products ?? 0) + (data?.total_orders ?? 0) === 0 && (
        <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 flex items-start gap-3">
          <Database className="h-5 w-5 mt-0.5 text-amber-500 shrink-0" />
          <div>
            <p className="font-medium">{t("dashboard.emptyDbTitle")}</p>
            <p className="text-sm text-muted-foreground mt-1">
              {t("dashboard.emptyDbDesc")} <code className="px-1 rounded bg-muted">php siro db:seed</code>
            </p>
          </div>
        </div>
      )}

      {widgets.visible("stats") && <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4" data-tour="dashboard-stats">
        {isLoading
          ? Array.from({ length: 4 }).map((_, i) => <StatCardSkeleton key={i} />)
          : stats.map((s, index) => <StatCard key={`${s.id}-${index}`} {...s} />)}
      </div>}

      {widgets.visible("ranges") && <RangeCards data={data} isLoading={isLoading} />}

      <BusinessPulse />

      {data?.freshness?.status === "stale" && <div role="status" className="rounded-md border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm">Dashboard data is older than expected. Refresh to verify current operations.</div>}

      <div className="grid gap-4 lg:grid-cols-3">
        {widgets.visible("recent") && <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle className="text-sm font-medium">{t("dashboard.recentOrders")}</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="space-y-4">
                {Array.from({ length: 5 }).map((_, i) => (
                  <div key={i} className="flex items-start gap-3">
                    <Skeleton className="h-2 w-2 mt-2 rounded-full" />
                    <div className="flex-1 space-y-1">
                      <Skeleton className="h-4 w-48" />
                      <Skeleton className="h-3 w-64" />
                    </div>
                  </div>
                ))}
              </div>
            ) : data?.recent_activity && data.recent_activity.length > 0 ? (
              <div className="space-y-4">
                {data.recent_activity.slice(0, 8).map((activity, index) => (
                  <div key={`${activity.id}-${index}`} className="flex items-start gap-3">
                    <Avatar className="h-8 w-8 shrink-0">
                      <AvatarFallback className="text-xs bg-primary/10 text-primary">
                        {(activity.user ?? "?").charAt(0).toUpperCase()}
                      </AvatarFallback>
                    </Avatar>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium truncate">{activity.action}</p>
                      <p className="text-xs text-muted-foreground truncate">{activity.description}</p>
                      <p className="text-xs text-muted-foreground mt-1">{formatRelativeTime(activity.created_at!)}</p>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <EmptyState title={t("common.noData")} description={t("dashboard.activityEmpty")} />
            )}
          </CardContent>
        </Card>}

        <div className="space-y-4">
          {widgets.visible("status") && <Card>
            <CardHeader>
              <CardTitle className="text-sm font-medium">{t("dashboard.ordersByStatus")}</CardTitle>
            </CardHeader>
            <CardContent>
              {isLoading ? (
                <div className="space-y-3">
                  {Array.from({ length: 3 }).map((_, i) => (
                    <div key={i} className="flex items-center justify-between">
                      <Skeleton className="h-4 w-16" />
                      <Skeleton className="h-4 w-10" />
                    </div>
                  ))}
                </div>
              ) : data?.orders_by_status && Object.keys(data.orders_by_status).length > 0 ? (
                <div className="space-y-3">
                  {Object.entries(data.orders_by_status).map(([status, count]) => (
                    <div key={status} className="flex items-center justify-between">
                      <span className="text-sm text-muted-foreground capitalize">{status}</span>
                      <span className="text-sm font-medium">{formatNumber(count ?? 0)}</span>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-sm text-muted-foreground py-4 text-center">{t("common.noData")}</p>
              )}
            </CardContent>
          </Card>}

          <Card>
            <CardHeader>
              <CardTitle className="text-sm font-medium">{t("dashboard.apiStatus")}</CardTitle>
            </CardHeader>
            <CardContent>
              {isLoading ? (
                <div className="space-y-3">
                  {Array.from({ length: 4 }).map((_, i) => (
                    <div key={i} className="flex items-center justify-between">
                      <Skeleton className="h-4 w-16" />
                      <Skeleton className="h-4 w-20" />
                    </div>
                  ))}
                </div>
              ) : data?.api_status ? (
                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-muted-foreground">{t("common.status")}</span>
                    <StatusBadge status={data.api_status.status ?? "down"} />
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-muted-foreground">{t("dashboard.version")}</span>
                    <span className="text-sm font-medium">{data.api_status.version}</span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-muted-foreground">{t("dashboard.uptime")}</span>
                    <span className="text-sm font-medium">{Math.round((data.api_status.uptime ?? 0) / 3600)}h</span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-muted-foreground">{t("dashboard.responseTime")}</span>
                    <span className="text-sm font-medium">{data.api_status.response_time}ms</span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-muted-foreground">{t("dashboard.lastChecked")}</span>
                    <span className="text-sm font-medium">{lastChecked ? formatRelativeTime(lastChecked) : "–"}</span>
                  </div>
                </div>
              ) : (
                <p className="text-sm text-muted-foreground py-4 text-center">{t("dashboard.apiStatusUnavailable")}</p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-sm font-medium">{t("dashboard.quickActions")}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-2">
              <Link href="/users/new" className="group">
                <Button variant="default" className="w-full justify-start">
                  <Plus className="mr-2 h-4 w-4" /> {t("dashboard.newUser")}
                  <ArrowRight className="ml-auto h-4 w-4 opacity-0 -translate-x-2 transition-all group-hover:opacity-100 group-hover:translate-x-0" />
                </Button>
              </Link>
              <Link href="/orders" className="group">
                <Button variant="secondary" className="w-full justify-start">
                  <Eye className="mr-2 h-4 w-4" /> {t("dashboard.viewOrders")}
                  <ArrowRight className="ml-auto h-4 w-4 opacity-0 -translate-x-2 transition-all group-hover:opacity-100 group-hover:translate-x-0" />
                </Button>
              </Link>
              <Link href="/products" className="group">
                <Button variant="secondary" className="w-full justify-start">
                  <Package className="mr-2 h-4 w-4" /> {t("dashboard.manageProducts")}
                  <ArrowRight className="ml-auto h-4 w-4 opacity-0 -translate-x-2 transition-all group-hover:opacity-100 group-hover:translate-x-0" />
                </Button>
              </Link>
              <Link href="/settings" className="group">
                <Button variant="secondary" className="w-full justify-start">
                  <Settings className="mr-2 h-4 w-4" /> {t("common.settings")}
                  <ArrowRight className="ml-auto h-4 w-4 opacity-0 -translate-x-2 transition-all group-hover:opacity-100 group-hover:translate-x-0" />
                </Button>
              </Link>
            </CardContent>
          </Card>
        </div>
      </div>

      {widgets.visible("revenue") && <Card>
        <CardHeader><CardTitle className="text-sm font-medium">{t("dashboard.monthlyRevenue")}</CardTitle></CardHeader>
        <CardContent>
          {isLoading ? (
            <Skeleton className="h-[300px] w-full" />
          ) : isError ? (
            <ErrorState message={t("errors.serverError")} onRetry={() => refetch()} />
          ) : data?.monthly_revenue && data.monthly_revenue.length > 0 ? (
            <ResponsiveContainer width="100%" height={300}>
              <BarChart data={data.monthly_revenue}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                <XAxis dataKey="month" className="text-xs fill-muted-foreground" />
                <YAxis className="text-xs fill-muted-foreground" />
                <Tooltip contentStyle={{ backgroundColor: "hsl(var(--popover))", border: "1px solid hsl(var(--border))", borderRadius: "var(--radius)" }} />
                <Bar dataKey="revenue" fill="hsl(var(--primary))" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          ) : (
            <EmptyState title={t("dashboard.noRevenue")} description={t("dashboard.noRevenueDesc")} />
          )}
        </CardContent>
      </Card>}

      {widgets.visible("audit") && <Card>
        <CardHeader><CardTitle className="text-sm font-medium">Audit timeline</CardTitle></CardHeader>
        <CardContent>
          {auditError ? <p className="text-sm text-destructive">Audit activity is unavailable for this scope.</p> : audit.length === 0 ? <p className="text-sm text-muted-foreground">No recent audit activity.</p> : <div className="space-y-3">{audit.map((event) => <div key={event.id} className="flex items-start justify-between gap-4 border-b pb-3 last:border-0"><div><p className="text-sm font-medium">{event.action}</p><p className="text-xs text-muted-foreground">Actor: {event.actor || "system"}</p></div><time className="shrink-0 text-xs text-muted-foreground">{formatRelativeTime(event.created_at)}</time></div>)}</div>}
        </CardContent>
      </Card>}
    </div>
  );
}
