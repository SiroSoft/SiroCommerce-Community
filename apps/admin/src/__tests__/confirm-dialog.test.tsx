import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { useState } from "react";
import { ConfirmDialog } from "@/components/confirm-dialog";

function Harness({ onConfirm = vi.fn() }: { onConfirm?: () => void }) {
  const [open, setOpen] = useState(false);
  return (
    <div>
      <button type="button" onClick={() => setOpen(true)}>
        Open confirmation
      </button>
      <ConfirmDialog
        open={open}
        title="Archive product"
        description="This changes its lifecycle state and cannot be undone."
        onCancel={() => setOpen(false)}
        onConfirm={() => {
          onConfirm();
          setOpen(false);
        }}
      />
    </div>
  );
}

async function openDialog() {
  const trigger = screen.getByRole("button", { name: "Open confirmation" });
  trigger.focus();
  fireEvent.click(trigger);
  return screen.findByRole("dialog", { name: "Archive product" });
}

afterEach(() => {
  cleanup();
});

describe("ConfirmDialog", () => {
  it("exposes an accessible modal dialog with title, description, Cancel and Confirm", async () => {
    render(<Harness />);
    const dialog = await openDialog();

    expect(dialog).toHaveAttribute("aria-modal", "true");
    expect(dialog).toHaveAccessibleDescription("This changes its lifecycle state and cannot be undone.");
    expect(screen.getByRole("button", { name: "Cancel" })).toBeVisible();
    expect(screen.getByRole("button", { name: "Confirm" })).toBeVisible();
  });

  it("moves focus into the dialog and keeps Tab focus trapped", async () => {
    render(<Harness />);
    const dialog = await openDialog();
    const cancel = screen.getByRole("button", { name: "Cancel" });
    const confirm = screen.getByRole("button", { name: "Confirm" });

    await waitFor(() => expect(cancel).toHaveFocus());

    confirm.focus();
    fireEvent.keyDown(dialog, { key: "Tab" });
    expect(cancel).toHaveFocus();

    cancel.focus();
    fireEvent.keyDown(dialog, { key: "Tab", shiftKey: true });
    expect(confirm).toHaveFocus();
  });

  it("closes on Escape and returns focus to the trigger", async () => {
    render(<Harness />);
    const trigger = screen.getByRole("button", { name: "Open confirmation" });
    const dialog = await openDialog();

    fireEvent.keyDown(dialog, { key: "Escape" });
    await waitFor(() => expect(screen.queryByRole("dialog", { name: "Archive product" })).toBeNull());
    await waitFor(() => expect(trigger).toHaveFocus());
  });

  it("cancels without confirming and restores focus", async () => {
    const onConfirm = vi.fn();
    render(<Harness onConfirm={onConfirm} />);
    const trigger = screen.getByRole("button", { name: "Open confirmation" });
    await openDialog();

    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    await waitFor(() => expect(screen.queryByRole("dialog", { name: "Archive product" })).toBeNull());
    expect(onConfirm).not.toHaveBeenCalled();
    await waitFor(() => expect(trigger).toHaveFocus());
  });

  it("invokes onConfirm only when Confirm is pressed", async () => {
    const onConfirm = vi.fn();
    render(<Harness onConfirm={onConfirm} />);
    await openDialog();

    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(onConfirm).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(screen.queryByRole("dialog", { name: "Archive product" })).toBeNull());
  });

  it("renders nothing while closed", () => {
    render(<Harness />);
    expect(screen.queryByRole("dialog")).toBeNull();
  });
});
