import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { Pagination } from "@/components/pagination";
import { I18nProvider } from "@/providers/i18n-provider";

describe("pagination boundary", () => {
  it("clamps a page greater than last_page to the final page", () => {
    const onPageChange = vi.fn();
    render(
      <I18nProvider>
        <Pagination meta={{ page: 9, per_page: 10, total: 40, last_page: 4 } as never} onPageChange={onPageChange} />
      </I18nProvider>,
    );

    expect(screen.getByText("Showing page 4 of 4 (40 total)")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Page 4" })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("button", { name: "Next page" })).toBeDisabled();

    fireEvent.click(screen.getByRole("button", { name: "Previous page" }));
    expect(onPageChange).toHaveBeenCalledWith(3);
    expect(screen.queryByRole("button", { name: "Page 9" })).toBeNull();
  });
});
