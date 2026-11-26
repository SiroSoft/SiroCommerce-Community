"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@/lib/utils";
import {
  LayoutDashboard,
  Users,
  ShoppingCart,
  FileText,
  Settings,
  ChevronLeft,
  LogOut,
  Package,
  Tags,
  User,
  ClipboardList,
  Boxes,
  Truck,
  BarChart3,
  SlidersHorizontal,
  Building2,
  Paintbrush,
  Activity,
  Megaphone,
  CreditCard,
  Workflow,
  ShieldCheck,
  Zap,
  Image,
  Blocks,
  Sparkles,
  Store,
  Rocket,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { useAuth } from "@/hooks/use-auth";
import { useI18n } from "@/providers/i18n-provider";
import { APP_NAME } from "@/lib/constants";
import { canOperateMerchant, canUseRoute } from "@/modules/merchant/permissions";
import { useEffect, useState } from "react";
import { Puzzle } from "lucide-react";
import { extensionsService, type ExtensionView } from "@/services/extensions.service";

function ExtensionNavEntries({ collapsed, pathname }: { collapsed: boolean; pathname: string }) {
  const { user } = useAuth();
  const [views, setViews] = useState<ExtensionView[]>([]);
  useEffect(() => {
    if (!canUseRoute(user, ["owner", "admin"])) return;
    void extensionsService.views().then(setViews).catch(() => setViews([]));
  }, [user]);
  if (views.length === 0) return null;
  return (
    <>
      {views.map((view) => {
        const href = `/extensions/${encodeURIComponent(view.id)}`;
        const isActive = pathname === href || pathname.startsWith(href + "/");
        return (
          <Link
            key={view.id}
            href={href}
            aria-label={collapsed ? view.label : undefined}
            title={collapsed ? view.label : undefined}
            aria-current={isActive ? "page" : undefined}
            className={cn(
              "flex items-center gap-3 rounded-lg px-3 py-2 pl-9 text-sm font-medium transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring active:scale-[0.98]",
              isActive
                ? "bg-sidebar-accent text-sidebar-foreground"
                : "text-sidebar-muted hover:bg-sidebar-accent hover:text-sidebar-foreground",
              collapsed && "justify-center px-2 pl-2",
            )}
          >
            <Puzzle className="h-4 w-4 shrink-0" />
            {!collapsed && <span>{view.label}</span>}
          </Link>
        );
      })}
    </>
  );
}

interface SidebarProps {
  collapsed: boolean;
  onToggle: () => void;
}

export function Sidebar({ collapsed, onToggle }: SidebarProps) {
  const pathname = usePathname();
  const { logout, user } = useAuth();
  const { t } = useI18n();

  const navItems: Array<{ href: string; label: string; icon: typeof LayoutDashboard; capability?: Parameters<typeof canOperateMerchant>[1]; roles?: readonly string[] }> = [
    { href: "/", label: t("common.dashboard"), icon: LayoutDashboard },
    { href: "/launch", label: "Launch checklist", icon: Rocket, capability: "products" },
    { href: "/action-center", label: "Action Center", icon: ClipboardList, roles: ["owner", "admin"] },
    { href: "/operations", label: "Operations", icon: Activity, capability: "operations" },
    { href: "/finance", label: "Finance", icon: CreditCard, roles: ["owner", "admin", "finance"] },
    { href: "/tax", label: "Tax rules", icon: CreditCard, capability: "finance" },
    { href: "/subscriptions", label: "Subscriptions", icon: CreditCard, capability: "subscriptions" },
    { href: "/campaigns", label: "Campaign automation", icon: Megaphone, capability: "campaigns" },
    { href: "/users", label: t("common.users"), icon: Users, capability: "users" },
    { href: "/orders", label: t("common.orders"), icon: ShoppingCart, capability: "orders" },
    { href: "/fulfillment", label: "Fulfillment", icon: Truck, capability: "inventory" },
    { href: "/inventory", label: "Inventory", icon: Boxes, capability: "inventory" },
    { href: "/products", label: t("common.products"), icon: Package, capability: "products" },
    { href: "/vendors", label: "Vendors", icon: Store, capability: "products" },
    { href: "/commerce", label: "Commerce management", icon: SlidersHorizontal, capability: "products" },
    { href: "/extensions", label: "Extensions", icon: Blocks, roles: ["owner", "admin"] },
    { href: "/flash-sales", label: "Flash sales", icon: Zap, capability: "products" },
    { href: "/pickup-points", label: "Pickup points", icon: Truck, capability: "inventory" },
    { href: "/gift-cards", label: "Gift cards & credit", icon: CreditCard, capability: "products" },
    { href: "/ai-copilot", label: "AI copilot queue", icon: Sparkles, capability: "products" },
    { href: "/media", label: "Media library", icon: Image, capability: "cms" },
    { href: "/b2b", label: "B2B operations", icon: Building2, capability: "b2b" },
    { href: "/categories", label: t("common.categories"), icon: Tags, capability: "products" },
    { href: "/posts", label: t("common.posts"), icon: FileText, capability: "cms" },
    { href: "/pages", label: "Pages", icon: FileText, capability: "cms" },
    { href: "/storefront/theme", label: "Theme builder", icon: Paintbrush, capability: "cms" },
    { href: "/storefront/themes", label: "Theme catalog", icon: Paintbrush, capability: "cms" },
    { href: "/reporting", label: "Reporting", icon: BarChart3, capability: "reports" },
    { href: "/admin-workbench", label: "Admin workbench", icon: Workflow, capability: "admin_workbench" },
    { href: "/configuration", label: "Configuration", icon: SlidersHorizontal, roles: ["owner", "admin"] },
    { href: "/search-seo", label: "Search SEO", icon: BarChart3, capability: "seo" },
    { href: "/parity", label: "Parity diagnostics", icon: ShieldCheck },
    { href: "/profile", label: t("common.profile"), icon: User },
    { href: "/settings", label: t("common.settings"), icon: Settings, roles: ["owner", "admin"] },
  ];

  return (
    <aside
      data-tour="sidebar"
      className={cn(
        "fixed left-0 top-0 z-40 hidden h-screen flex-col border-r bg-sidebar transition-all duration-300 lg:flex",
        collapsed ? "w-16" : "w-60",
      )}
    >
      <div className={cn("flex h-14 items-center border-b border-sidebar-border px-4 gap-2", collapsed && "justify-center")}>
        <Link href="/" className="flex items-center gap-2 truncate">
          <img src="/logo.svg" alt={t("a11y.appLogo")} className="h-7 w-7 shrink-0" />
          {!collapsed && <span className="text-sm font-bold text-sidebar-foreground">{APP_NAME}</span>}
        </Link>
        <Button
          variant="ghost"
          size="icon"
          onClick={onToggle}
          className={cn("ml-auto text-sidebar-foreground hover:bg-sidebar-accent", collapsed && "ml-0")}
          aria-label={collapsed ? t("a11y.expandSidebar") : t("a11y.collapseSidebar")}
        >
          <ChevronLeft className={cn("h-4 w-4 transition-transform", collapsed && "rotate-180")} />
        </Button>
      </div>

      <nav className="flex-1 space-y-1 p-2 overflow-y-auto">
        {navItems.filter((item) => (!item.capability || canOperateMerchant(user, item.capability)) && (!item.roles || canUseRoute(user, item.roles))).map((item) => {
          const Icon = item.icon;
          const isActive = pathname === item.href || pathname.startsWith(item.href + "/");
          return (
            <Link
              key={item.href}
              href={item.href}
              aria-label={collapsed ? item.label : undefined}
              title={collapsed ? item.label : undefined}
              aria-current={isActive ? "page" : undefined}
              className={cn(
                "flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring active:scale-[0.98]",
                isActive
                  ? "bg-sidebar-accent text-sidebar-foreground"
                  : "text-sidebar-muted hover:bg-sidebar-accent hover:text-sidebar-foreground",
                collapsed && "justify-center px-2",
              )}
            >
              <Icon className="h-4 w-4 shrink-0" />
              {!collapsed && <span>{item.label}</span>}
            </Link>
          );
        })}
        <ExtensionNavEntries collapsed={collapsed} pathname={pathname} />
      </nav>

      <Separator className="bg-sidebar-border" />

      <div className="p-2">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => logout()}
          className={cn(
            "w-full justify-start text-sidebar-muted hover:bg-sidebar-accent hover:text-sidebar-foreground active:scale-[0.98]",
            collapsed && "justify-center px-2",
          )}
        >
          <LogOut className="h-4 w-4 shrink-0" />
          {!collapsed && <span className="ml-3">{t("common.logout")}</span>}
        </Button>
      </div>
    </aside>
  );
}
