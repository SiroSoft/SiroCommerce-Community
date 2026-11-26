"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Search } from "lucide-react";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { adminConsoleService, type AdminSearchResult, type SearchType } from "@/services/admin-console.service";
import { extensionsService } from "@/services/extensions.service";
import { useAuth } from "@/hooks/use-auth";
import { canOperateMerchant, canUseRoute } from "@/modules/merchant/permissions";

const recordLinks: Record<SearchType, string> = { catalog: "/products", orders: "/orders", customers: "/users" };

interface NavEntry {
  href: string;
  label: string;
  keywords: string;
  capability?: Parameters<typeof canOperateMerchant>[1];
  roles?: readonly string[];
}

const NAV_ENTRIES: NavEntry[] = [
  { href: "/", label: "Dashboard", keywords: "home overview stats" },
  { href: "/action-center", label: "Action Center", keywords: "rescue smart execute", roles: ["owner", "admin"] },
  { href: "/operations", label: "Operations", keywords: "jobs reconcile scheduled", capability: "operations" },
  { href: "/finance", label: "Finance", keywords: "payments invoices reconcile money", capability: "finance" },
  { href: "/subscriptions", label: "Subscriptions", keywords: "recurring plans renew", capability: "subscriptions" },
  { href: "/campaigns", label: "Campaign automation", keywords: "marketing messages", capability: "campaigns" },
  { href: "/users", label: "Users", keywords: "customers staff accounts", capability: "users" },
  { href: "/orders", label: "Orders", keywords: "sales purchase fulfill refund", capability: "orders" },
  { href: "/fulfillment", label: "Fulfillment", keywords: "ship track pack allocate", capability: "inventory" },
  { href: "/inventory", label: "Inventory", keywords: "stock warehouse levels", capability: "inventory" },
  { href: "/products", label: "Products", keywords: "catalog items sku", capability: "products" },
  { href: "/commerce", label: "Commerce management", keywords: "coupons promotions lifecycle theme", capability: "products" },
  { href: "/flash-sales", label: "Flash sales", keywords: "deals promo", capability: "products" },
  { href: "/pickup-points", label: "Pickup points", keywords: "click collect store pickup handover counter", capability: "inventory" },
  { href: "/gift-cards", label: "Gift cards & credit", keywords: "gift voucher wallet store credit issue void", capability: "products" },
  { href: "/media", label: "Media library", keywords: "images files upload", capability: "cms" },
  { href: "/b2b", label: "B2B operations", keywords: "companies quotes wholesale", capability: "b2b" },
  { href: "/categories", label: "Categories", keywords: "taxonomy", capability: "products" },
  { href: "/posts", label: "Posts", keywords: "cms content blog", capability: "cms" },
  { href: "/storefront/theme", label: "Theme builder", keywords: "theme tokens publish design", capability: "cms" },
  { href: "/extensions", label: "Extensions", keywords: "modules plugins affiliate install", roles: ["owner", "admin"] },
  { href: "/reporting", label: "Reporting", keywords: "analytics funnel export", capability: "reports" },
  { href: "/admin-workbench", label: "Admin workbench", keywords: "bulk workflows approvals", capability: "admin_workbench" },
  { href: "/configuration", label: "Configuration", keywords: "settings scope", capability: "settings" },
  { href: "/search-seo", label: "Search SEO", keywords: "sitemap synonyms redirects", capability: "seo" },
  { href: "/parity", label: "Parity diagnostics", keywords: "health checks" },
  { href: "/settings", label: "Settings", keywords: "preferences", roles: ["owner", "admin"] },
];

interface QuickAction {
  label: string;
  keywords: string;
  href: string;
  capability: Parameters<typeof canOperateMerchant>[1];
}

const QUICK_ACTIONS: QuickAction[] = [
  { label: "New order", keywords: "create order draft cs", href: "/orders", capability: "orders" },
  { label: "New product", keywords: "create catalog item", href: "/products", capability: "products" },
  { label: "New coupon", keywords: "create promotion discount", href: "/commerce", capability: "coupons" },
  { label: "Publish theme", keywords: "theme go live publish", href: "/storefront/theme", capability: "cms" },
  { label: "Reconcile payments", keywords: "finance capture fix", href: "/finance", capability: "finance" },
  { label: "Run scheduled ops", keywords: "jobs retry ops", href: "/operations", capability: "operations" },
];

function fuzzyMatch(haystack: string, needle: string): boolean {
  const text = haystack.toLowerCase();
  const query = needle.toLowerCase().trim();
  if (!query) return true;
  if (text.includes(query)) return true;
  let position = 0;
  for (const char of query) {
    position = text.indexOf(char, position);
    if (position === -1) return false;
    position += 1;
  }
  return true;
}

type FlatItem =
  | { kind: "action"; id: string; label: string; hint: string; href: string }
  | { kind: "page"; id: string; label: string; hint: string; href: string }
  | { kind: "record"; id: string; label: string; hint: string; href: string };

