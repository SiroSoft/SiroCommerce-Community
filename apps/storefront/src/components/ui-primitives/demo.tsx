"use client";

import { useState } from "react";
import { Badge, LiveRegion, Modal, Popover, StickyBar } from "./index";

/**
 * Interactive demo for the axe E2E spec. Rendered ONLY by the test-mode
 * route /dev/ui-primitives (404 in production).
 */
export function UiPrimitivesDemo() {
  const [modalOpen, setModalOpen] = useState(false);
  const [notice, setNotice] = useState("");
  const [barVisible, setBarVisible] = useState(true);
  const [added, setAdded] = useState(false);

  return (
    <main className="ui-demo">
      <h1>UI primitives demo</h1>

      <section aria-labelledby="demo-badges-heading">
        <h2 id="demo-badges-heading">Badges</h2>
        <p className="ui-demo-badges">
          <Badge kind="new" label="New" />
          <Badge kind="sale" label="-20%" />
          <Badge kind="best-seller" label="Best seller" />
          <Badge kind="custom" tone="neutral" label="Limited edition drop" />
        </p>
      </section>

      <section aria-labelledby="demo-modal-heading">
        <h2 id="demo-modal-heading">Modal</h2>
        <button type="button" className="button ui-demo-open" onClick={() => setModalOpen(true)}>
          Open demo modal
        </button>
        <Modal
          open={modalOpen}
          onClose={() => setModalOpen(false)}
          title="Demo modal"
          description="A modal dialog used by the accessibility checks."
        >
          <form
            onSubmit={(event) => {
              event.preventDefault();
              setNotice("Subscribed — welcome code sent.");
              setModalOpen(false);
            }}
          >
            <label htmlFor="demo-email">Email</label>
            <input id="demo-email" name="email" type="email" autoComplete="email" required />
            <button type="submit" className="button">
              Subscribe
            </button>
          </form>
        </Modal>
        <LiveRegion message={notice} />
      </section>

      <section aria-labelledby="demo-popover-heading">
        <h2 id="demo-popover-heading">Popover</h2>
        <Popover triggerLabel="Size guide" panelLabel="Size guide">
          <p>Measure your chest and pick the closest size. When in doubt, size up.</p>
          <a href="/search">Browse the catalogue</a>
        </Popover>
      </section>

      <section aria-labelledby="demo-sticky-heading">
        <h2 id="demo-sticky-heading">Sticky bar</h2>
        <label>
          <input
            type="checkbox"
            checked={barVisible}
            onChange={(event) => setBarVisible(event.target.checked)}
          />{" "}
          Show sticky bar
        </label>
        <p>
          When the bar is visible, this page reserves exactly the bar height at
          the bottom, so the text below is never covered by it.
        </p>
      </section>

      <p id="demo-end">
        End of demo content. If the sticky bar covered this line, the layout
        reservation would be broken.
      </p>

      <StickyBar
        title="Linen camp shirt"
        price="$68.00"
        actionLabel={added ? "Added to bag" : "Add to cart"}
        onAction={() => setAdded(true)}
        visible={barVisible}
      />
    </main>
  );
}
