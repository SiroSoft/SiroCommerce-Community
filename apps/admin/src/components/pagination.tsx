"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useI18n } from "@/providers/i18n-provider";
import type { components } from "@/types/api";

type PaginationMeta = components["schemas"]["PaginationMeta"];

interface PaginationProps {
  meta?: PaginationMeta;
  onPageChange: (page: number) => void;
}

export function Pagination({ meta, onPageChange }: PaginationProps) {
  const { t } = useI18n();
  const lastPage = Number(meta?.last_page);
  const currentPage = Number(meta?.page);
  const total = Number(meta?.total);
  if (!Number.isInteger(lastPage) || lastPage < 2 || !Number.isInteger(currentPage) || currentPage < 1) return null;

  const safeCurrentPage = Math.min(currentPage, lastPage);
  const pages: number[] = [];

  for (let i = Math.max(1, safeCurrentPage - 2); i <= Math.min(lastPage, safeCurrentPage + 2); i++) {
    pages.push(i);
  }

  return (
    <div className="flex items-center justify-between pt-4">
      <p className="text-sm text-muted-foreground">
        Showing page {safeCurrentPage} of {lastPage} ({Number.isFinite(total) && total >= 0 ? total : 0} total)
      </p>
      <div className="flex items-center gap-1">
        <Button
          variant="outline"
          size="icon"
          className="h-8 w-8"
          disabled={safeCurrentPage <= 1}
          onClick={() => onPageChange(safeCurrentPage - 1)}
          aria-label={t("a11y.prevPage")}
        >
          <ChevronLeft className="h-4 w-4" />
        </Button>
        {pages.map((p) => (
          <Button
            key={p}
            variant={p === safeCurrentPage ? "default" : "outline"}
            size="icon"
            className="h-8 w-8"
            onClick={() => onPageChange(p)}
            aria-label={t("a11y.pageNumber", { page: p })}
            aria-current={p === safeCurrentPage ? "page" : undefined}
          >
            {p}
          </Button>
        ))}
        <Button
          variant="outline"
          size="icon"
          className="h-8 w-8"
          disabled={safeCurrentPage >= lastPage}
          onClick={() => onPageChange(safeCurrentPage + 1)}
          aria-label={t("a11y.nextPage")}
        >
          <ChevronRight className="h-4 w-4" />
        </Button>
      </div>
    </div>
  );
}
