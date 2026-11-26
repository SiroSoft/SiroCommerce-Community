import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { LocaleProvider, useCopy } from "../lib/i18n-client";
import { readScopeCookie } from "../lib/scope-cookie";
import { RegionSelector } from "../components/RegionSelector";

vi.mock("next/link", () => ({ default: ({ children, ...props }: React.PropsWithChildren<{ href: string }>) => <a {...props} href={props.href}>{children}</a> }));
vi.mock("next/navigation", () => ({ usePathname: () => "/", useRouter: () => ({ push: () => undefined }) }));

function Probe() {
  const copy = useCopy();
  return <p data-testid="probe">{copy.home.title}</p>;
}

afterEach(() => {
  document.body.innerHTML = "";
  document.cookie.split(";").forEach((part) => {
    const name = part.split("=")[0]?.trim();
    if (name) document.cookie = `${name}=; Max-Age=0; Path=/`;
  });
});

describe("region and locale plumbing", () => {
  it("renders the configured default copy without a provider", () => {
    render(<Probe />);
    expect(screen.getByTestId("probe")).toHaveTextContent("Useful things, beautifully considered.");
  });

  it("serves the provider locale to client components", () => {
    render(<LocaleProvider locale="vi"><Probe /></LocaleProvider>);
    expect(screen.getByTestId("probe")).toHaveTextContent("Những món đồ hữu ích, được cân nhắc kỹ lưỡng.");
  });

  it("offers five languages and two priced currencies, persisting to cookie", () => {
    const reload = vi.fn();
    Object.defineProperty(window, "location", { value: { reload }, writable: true });
    render(<RegionSelector locale="en" currency="USD" />);
    expect(screen.getByRole("combobox", { name: "Language" })).toHaveDisplayValue("English");
    expect(screen.getByRole("combobox", { name: "Currency" })).toHaveDisplayValue("USD $");
    expect(screen.getByRole("option", { name: "Tiếng Việt" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "VND ₫" })).toBeInTheDocument();
    fireEvent.change(screen.getByRole("combobox", { name: "Currency" }), { target: { value: "VND" } });
    expect(readScopeCookie().currency).toBe("VND");
    expect(reload).toHaveBeenCalled();
  });
});
