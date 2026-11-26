import { describe, expect, it, vi } from "vitest";
import { render, renderHook, screen } from "@testing-library/react";
import { renderToString } from "react-dom/server";
import type { ReactNode } from "react";
import {
  Slot,
  SlotProvider,
  createSlotRegistry,
  isSlotName,
  registerSlot,
  useSlots,
  validateRegistry,
} from "@/slots";
import type { SlotComponent, SlotProps, SlotRegistry } from "@/slots";

function textItem(label: string): SlotComponent {
  function TextItem() {
    return <span data-slot-item>{label}</span>;
  }
  return TextItem;
}

const Failing: SlotComponent = () => {
  throw new Error("slot failure");
};

function renderTextItems(container: HTMLElement): (string | null)[] {
  return Array.from(container.querySelectorAll("[data-slot-item]")).map((item) => item.textContent);
}

describe("storefront slots", () => {
  it("renders registered components in ascending priority order", () => {
    const registry = createSlotRegistry({
      pdp: [
        { id: "high", priority: 30, component: textItem("high") },
        { id: "low", priority: 10, component: textItem("low") },
        { id: "mid", priority: 20, component: textItem("mid") },
      ],
    });

    const { container } = render(
      <SlotProvider registry={registry}>
        <Slot name="pdp" />
      </SlotProvider>,
    );

    expect(renderTextItems(container)).toEqual(["low", "mid", "high"]);
  });

  it("uses order and id as deterministic tie-breakers", () => {
    const registry = createSlotRegistry({
      cart: [
        { id: "b", priority: 10, order: 2, component: textItem("b") },
        { id: "c", priority: 10, order: 1, component: textItem("c") },
        { id: "a", priority: 10, order: 1, component: textItem("a") },
      ],
    });

    const { container } = render(
      <SlotProvider registry={registry}>
        <Slot name="cart" />
      </SlotProvider>,
    );

    expect(renderTextItems(container)).toEqual(["a", "c", "b"]);
  });

  it("renders the fallback when a slot has no registrations", () => {
    render(
      <SlotProvider>
        <Slot name="checkout" fallback={<p>No checkout extensions</p>} />
      </SlotProvider>,
    );

    expect(screen.getByText("No checkout extensions")).toBeInTheDocument();
  });

  it("renders nothing when empty without a fallback", () => {
    const { container } = render(
      <SlotProvider>
        <Slot name="footer" />
      </SlotProvider>,
    );

    expect(container).toBeEmptyDOMElement();
  });

  it("keeps other components when one registration throws", () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    const registry = createSlotRegistry({
      "my-account": [
        { id: "alpha", priority: 10, component: textItem("alpha") },
        { id: "broken", priority: 20, component: Failing },
        { id: "omega", priority: 30, component: textItem("omega") },
      ],
    });

    const { container } = render(
      <SlotProvider registry={registry}>
        <Slot
          name="my-account"
          errorFallback={({ registration }) => (
            <span data-testid={`failed-${registration.id}`}>failed</span>
          )}
        />
      </SlotProvider>,
    );

    expect(screen.getByText("alpha")).toBeInTheDocument();
    expect(screen.getByText("omega")).toBeInTheDocument();
    expect(screen.getByTestId("failed-broken")).toBeInTheDocument();
    expect(renderTextItems(container)).toEqual(["alpha", "omega"]);
  });

  it("supports an optional wrapper element and className", () => {
    const registry = createSlotRegistry({
      header: [{ id: "banner", priority: 1, component: textItem("banner") }],
    });

    const { container } = render(
      <SlotProvider registry={registry}>
        <Slot name="header" as="section" className="slot-header" />
      </SlotProvider>,
    );

    const wrapper = container.querySelector("section.slot-header");
    expect(wrapper).not.toBeNull();
    expect(wrapper).toHaveAttribute("data-slot", "header");
    expect(wrapper).toHaveTextContent("banner");
  });

  it("validates slot names, duplicate ids and priorities", () => {
    expect(isSlotName("pdp")).toBe(true);
    expect(isSlotName("sidebar")).toBe(false);

    expect(() =>
      validateRegistry({
        pdp: [
          { id: "duplicate", priority: 1, component: textItem("one") },
          { id: "duplicate", priority: 2, component: textItem("two") },
        ],
      }),
    ).toThrow(/Duplicate slot registration id/);

    expect(() =>
      validateRegistry({
        pdp: [{ id: "nan", priority: Number.NaN, component: textItem("nan") }],
      }),
    ).toThrow(/finite number priority/);

    expect(() =>
      validateRegistry({
        pdp: [{ id: "infinite", priority: Number.POSITIVE_INFINITY, component: textItem("inf") }],
      }),
    ).toThrow(/finite number priority/);

    expect(() =>
      validateRegistry({ sidebar: [] } as unknown as SlotRegistry),
    ).toThrow(/Unknown slot/);
  });

  it("registers slots immutably and exposes the registry through useSlots", () => {
    const base = createSlotRegistry({ pdp: [{ id: "base", priority: 1, component: textItem("base") }] });
    const extended = registerSlot(base, "pdp", {
      id: "extra",
      priority: 2,
      component: textItem("extra"),
    });

    expect(extended.pdp?.map((entry) => entry.id)).toEqual(["base", "extra"]);
    expect(base.pdp?.map((entry) => entry.id)).toEqual(["base"]);

    const wrapper = ({ children }: { children: ReactNode }) => (
      <SlotProvider registry={extended}>{children}</SlotProvider>
    );
    const { result } = renderHook(() => useSlots(), { wrapper });

    expect(result.current.pdp?.map((entry) => entry.id)).toEqual(["base", "extra"]);
  });

  it("renders on the server without browser globals", () => {
    function ServerGreeting({ locale, scope }: SlotProps) {
      return <strong data-slot-item>{`${scope ?? "store"} ${locale ?? "en"}`}</strong>;
    }

    const html = renderToString(
      <SlotProvider
        registry={createSlotRegistry({
          email: [{ id: "greeting", priority: 5, component: ServerGreeting }],
        })}
      >
        <Slot name="email" scope="demo" locale="vi" as="header" />
      </SlotProvider>,
    );

    expect(html).toContain("demo vi");
    expect(html).toContain('data-slot="email"');
  });
});
