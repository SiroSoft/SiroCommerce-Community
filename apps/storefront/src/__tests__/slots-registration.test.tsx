import "@testing-library/jest-dom/vitest";
import { act, render, screen } from "@testing-library/react";
import { renderToString } from "react-dom/server";
import { beforeEach, describe, expect, it } from "vitest";
import {
  Slot,
  SlotProvider,
  createSlotRegistry,
  defaultSlotRegistry,
  getSlotRegistry,
  registerSlotComponent,
  resetSlotRegistry,
  unregisterSlotComponent,
} from "@/slots";
import type { SlotComponent, SlotName, SlotProps, SlotRegistration } from "@/slots";
import {
  EXAMPLE_SLOT_ID,
  EXAMPLE_SLOT_NAME,
  ExampleSlotNotice,
  exampleSlotRegistration,
  registerExampleSlot,
} from "@/plugins/example-slot";

function marker(label: string): SlotComponent {
  function Marker() {
    return <span data-slot-item data-testid={`slot-${label}`}>{label}</span>;
  }
  return Marker;
}

function registration(
  id: string,
  priority: number,
  label: string,
): SlotRegistration {
  return { id, priority, component: marker(label) };
}

describe("runtime slot registration", () => {
  beforeEach(() => {
    resetSlotRegistry();
  });

  it("does not register the example plugin at module import time", () => {
    expect(getSlotRegistry()).toEqual({});
    expect(exampleSlotRegistration.id).toBe(EXAMPLE_SLOT_ID);
  });

  it("registers a component and renders it through the default SlotProvider", () => {
    registerSlotComponent("pdp", registration("demo", 10, "demo"));

    render(
      <SlotProvider>
        <Slot name="pdp" />
      </SlotProvider>,
    );

    expect(screen.getByTestId("slot-demo")).toBeInTheDocument();
  });

  it("merges runtime registrations with an explicitly supplied registry", () => {
    const base = createSlotRegistry({
      pdp: [registration("base", 10, "base")],
    });
    registerSlotComponent("pdp", registration("runtime", 5, "runtime"));

    const { container } = render(
      <SlotProvider registry={base}>
        <Slot name="pdp" />
      </SlotProvider>,
    );

    expect(Array.from(container.querySelectorAll("[data-slot-item]")).map((item) => item.textContent))
      .toEqual(["runtime", "base"]);
  });

  it("applies runtime registrations when the default registry is passed", () => {
    registerSlotComponent("footer", registration("footer", 10, "footer"));

    render(
      <SlotProvider registry={defaultSlotRegistry}>
        <Slot name="footer" />
      </SlotProvider>,
    );

    expect(screen.getByTestId("slot-footer")).toBeInTheDocument();
  });

  it("updates a mounted SlotProvider after a late registration", () => {
    const { container } = render(
      <SlotProvider>
        <Slot name="cart" />
      </SlotProvider>,
    );

    expect(container).toBeEmptyDOMElement();

    act(() => {
      registerSlotComponent("cart", registration("late", 10, "late"));
    });
    expect(screen.getByTestId("slot-late")).toBeInTheDocument();

    act(() => {
      unregisterSlotComponent("cart", "late");
    });
    expect(container).toBeEmptyDOMElement();
  });

  it("unregisters by id and reports whether an entry was removed", () => {
    registerSlotComponent("checkout", registration("keep", 10, "keep"));
    registerSlotComponent("checkout", registration("remove", 20, "remove"));

    expect(unregisterSlotComponent("checkout", "remove")).toBe(true);
    expect(unregisterSlotComponent("checkout", "remove")).toBe(false);
    expect(getSlotRegistry().checkout?.map((entry) => entry.id)).toEqual(["keep"]);
    expect(() => unregisterSlotComponent("sidebar" as SlotName, "keep")).toThrow(/Unknown slot/);
  });

  it("rejects duplicate ids and keeps the existing registration", () => {
    const original = marker("original");
    registerSlotComponent("pdp", { id: "duplicate", priority: 10, component: original });

    expect(() =>
      registerSlotComponent("pdp", registration("duplicate", 20, "replacement")),
    ).toThrow(/Duplicate slot registration id/);

    const entries = getSlotRegistry().pdp ?? [];
    expect(entries).toHaveLength(1);
    expect(entries[0]?.component).toBe(original);
    expect(entries[0]?.priority).toBe(10);
  });

  it("rejects invalid priorities, components and slot names", () => {
    expect(() =>
      registerSlotComponent("pdp", {
        id: "nan",
        priority: Number.NaN,
        component: marker("nan"),
      }),
    ).toThrow(/finite number priority/);

    expect(() =>
      registerSlotComponent("pdp", {
        id: "infinite",
        priority: Number.POSITIVE_INFINITY,
        component: marker("infinite"),
      }),
    ).toThrow(/finite number priority/);

    expect(() =>
      registerSlotComponent("pdp", {
        id: "string",
        priority: 1,
        component: "not-a-component" as unknown as SlotComponent,
      }),
    ).toThrow(/React component/);

    expect(() =>
      registerSlotComponent("pdp", {
        id: "object",
        priority: 1,
        component: {} as unknown as SlotComponent,
      }),
    ).toThrow(/React component/);

    expect(() =>
      registerSlotComponent("sidebar" as SlotName, registration("bad", 1, "bad")),
    ).toThrow(/Unknown slot/);

    expect(getSlotRegistry()).toEqual({});
  });

  it("renders runtime registrations on the server without browser globals", () => {
    function ServerNotice({ scope, locale }: SlotProps) {
      return <strong data-slot-item>{`plugin ${scope ?? "store"} ${locale ?? "en"}`}</strong>;
    }

    registerSlotComponent("email", {
      id: "server",
      priority: 5,
      component: ServerNotice,
    });

    const html = renderToString(
      <SlotProvider>
        <Slot name="email" scope="demo" locale="vi" as="header" />
      </SlotProvider>,
    );

    expect(html).toContain("plugin demo vi");
    expect(html).toContain('data-slot="email"');
  });

  it("reset clears state so tests stay isolated", () => {
    registerSlotComponent("my-account", registration("account", 10, "account"));
    expect(getSlotRegistry()["my-account"]).toHaveLength(1);

    resetSlotRegistry();

    expect(getSlotRegistry()).toEqual({});
    render(
      <SlotProvider>
        <Slot name="my-account" fallback={<p>empty</p>} />
      </SlotProvider>,
    );
    expect(screen.getByText("empty")).toBeInTheDocument();
  });

  it("registers and renders the example plugin explicitly", () => {
    registerExampleSlot();

    render(
      <SlotProvider>
        <Slot
          name={EXAMPLE_SLOT_NAME}
          scope="demo"
          locale="vi"
          data={{ message: "Hello from a plugin", tone: "promo" }}
        />
      </SlotProvider>,
    );

    const notice = screen.getByTestId("example-slot-notice");
    expect(notice).toHaveTextContent("Example plugin");
    expect(notice).toHaveTextContent("Hello from a plugin");
    expect(notice).toHaveAttribute("data-tone", "promo");
    expect(notice).toHaveAttribute("data-scope", "demo");
    expect(ExampleSlotNotice).toBe(exampleSlotRegistration.component);
  });
});
