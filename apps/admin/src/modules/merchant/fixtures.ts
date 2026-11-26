/** Local fixtures are opt-in previews only and never stand in for live API data. */
export const merchantFixtures = {
  smartAction: { available: false, reason: "Smart Action is not in the current API contract." },
  fulfillment: { available: true, reason: "Live fulfillment routes are available through the shared API client." },
  inventory: [
    { sku: "DEMO-BLUE-M", name: "Demo shirt / Blue / M", onHand: 24, reserved: 3, incoming: 8 },
    { sku: "DEMO-BLUE-L", name: "Demo shirt / Blue / L", onHand: 9, reserved: 2, incoming: 0 },
    { sku: "DEMO-WHITE-M", name: "Demo shirt / White / M", onHand: 0, reserved: 0, incoming: 12 },
  ],
} as const;

export type MerchantFixtureState = "loading" | "empty" | "success" | "unavailable";
