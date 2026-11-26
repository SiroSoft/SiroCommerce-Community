"use client";

import { CircleHelp, Menu, Moon, Sun, ChevronRight } from "lucide-react";
import { WELCOME_TOUR_ID, WELCOME_TOUR_STEPS, useTour } from "@/components/guided-tour";
import { useTheme } from "next-themes";
import { usePathname } from "next/navigation";
import { Button } from "@/components/ui/button";
import { UserNav } from "@/layouts/user-nav";
import { LocaleSwitcher } from "@/components/locale-switcher";
import { useI18n } from "@/providers/i18n-provider";
import { cn } from "@/lib/utils";
import { AdminGlobalSearch } from "@/components/admin-global-search";

interface HeaderProps {
  onMenuToggle: () => void;
}

export function Header({ onMenuToggle }: HeaderProps) {
  const { theme, setTheme } = useTheme();
  const pathname = usePathname();
  const { t } = useI18n();
  const tour = useTour();

  return (
    <header className="sticky top-0 z-30 flex h-14 items-center gap-4 border-b bg-background px-4 lg:px-6">
      <Button id="mobile-nav-trigger" variant="ghost" size="icon" onClick={onMenuToggle} className="lg:hidden" aria-label={t("a11y.openNav")}>
        <Menu className="h-5 w-5" />
      </Button>

      <nav aria-label={t("a11y.breadcrumb")} className="hidden md:flex items-center gap-1 text-sm text-muted-foreground">
        <ol className="flex items-center gap-1">
          <li><a href="/" className={cn(pathname === "/" && "text-foreground font-medium")} aria-current={pathname === "/" ? "page" : undefined}>{t("common.dashboard")}</a></li>
          {pathname !== "/" && pathname.split("/").filter(Boolean).map((segment, i, arr) => (
            <li key={`${segment}-${i}`} className="flex items-center gap-1">
              <ChevronRight className="h-3 w-3" aria-hidden="true" />
              <span className={cn(i === arr.length - 1 && "text-foreground font-medium")} aria-current={i === arr.length - 1 ? "page" : undefined}>
                {segment.charAt(0).toUpperCase() + segment.slice(1).replace(/-/g, " ")}
              </span>
            </li>
          ))}
        </ol>
      </nav>

      <AdminGlobalSearch />

      <div className="flex-1" />

      <LocaleSwitcher />

      <Button
        variant="ghost"
        size="icon"
        onClick={() => tour.start(WELCOME_TOUR_ID, WELCOME_TOUR_STEPS)}
        aria-label="Take the guided tour"
      >
        <CircleHelp className="h-5 w-5" />
      </Button>

      <Button
        variant="ghost"
        size="icon"
        onClick={() => setTheme(theme === "dark" ? "light" : "dark")}
        aria-label={theme === "dark" ? t("a11y.switchToLight") : t("a11y.switchToDark")}
      >
        <Sun className={cn("h-5 w-5", theme === "dark" && "hidden")} />
        <Moon className={cn("h-5 w-5", theme !== "dark" && "hidden")} />
      </Button>

      <UserNav />
    </header>
  );
}
