import { afterEach, describe, expect, it } from "vitest";
import { clearApiScope, setApiScope } from "@/services/api";
import { scopedQueryKey } from "@/services/query-keys";

describe("admin commerce-scoped query keys", () => {
  afterEach(() => clearApiScope());

  it("separates cached resources when the commerce scope changes", () => {
    setApiScope({ store_id: "store-a", channel_id: "web", locale: "en", currency: "USD" });
    const first = scopedQueryKey("products", { page: 1 });
    setApiScope({ store_id: "store-b", channel_id: "web", locale: "en", currency: "USD" });
    const second = scopedQueryKey("products", { page: 1 });

    expect(first).not.toEqual(second);
  });
});
