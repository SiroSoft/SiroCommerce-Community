"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useCallback, useRef } from "react";
import { useAuth } from "@/hooks/use-auth";
import { X, LayoutDashboard, Users, ShoppingCart, FileText, Settings, Package, Tags, User, LogOut, SlidersHorizontal, Workflow, ClipboardList, Boxes, Truck, BarChart3, Building2, Paintbrush, Activity, Megaphone, CreditCard } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { useI18n } from "@/providers/i18n-provider";
import { APP_NAME } from "@/lib/constants";
import { canOperateMerchant, canUseRoute } from "@/modules/merchant/permissions";

interface MobileSidebarProps {
  open: boolean;
  onClose: () => void;
}

export function MobileSidebar({ open, onClose }: MobileSidebarProps) {
  const pathname = usePathname();
  const { logout, user } = useAuth();
  const { t } = useI18n();
  const closeButtonRef = useRef<HTMLButtonElement>(null);
  const wasOpen = useRef(false);

  const navItems: Array<{ href: string; label: string; icon: typeof LayoutDashboard; capability?: Parameters<typeof canOperateMerchant>[1]; roles?: readonly string[] }> = [
    { href: "/", label: t("common.dashboard"), icon: LayoutDashboard },
    { href: "/action-center", label: "Action Center", icon: ClipboardList, roles: ["owner", "admin"] },
    { href: "/operations", label: "Operations", icon: Activity, capability: "operations" },
    { href: "/finance", label: "Finance", icon: CreditCard, capability: "finance" },
    { href: "/tax", label: "Tax rules", icon: CreditCard, capability: "finance" },
    { href: "/subscriptions", label: "Subscriptions", icon: CreditCard, capability: "subscriptions" },
    { href: "/campaigns", label: "Campaign automation", icon: Megaphone, capability: "campaigns" },
    { href: "/users", label: t("common.users"), icon: Users, capability: "users" },
    { href: "/orders", label: t("common.orders"), icon: ShoppingCart, capability: "orders" },
    { href: "/fulfillment", label: "Fulfillment", icon: Truck, capability: "inventory" },
    { href: "/inventory", label: "Inventory", icon: Boxes, capability: "inventory" },
    { href: "/products", label: t("common.products"), icon: Package, capability: "products" },
    { href: "/commerce", label: "Commerce management", icon: SlidersHorizontal, capability: "products" },
    { href: "/b2b", label: "B2B operations", icon: Building2, capability: "b2b" },
    { href: "/categories", label: t("common.categories"), icon: Tags, capability: "products" },
    { href: "/posts", label: t("common.posts"), icon: FileText, capability: "cms" },
    { href: "/storefront/theme", label: "Theme builder", icon: Paintbrush, capability: "cms" },
    { href: "/storefront/themes", label: "Theme catalog", icon: Paintbrush, capability: "cms" },
    { href: "/reporting", label: "Reporting", icon: BarChart3, capability: "reports" },
    { href: "/admin-workbench", label: "Admin workbench", icon: Workflow, capability: "admin_workbench" },
    { href: "/profile", label: t("common.profile"), icon: User },
    { href: "/settings", label: t("common.settings"), icon: Settings },
  ];

  const handleKeyDown = useCallback((e: KeyboardEvent) => {
    if (e.key === "Escape" && open) onClose();
  }, [open, onClose]);

  useEffect(() => {
    if (open) {
      wasOpen.current = true;
      document.addEventListener("keydown", handleKeyDown);
      closeButtonRef.current?.focus();
      return () => document.removeEventListener("keydown", handleKeyDown);
    }
    if (wasOpen.current) document.getElementById("mobile-nav-trigger")?.focus();
  }, [open, handleKeyDown]);

  return (
    <>
      {open && (
        <div className="fixed inset-0 z-50 bg-black/50 lg:hidden" onClick={onClose} aria-label={t("a11y.closeOverlay")} role="presentation" />
      )}
      <aside
        className={cn(
          "fixed inset-y-0 left-0 z-50 w-60 bg-sidebar p-4 transition-transform lg:hidden",
          open ? "translate-x-0" : "-translate-x-full",
        )}
         aria-label={t("a11y.mobileNav")}
         aria-hidden={!open}
         aria-modal="true"
         inert={!open ? true : undefined}
          aria-labelledby="mobile-nav-title"
          role="dialog"
      >
        <div className="flex items-center justify-between mb-6">
           <span id="mobile-nav-title" className="text-sm font-bold text-sidebar-foreground">{APP_NAME}</span>
           <Button ref={closeButtonRef} variant="ghost" size="icon" onClick={onClose} className="text-sidebar-foreground hover:bg-sidebar-accent" aria-label={t("a11y.closeNav")}>
            <X className="h-4 w-4" />
          </Button>
        </div>

        <nav className="space-y-1">
        {navItems.filter((item) => (!item.capability || canOperateMerchant(user, item.capability)) && (!item.roles || canUseRoute(user, item.roles))).map((item) => {
            const Icon = item.icon;
            const isActive = pathname === item.href || pathname.startsWith(item.href + "/");
            return (
              <Link
                key={item.href}
                href={item.href}
                onClick={onClose}
                aria-current={isActive ? "page" : undefined}
                className={cn(
                  "flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors active:scale-[0.98]",
                  isActive
                    ? "bg-sidebar-accent text-sidebar-foreground"
                    : "text-sidebar-muted hover:bg-sidebar-accent hover:text-sidebar-foreground",
                )}
              >
                <Icon className="h-4 w-4" />
                {item.label}
              </Link>
            );
          })}
        </nav>

        <div className="absolute bottom-4 left-4 right-4">
           <button
             type="button"
             aria-label={t("common.logout")}
            onClick={() => { logout(); onClose(); }}
            className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-sidebar-muted hover:bg-sidebar-accent hover:text-sidebar-foreground transition-colors active:scale-[0.98]"
          >
            <LogOut className="h-4 w-4" />
            {t("common.logout")}
          </button>
        </div>
      </aside>
    </>
  );
}
