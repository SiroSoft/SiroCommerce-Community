import "@testing-library/jest-dom/vitest";
import fs from "node:fs";
import path from "node:path";
import { useEffect, useMemo, useRef, useState } from "react";
import {
  createColumnHelper,
  getCoreRowModel,
  useReactTable,
} from "@tanstack/react-table";
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import { Toaster } from "@/components/ui/toast";
import { toast } from "@/hooks/use-toast";
import { SearchInput } from "@/components/search-input";
import { DataTable } from "@/components/data-table";
import { ImageUpload } from "@/components/ui/image-upload";
import { ErrorState } from "@/components/error-state";

vi.mock("@/hooks/use-products", () => ({
  useProducts: () => ({ products: [{ id: 1, name: "P", sku: "S" }] }),
}));
vi.mock("@/hooks/use-users", () => ({
  useUsers: () => ({ users: [{ id: 1, name: "U", email: "e@example.test" }] }),
}));

import { OrderForm } from "@/modules/orders/components/order-form";

function readSrc(rel: string): string {
  return fs.readFileSync(
    path.resolve(__dirname, "..", ...rel.split("/")),
    "utf8",
  );
}

afterEach(() => {
  cleanup();
});

describe("admin state consistency / a11y", () => {
  it("toast root uses alert for destructive and status otherwise", () => {
    render(
      <I18nProvider>
        <Toaster />
      </I18nProvider>,
    );
    act(() => {
      toast({ title: "Boom", variant: "destructive" });
    });
    expect(screen.getByRole("alert")).toHaveTextContent("Boom");
    act(() => {
      toast({ title: "All good", variant: "success" });
    });
    const statuses = screen.getAllByRole("status");
    expect(statuses.some((el) => el.textContent?.includes("All good"))).toBe(
      true,
    );
    expect(readSrc("components/ui/toast.tsx")).toContain(
      'role={t.variant === "destructive" ? "alert" : "status"}',
    );
  });

  it("search input exposes its placeholder as aria-label", () => {
    render(
      <I18nProvider>
        <SearchInput value="" onChange={() => {}} placeholder="Search orders" />
      </I18nProvider>,
    );
    const input = screen.getByLabelText("Search orders");
    expect(input).toHaveAttribute("placeholder", "Search orders");
    expect(readSrc("components/search-input.tsx")).toContain(
      "aria-label={resolvedPlaceholder}",
    );
  });

  it("data-table announces loading alongside aria-busy", () => {
    function Harness() {
      const helper = useMemo(() => createColumnHelper<{ name: string }>(), []);
      const columns = useMemo(
        () => [helper.accessor("name", { header: "Name" })],
        [helper],
      );
      const table = useReactTable({
        data: [{ name: "A" }],
        columns,
        getCoreRowModel: getCoreRowModel(),
      });
      return <DataTable table={table} isLoading />;
    }
    const { container } = render(
      <I18nProvider>
        <Harness />
      </I18nProvider>,
    );
    expect(screen.getByRole("status")).toHaveTextContent(/loading/i);
    expect(container.firstChild).toHaveAttribute("aria-busy", "true");
  });

  it("image-upload labels the icon-only remove button and the URL input", () => {
    render(
      <I18nProvider>
        <ImageUpload value="https://example.com/a.png" onChange={() => {}} />
      </I18nProvider>,
    );
    expect(screen.getByLabelText(/remove image/i)).toBeInTheDocument();
    cleanup();
    render(
      <I18nProvider>
        <ImageUpload value="" onChange={() => {}} />
      </I18nProvider>,
    );
    expect(screen.getByLabelText(/paste image url/i)).toBeInTheDocument();
    const src = readSrc("components/ui/image-upload.tsx");
    expect(src).toContain('t("a11y.removeImage")');
  });

  it("order-form labels the remove-line button with its index", () => {
    render(
      <I18nProvider>
        <OrderForm onSubmit={() => {}} isPending={false} />
      </I18nProvider>,
    );
    expect(screen.getByLabelText("Remove item 1")).toBeInTheDocument();
    expect(readSrc("modules/orders/components/order-form.tsx")).toContain(
      "Remove item ${index + 1}",
    );
  });

  it("skeleton surfaces announce loading (settings, orders, order detail)", () => {
    const settings = readSrc("app/(dashboard)/settings/page.tsx");
    expect(settings).toContain('role="status"');
    expect(settings).toContain("Loading settings");
    const orderUi = readSrc("modules/merchant/order-ui.tsx");
    expect(orderUi).toContain("Loading orders");
    expect(orderUi).toContain("Loading order");
    // Updating spinner is a status region, not a bare aria-label on the icon.
    expect(orderUi).toContain('<span role="status"');
    expect(orderUi).toContain("Updating");
    expect(orderUi).not.toContain('aria-label="Updating"');
  });

  it("fulfillment feedback uses alert for errors and status otherwise", () => {
    const src = readSrc("app/(dashboard)/fulfillment/page.tsx");
    expect(src).toContain(
      'role={feedback.kind === "err" ? "alert" : "status"}',
    );
    function Banner({ kind }: { kind: "ok" | "err" }) {
      return (
        <p role={kind === "err" ? "alert" : "status"}>
          {kind === "err" ? "bad" : "good"}
        </p>
      );
    }
    const { unmount } = render(<Banner kind="err" />);
    expect(screen.getByRole("alert")).toHaveTextContent("bad");
    unmount();
    render(<Banner kind="ok" />);
    expect(screen.getByRole("status")).toHaveTextContent("good");
  });

  it("finance error uses shared ErrorState with retry and keeps copy", () => {
    const src = readSrc("app/(dashboard)/finance/page.tsx");
    expect(src).toContain("<ErrorState");
    expect(src).toContain("Financial reconciliation is unavailable.");
    expect(src).toContain("onRetry");
    const onRetry = vi.fn();
    render(
      <I18nProvider>
        <ErrorState message="Financial reconciliation is unavailable." onRetry={onRetry} />
      </I18nProvider>,
    );
    expect(screen.getByRole("alert")).toHaveTextContent(
      /financial reconciliation is unavailable/i,
    );
    fireEvent.click(screen.getByRole("button", { name: /try again/i }));
    expect(onRetry).toHaveBeenCalledTimes(1);
  });

  it("inventory transfer success announces like the adjust pattern", () => {
    const src = readSrc("modules/merchant/inventory-ui.tsx");
    expect(src).toContain("Adjustment accepted. Refreshing ATP");
    expect(src).toContain("Transfer accepted. Refreshing ATP");
    expect(src).toContain('{transfer.isSuccess && <p role="status"');
  });

  it("focus regions recover focus only after an error, never on reloads", () => {
    for (const rel of [
      "modules/products/components/product-table.tsx",
      "modules/users/components/user-table.tsx",
      "modules/categories/components/category-table.tsx",
      "modules/merchant/order-ui.tsx",
    ]) {
      const src = readSrc(rel);
      expect(src).toContain('role="region"');
      expect(src).toContain("tabIndex={-1}");
      expect(src).toContain(".focus()");
    }

    // Behavioral double of the shipped pattern: focus only on error -> success.
    function Recovery({
      isError,
      isLoading,
    }: {
      isError: boolean;
      isLoading: boolean;
    }) {
      const regionRef = useRef<HTMLDivElement>(null);
      const hadError = useRef(false);
      const [items] = useState(["row"]);
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
      if (isError) return <div role="alert">broken</div>;
      return (
        <div ref={regionRef} role="region" tabIndex={-1} aria-label="Rows">
          {items.join(",")}
        </div>
      );
    }
    const { rerender } = render(<Recovery isError={false} isLoading={true} />);
    // Plain reload (search/pagination) must not steal focus.
    rerender(<Recovery isError={false} isLoading={false} />);
    expect(screen.getByRole("region")).not.toHaveFocus();
    // Error -> success recovery must move focus for SR/keyboard users.
    rerender(<Recovery isError={true} isLoading={false} />);
    rerender(<Recovery isError={false} isLoading={false} />);
    expect(screen.getByRole("region")).toHaveFocus();
  });
});
