import { test, expect } from "@playwright/test";

test.describe("Admin workbench API boundary", () => {
  test("rejects anonymous workbench access", async ({ request }) => {
    test.skip(!process.env.E2E_API_URL, "Requires a running backend API");
    const baseUrl = process.env.E2E_API_URL || "http://localhost:8080";
    const response = await request.get(`${baseUrl}/api/v2/admin/workbench`, { headers: { Accept: "application/json" } });
    expect(response.status()).toBe(401);
  });
});