export function AdminGlobalSearch() {
  const [open, setOpen] = useState(false);
  const [term, setTerm] = useState("");
  const [active, setActive] = useState(0);
  const [records, setRecords] = useState<Array<{ type: SearchType; rows: AdminSearchResult[] }>>([]);
  const [loading, setLoading] = useState(false);
  const { user } = useAuth();
  const router = useRouter();
  const inputRef = useRef<HTMLInputElement>(null);
  const listId = "admin-command-listbox";

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        setOpen((value) => !value);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  useEffect(() => {
    if (open) {
      setActive(0);
      window.setTimeout(() => inputRef.current?.focus(), 30);
    } else {
      setTerm("");
      setRecords([]);
    }
  }, [open ]);

  useEffect(() => {
    if (!open || term.trim().length < 2) {
      setRecords([]);
      setLoading(false);
      return;
    }
    setLoading(true);
    let cancelled = false;
    const timer = window.setTimeout(() => {
      const query = term.trim();
      void Promise.all((["catalog", "orders", "customers"] as SearchType[]).map((type) => adminConsoleService.search(query, type, 1, 4).then(
        (response) => ({ type, rows: response.data }),
        () => ({ type, rows: [] as AdminSearchResult[] }),
      ))).then((results) => {
        if (!cancelled) {
          setRecords(results);
          setLoading(false);
        }
      });
    }, 200);
    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [term, open]);

  const [extensionNav, setExtensionNav] = useState<NavEntry[]>([]);
  useEffect(() => {
    if (!open || !canUseRoute(user, ["owner", "admin"])) return;
    void extensionsService.views()
      .then((views) => setExtensionNav(views.map((view) => ({ href: `/extensions/${encodeURIComponent(view.id)}`, label: view.label, keywords: `extension ${view.id} ${view.section} panels`, roles: ["owner", "admin"] as const }))))
      .catch(() => setExtensionNav([]));
  }, [open, user]);
  const visibleNav = useMemo(
    () =>
      [...NAV_ENTRIES, ...extensionNav].filter(
        (entry) =>
          (!entry.capability || canOperateMerchant(user, entry.capability)) &&
          (!entry.roles || canUseRoute(user, entry.roles)) &&
          fuzzyMatch(`${entry.label} ${entry.keywords} ${entry.href}`, term),
      ),
    [user, term, extensionNav],
  );
  const visibleActions = useMemo(
    () => QUICK_ACTIONS.filter((action) => canOperateMerchant(user, action.capability) && fuzzyMatch(`${action.label} ${action.keywords}`, term)),
    [user, term],
  );

  const flat: FlatItem[] = useMemo(() => {
    const items: FlatItem[] = [
      ...visibleActions.map((action, index): FlatItem => ({ kind: "action", id: `action-${index}`, label: action.label, hint: "Action", href: action.href })),
      ...visibleNav.map((entry, index): FlatItem => ({ kind: "page", id: `page-${index}`, label: entry.label, hint: "Go to", href: entry.href })),
    ];
    for (const group of records) {
      for (const row of group.rows.slice(0, 4)) {
        items.push({
          kind: "record",
          id: `record-${group.type}-${String(row.id)}`,
          label: String(row.name || row.number || row.email_masked || row.slug || row.id),
          hint: group.type === "catalog" ? "Product" : group.type === "orders" ? `Order${row.state ? ` · ${row.state}` : ""}` : "Customer",
          href: `${recordLinks[group.type]}/${row.id}`,
        });
      }
    }
    return items.slice(0, 30);
  }, [visibleActions, visibleNav, records]);

  useEffect(() => setActive(0), [flat.length]);

  const go = (item: FlatItem) => {
    setOpen(false);
    router.push(item.href);
  };

  const onInputKey = (event: React.KeyboardEvent) => {
    if (event.key === "ArrowDown") {
      event.preventDefault();
      setActive((current) => (flat.length ? (current + 1) % flat.length : 0));
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      setActive((current) => (flat.length ? (current - 1 + flat.length) % flat.length : 0));
    } else if (event.key === "Enter") {
      const item = flat[active];
      if (item) {
        event.preventDefault();
        go(item);
      }
    } else if (event.key === "Escape") {
      setOpen(false);
    }
  };

  return (
    <div className="relative hidden w-full max-w-sm md:block" data-tour="global-search">
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="flex w-full items-center gap-2 rounded-md border bg-background px-3 py-2 text-sm text-muted-foreground hover:bg-muted"
        aria-label="Open command palette"
      >
        <Search className="h-4 w-4" aria-hidden="true" />
        <span className="flex-1 text-left">Search orders, products, or customers</span>
        <kbd className="rounded border px-1.5 py-0.5 text-[10px]">Ctrl+K</kbd>
      </button>
      {open && (
        <div className="absolute top-11 z-50 w-full rounded-md border bg-popover p-2 shadow-lg" role="dialog" aria-label="Command palette">
          <Input
            ref={inputRef}
            aria-label="Command palette search"
            aria-controls={listId}
            aria-activedescendant={flat[active] ? `palette-option-${flat[active].id}` : undefined}
            role="combobox"
            aria-expanded="true"
            aria-autocomplete="list"
            placeholder="Type a command, page, order #, product, or customer…"
            value={term}
            onChange={(event) => setTerm(event.target.value)}
            onKeyDown={onInputKey}
            autoComplete="off"
          />
          <div role="listbox" id={listId} aria-label="Results" className="mt-2 max-h-80 overflow-auto">
            {loading && <p className="p-2 text-sm text-muted-foreground" role="status">Searching…</p>}
            {!loading && flat.length === 0 && <p className="p-2 text-sm text-muted-foreground">No matches. Try an order number, SKU, or page name.</p>}
            {flat.map((item, index) => (
              <Link
                key={item.id}
                id={`palette-option-${item.id}`}
                href={item.href}
                role="option"
                aria-selected={index === active}
                onClick={() => setOpen(false)}
                onMouseEnter={() => setActive(index)}
                className={cn("flex items-center justify-between gap-2 rounded p-2 text-sm", index === active ? "bg-primary text-primary-foreground" : "hover:bg-muted")}
              >
                <span className="font-medium">{item.label}</span>
                <span className={cn("text-xs", index === active ? "text-primary-foreground/80" : "text-muted-foreground")}>{item.hint}</span>
              </Link>
            ))}
          </div>
          <p className="mt-1 px-2 text-[11px] text-muted-foreground">↑↓ navigate · Enter open · Esc close</p>
        </div>
      )}
    </div>
  );
}
