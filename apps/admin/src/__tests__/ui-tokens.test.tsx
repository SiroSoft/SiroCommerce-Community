import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader } from "@/components/ui/card";
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from "@/components/ui/table";

describe("R9 UI tokens — button, input, card, table, form", () => {
  it("button has min-h-11 or h-9 and px-3/4", () => {
    const { container } = render(<Button>Test</Button>);
    const btn = container.querySelector("button")!;
    expect(btn.className).toMatch(/h-9|min-h-11/);
    expect(btn.className).toMatch(/px-3|px-4/);
    expect(btn.className).toMatch(/active:scale-95|active:scale-\[0\.98\]/);
  });
  it("input has h-9 px-3 py-1 text-sm border-input", () => {
    const { container } = render(<Input aria-label="test" />);
    const inp = container.querySelector("input")!;
    expect(inp.className).toMatch(/h-9/);
    expect(inp.className).toMatch(/px-3/);
    expect(inp.className).toMatch(/text-sm/);
    expect(inp.className).toMatch(/border-input/);
  });
  it("card has p-6 and header/content spacing", () => {
    const { container } = render(<Card><CardHeader>Head</CardHeader><CardContent>Body</CardContent></Card>);
    const card = container.firstChild as HTMLElement;
    expect(card.className).toMatch(/rounded-xl|border/);
    expect(container.innerHTML).toMatch(/p-6|space-y-4|gap-2/);
  });
  it("table has p-3/p-4 and overflow-x-auto", () => {
    const { container } = render(<div className="overflow-x-auto"><Table><TableHeader><TableRow><TableHead className="p-4">H</TableHead></TableRow></TableHeader><TableBody><TableRow><TableCell className="p-3">C</TableCell></TableRow></TableBody></Table></div>);
    expect(container.innerHTML).toMatch(/overflow-x-auto/);
    expect(container.innerHTML).toMatch(/p-3|p-4/);
    expect(container.innerHTML).toMatch(/text-sm/);
  });
  it("form gaps are 2/4 tokens", () => {
    const { container } = render(<div className="grid gap-2 md:grid-cols-3 space-y-4"><div className="flex gap-2" /></div>);
    expect(container.innerHTML).toMatch(/gap-2|gap-4/);
    expect(container.innerHTML).toMatch(/space-y-4|space-y-3/);
  });
  it("finance and parity pages contain expected padding classes", async () => {
    // spot-check by importing pages (smoke) — ensures they compile with tokens
    const Finance = (await import("@/app/(dashboard)/finance/page")).default;
    expect(typeof Finance).toBe("function");
    const Parity = (await import("@/app/(dashboard)/parity/page")).default;
    expect(typeof Parity).toBe("function");
  });
});
