import type { PurchaseController } from "./usePurchaseFlow";

export function FixtureControls({ flow }: { flow: PurchaseController }) {
  const { copy, step, cart, variant, selectFixtureVariant, authenticateFixtureCustomer, setFixtureStatus, fixtureStatus, isPending, navigate, testMode } = flow;
  if (!testMode) return null;
  return <aside className="purchase-panel" aria-label="Fixture controls">
    <p className="eyebrow">Fixture controls</p>
    {step === "cart" && <>
      <label htmlFor="fixture-variant">Variant<select id="fixture-variant" aria-label="Variant" value={variant} onChange={(event) => selectFixtureVariant(event.target.value)}><option value="siro-dao">Siro dao</option><option value="siro-chanh">Siro chanh</option></select></label>
      <p data-testid="cart-token">Cart token: {cart?.token ?? "cart-fixture-1"}</p>
    </>}
    {step === "checkout" && <>
      <button className="text-button" type="button" onClick={() => void authenticateFixtureCustomer()}>Test customer authentication</button>
      <button className="text-button" type="button" onClick={() => setFixtureStatus("401 Unauthorized")}>Show unauthorized API state</button>
      <p className="form-notice" role="note">Card authorized in fixture mode only. Provider payment, credit, and vendor payout actions are unavailable.</p>
    </>}
    {step === "order-result" && isPending && <button className="text-button" type="button" onClick={() => navigate("cart")}>Restore cart and start another order</button>}
    {fixtureStatus && <p className="form-notice" role="note">{fixtureStatus}</p>}
  </aside>;
}
