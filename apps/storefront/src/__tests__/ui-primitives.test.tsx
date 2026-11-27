import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { Badge, LiveRegion, Modal, Popover, StickyBar } from "@/components/ui-primitives";

describe("ui primitives", () => {
  afterEach(() => {
    cleanup();
    document.body.style.overflow = "";
    document.documentElement.classList.remove("ui-has-sticky-bar");
  });

  describe("Modal", () => {
    it("renders nothing when closed and a labelled dialog when open", () => {
      const { rerender } = render(
        <Modal open={false} onClose={() => {}} title="Get 10% off">
          <p>Form goes here</p>
        </Modal>,
      );
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
      rerender(
        <Modal open onClose={() => {}} title="Get 10% off" description="Leave your email.">
          <p>Form goes here</p>
        </Modal>,
      );
      const dialog = screen.getByRole("dialog");
      expect(dialog).toHaveAttribute("aria-modal", "true");
      const title = screen.getByText("Get 10% off");
      expect(dialog).toHaveAttribute("aria-labelledby", title.id);
      expect(dialog).toHaveAttribute("aria-describedby");
      expect(screen.getByText("Form goes here")).toBeInTheDocument();
    });

    it("focuses the first field on open, traps Tab, and restores focus on close", async () => {
      const onClose = vi.fn();
      function Harness({ open }: { open: boolean }) {
        return (
          <>
            <button type="button" onClick={() => {}}>
              Open popup
            </button>
            <Modal open={open} onClose={onClose} title="Get 10% off">
              <label htmlFor="popup-email">Email</label>
              <input id="popup-email" type="email" />
              <button type="button">Subscribe</button>
            </Modal>
          </>
        );
      }
      const { rerender } = render(<Harness open={false} />);
      const trigger = screen.getByRole("button", { name: "Open popup" });
      trigger.focus();
      rerender(<Harness open />);
      const email = screen.getByLabelText("Email");
      expect(email).toHaveFocus();
      // Tab from the last focusable (close button) wraps to the first field.
      const close = screen.getByRole("button", { name: "Close" });
      close.focus();
      fireEvent.keyDown(document, { key: "Tab" });
      expect(email).toHaveFocus();
      // Shift+Tab from the first field wraps to the close button.
      fireEvent.keyDown(document, { key: "Tab", shiftKey: true });
      expect(close).toHaveFocus();
      // ESC asks to close; closing returns focus to the trigger.
      fireEvent.keyDown(document, { key: "Escape" });
      expect(onClose).toHaveBeenCalledTimes(1);
      rerender(<Harness open={false} />);
      await waitFor(() => expect(screen.getByRole("button", { name: "Open popup" })).toHaveFocus());
    });

    it("locks body scroll while open and restores the previous value on close", () => {
      document.body.style.overflow = "auto";
      const { rerender } = render(
        <Modal open onClose={() => {}} title="Demo">
          <p>Body</p>
        </Modal>,
      );
      expect(document.body.style.overflow).toBe("hidden");
      rerender(
        <Modal open={false} onClose={() => {}} title="Demo">
          <p>Body</p>
        </Modal>,
      );
      expect(document.body.style.overflow).toBe("auto");
    });

    it("closes on overlay click, and does not close when dismissible is false", () => {
      const onClose = vi.fn();
      const { rerender } = render(
        <Modal open onClose={onClose} title="Demo">
          <p>Body</p>
        </Modal>,
      );
      fireEvent.click(document.querySelector(".ui-modal-overlay")!);
      expect(onClose).toHaveBeenCalledTimes(1);
      rerender(
        <Modal open onClose={onClose} title="Demo" dismissible={false}>
          <p>Body</p>
        </Modal>,
      );
      fireEvent.click(document.querySelector(".ui-modal-overlay")!);
      fireEvent.keyDown(document, { key: "Escape" });
      expect(onClose).toHaveBeenCalledTimes(1);
    });

    it("close button closes and honours a custom label and size", () => {
      const onClose = vi.fn();
      render(
        <Modal open onClose={onClose} title="Demo" closeLabel="Dismiss" size="sm">
          <p>Body</p>
        </Modal>,
      );
      expect(screen.getByRole("dialog")).toHaveClass("ui-modal--sm");
      fireEvent.click(screen.getByRole("button", { name: "Dismiss" }));
      expect(onClose).toHaveBeenCalledTimes(1);
    });
  });

  describe("Popover", () => {
    it("toggles from the trigger, names the panel, and closes on Escape", () => {
      const trigger = () =>
        render(
          <Popover triggerLabel="Size guide" panelLabel="Size guide">
            <p>Measure twice.</p>
            <a href="/search">Browse</a>
          </Popover>,
        );
      trigger();
      const button = screen.getByRole("button", { name: "Size guide" });
      expect(button).toHaveAttribute("aria-haspopup", "dialog");
      expect(button).toHaveAttribute("aria-expanded", "false");
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
      fireEvent.click(button);
      expect(button).toHaveAttribute("aria-expanded", "true");
      const panel = screen.getByRole("dialog", { name: "Size guide" });
      expect(panel).toBeInTheDocument();
      expect(screen.getByText("Measure twice.")).toBeInTheDocument();
      // Focus moved into the panel (first focusable: the link).
      expect(screen.getByRole("link", { name: "Browse" })).toHaveFocus();
      fireEvent.keyDown(document, { key: "Escape" });
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
      expect(button).toHaveAttribute("aria-expanded", "false");
    });

    it("closes on outside pointer down and when focus leaves the popover", () => {
      render(
        <>
          <button type="button">Outside</button>
          <Popover triggerLabel="Info" panelLabel="Info panel">
            <p>Details</p>
          </Popover>
        </>,
      );
      fireEvent.click(screen.getByRole("button", { name: "Info" }));
      expect(screen.getByRole("dialog")).toBeInTheDocument();
      fireEvent.pointerDown(screen.getByRole("button", { name: "Outside" }));
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
      fireEvent.click(screen.getByRole("button", { name: "Info" }));
      expect(screen.getByRole("dialog")).toBeInTheDocument();
      fireEvent.focusIn(screen.getByRole("button", { name: "Outside" }));
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });

    it("supports controlled open state", () => {
      const onOpenChange = vi.fn();
      const { rerender } = render(
        <Popover triggerLabel="Info" panelLabel="Info panel" open={false} onOpenChange={onOpenChange}>
          <p>Details</p>
        </Popover>,
      );
      fireEvent.click(screen.getByRole("button", { name: "Info" }));
      expect(onOpenChange).toHaveBeenCalledWith(true);
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
      rerender(
        <Popover triggerLabel="Info" panelLabel="Info panel" open onOpenChange={onOpenChange}>
          <p>Details</p>
        </Popover>,
      );
      expect(screen.getByRole("dialog")).toBeInTheDocument();
    });
  });

  describe("Badge", () => {
    it("maps kinds to tones and renders the label", () => {
      const { rerender } = render(<Badge kind="new" label="New" />);
      expect(screen.getByText("New")).toHaveClass("ui-badge--accent");
      rerender(<Badge kind="sale" label="-20%" />);
      expect(screen.getByText("-20%")).toHaveClass("ui-badge--danger");
      rerender(<Badge kind="best-seller" label="Best seller" size="sm" />);
      const best = screen.getByText("Best seller");
      expect(best).toHaveClass("ui-badge--success", "ui-badge--sm");
      rerender(<Badge kind="custom" tone="neutral" label="Limited" />);
      expect(screen.getByText("Limited")).toHaveClass("ui-badge--neutral");
      expect(screen.getByText("Limited")).toHaveAttribute("data-kind", "custom");
    });

    it("is plain text, not a control", () => {
      render(<Badge kind="sale" label="-20%" />);
      expect(screen.queryByRole("button", { name: "-20%" })).not.toBeInTheDocument();
      expect(screen.getByText("-20%").tagName).toBe("SPAN");
    });
  });

  describe("StickyBar", () => {
    it("renders a named region with a 44px action and reserves page space", () => {
      const onAction = vi.fn();
      render(
        <StickyBar title="Linen camp shirt" price="$68.00" actionLabel="Add to cart" onAction={onAction} />,
      );
      const region = screen.getByRole("region", { name: "Linen camp shirt" });
      expect(region).toBeInTheDocument();
      expect(screen.getByText("$68.00")).toBeInTheDocument();
      expect(document.documentElement.classList.contains("ui-has-sticky-bar")).toBe(true);
      const action = screen.getByRole("button", { name: "Add to cart" });
      fireEvent.click(action);
      expect(onAction).toHaveBeenCalledTimes(1);
    });

    it("renders nothing and frees the page reservation when hidden", () => {
      const { rerender } = render(
        <StickyBar title="Linen camp shirt" actionLabel="Add to cart" onAction={() => {}} visible />,
      );
      expect(screen.getByRole("region")).toBeInTheDocument();
      rerender(
        <StickyBar title="Linen camp shirt" actionLabel="Add to cart" onAction={() => {}} visible={false} />,
      );
      expect(screen.queryByRole("region")).not.toBeInTheDocument();
      expect(document.documentElement.classList.contains("ui-has-sticky-bar")).toBe(false);
    });

    it("disables the action while busy and shows the busy label", () => {
      render(
        <StickyBar
          title="Linen camp shirt"
          actionLabel="Add to cart"
          actionBusy
          actionBusyLabel="Adding…"
          onAction={() => {}}
        />,
      );
      const action = screen.getByRole("button", { name: "Adding…" });
      expect(action).toBeDisabled();
      expect(action).toHaveAttribute("aria-busy", "true");
    });

    it("renders an optional secondary action", () => {
      const onSecondary = vi.fn();
      render(
        <StickyBar
          title="Linen camp shirt"
          actionLabel="Add to cart"
          onAction={() => {}}
          secondaryLabel="Details"
          onSecondary={onSecondary}
        />,
      );
      fireEvent.click(screen.getByRole("button", { name: "Details" }));
      expect(onSecondary).toHaveBeenCalledTimes(1);
    });
  });

  describe("LiveRegion", () => {
    it("renders nothing for an empty message and a polite status otherwise", () => {
      const { rerender } = render(<LiveRegion message="" />);
      expect(screen.queryByRole("status")).not.toBeInTheDocument();
      rerender(<LiveRegion message="Subscribed — welcome code sent." />);
      const status = screen.getByRole("status");
      expect(status).toHaveAttribute("aria-live", "polite");
      expect(status).toHaveTextContent("Subscribed — welcome code sent.");
      rerender(<LiveRegion message="Card declined." politeness="assertive" visible />);
      expect(screen.getByRole("status")).toHaveAttribute("aria-live", "assertive");
      expect(screen.getByRole("status")).not.toHaveClass("sr-only");
    });
  });
});
