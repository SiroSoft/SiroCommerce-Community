"use client";

import { useEffect, useMemo, useState, type FormEvent } from "react";
import { customer, CustomerRequestError, type CustomerAdapter } from "@/services/customer";
import { fill } from "@/lib/i18n";
import { useCopy } from "@/lib/i18n-client";

type LoadState = "loading" | "ready" | "error";

export function AccountLoyalty({ sessionToken, adapter }: { sessionToken?: string; adapter?: CustomerAdapter }) {
  const copy = useCopy().accountLoyalty;
  const client = useMemo(() => adapter ?? customer(), [adapter]);
  const signedIn = Boolean(sessionToken);
  const [loadState, setLoadState] = useState<LoadState>("loading");
  const [balance, setBalance] = useState(0);
  const [accountState, setAccountState] = useState("");
  const [loadError, setLoadError] = useState("");
  const [reload, setReload] = useState(0);
  const [points, setPoints] = useState("");
  const [confirming, setConfirming] = useState(false);
  const [pointsError, setPointsError] = useState("");
  const [redeemBusy, setRedeemBusy] = useState(false);
  const [redeemMessage, setRedeemMessage] = useState("");
  const [redeemOk, setRedeemOk] = useState(false);
  const [earnOrderId, setEarnOrderId] = useState("");
  const [earnError, setEarnError] = useState("");
  const [earnBusy, setEarnBusy] = useState(false);
  const [earnMessage, setEarnMessage] = useState("");

  useEffect(() => {
    if (!signedIn) return;
    let active = true;
    setLoadState("loading");
    setLoadError("");
    client
      .getLoyalty()
      .then((value) => {
        if (!active) return;
        setBalance(value.balance_points);
        setAccountState(value.state ?? "");
        setLoadState("ready");
      })
      .catch((error) => {
        if (!active) return;
        setLoadState("error");
        setLoadError(error instanceof CustomerRequestError && error.status === 401 ? copy.signInRequired : copy.loadError);
      });
    return () => {
      active = false;
    };
  }, [client, signedIn, reload, copy.loadError, copy.signInRequired]);

  if (!signedIn) {
    return (
      <section className="account-panel" aria-labelledby="account-loyalty-title">
        <div className="account-section-heading">
          <h3 id="account-loyalty-title">{copy.title}</h3>
        </div>
        <p className="muted" role="note">{copy.signInRequired}</p>
      </section>
    );
  }

  async function refreshBalance() {
    const value = await client.getLoyalty();
    setBalance(value.balance_points);
    setAccountState(value.state ?? "");
  }

  function startRedeem(event: FormEvent) {
    event.preventDefault();
    const parsed = Number(points);
    if (!Number.isInteger(parsed) || parsed < 1) {
      setPointsError(copy.needPoints);
      return;
    }
    setPointsError("");
    setRedeemMessage("");
    setRedeemOk(false);
    setConfirming(true);
  }

  async function confirmRedeem() {
    const parsed = Number(points);
    setRedeemBusy(true);
    setRedeemMessage("");
    setRedeemOk(false);
    try {
      await client.redeemLoyalty(parsed);
      await refreshBalance();
      setRedeemMessage(fill(copy.redeemSuccess, { points: parsed }));
      setRedeemOk(true);
      setConfirming(false);
      setPoints("");
    } catch (error) {
      setRedeemMessage(error instanceof CustomerRequestError && error.status === 401 ? copy.signInRequired : copy.redeemError);
      setRedeemOk(false);
    } finally {
      setRedeemBusy(false);
    }
  }

  async function earn(event: FormEvent) {
    event.preventDefault();
    if (!earnOrderId.trim()) {
      setEarnError(copy.requiredField);
      return;
    }
    setEarnError("");
    setEarnMessage("");
    setEarnBusy(true);
    try {
      await client.earnLoyalty(earnOrderId.trim());
      await refreshBalance();
      setEarnMessage(copy.earnSuccess);
      setEarnOrderId("");
    } catch (error) {
      setEarnMessage(error instanceof CustomerRequestError && error.status === 401 ? copy.signInRequired : copy.earnError);
    } finally {
      setEarnBusy(false);
    }
  }

  return (
    <section className="account-panel" aria-labelledby="account-loyalty-title">
      <div className="account-section-heading">
        <h3 id="account-loyalty-title">{copy.title}</h3>
      </div>
      <p className="muted">{copy.intro}</p>
      {loadState === "loading" && (
        <p className="muted" role="status">{copy.loading}</p>
      )}
      {loadState === "error" && (
        <>
          <p className="form-error" role="alert">{loadError}</p>
          <button className="text-button" type="button" onClick={() => setReload((value) => value + 1)}>
            {copy.retry}
          </button>
        </>
      )}
      {loadState === "ready" && (
        <>
          <div className="loyalty-balance" role="status" aria-live="polite">
            <strong>{balance}</strong>
            <span>{copy.balance}</span>
            {accountState && (
              <span className="muted">{copy.state}: {accountState}</span>
            )}
          </div>
          {balance === 0 && <p className="muted">{copy.empty}</p>}
          <p className="muted" role="note">{copy.noHistory}</p>
          <h4>{copy.redeemTitle}</h4>
          <form className="auth-form" onSubmit={startRedeem} noValidate>
            <label htmlFor="account-loyalty-points">
              {copy.pointsLabel}
              <input
                id="account-loyalty-points"
                type="number"
                min={1}
                step={1}
                value={points}
                onChange={(event) => {
                  setPoints(event.target.value);
                  setConfirming(false);
                }}
                aria-invalid={Boolean(pointsError)}
                aria-describedby={pointsError ? "account-loyalty-points-error" : undefined}
              />
            </label>
            {pointsError && (
              <p id="account-loyalty-points-error" className="form-error" role="alert">{pointsError}</p>
            )}
            {!confirming && (
              <button className="button" type="submit">
                {copy.redeem}
              </button>
            )}
          </form>
          {confirming && (
            <div role="group" aria-label={copy.redeemTitle}>
              <p className="form-notice" role="status">{fill(copy.confirmRedeem, { points: Number(points) })}</p>
              <div className="state-actions">
                <button
                  className="button"
                  type="button"
                  onClick={() => void confirmRedeem()}
                  aria-busy={redeemBusy}
                  disabled={redeemBusy}
                >
                  {redeemBusy ? copy.redeeming : copy.confirm}
                </button>
                <button className="text-button" type="button" onClick={() => setConfirming(false)} disabled={redeemBusy}>
                  {copy.cancel}
                </button>
              </div>
            </div>
          )}
          {redeemMessage && (
            <p className={redeemOk ? "form-notice" : "form-error"} role={redeemOk ? "status" : "alert"}>{redeemMessage}</p>
          )}
          <h4>{copy.earnTitle}</h4>
          <p className="muted">{copy.earnIntro}</p>
          <form className="auth-form" onSubmit={earn} noValidate>
            <label htmlFor="account-loyalty-order-id">
              {copy.orderIdLabel}
              <input
                id="account-loyalty-order-id"
                value={earnOrderId}
                onChange={(event) => setEarnOrderId(event.target.value)}
                aria-invalid={Boolean(earnError)}
                aria-describedby={earnError ? "account-loyalty-order-id-error" : undefined}
                autoComplete="off"
              />
            </label>
            {earnError && (
              <p id="account-loyalty-order-id-error" className="form-error" role="alert">{earnError}</p>
            )}
            <button className="button" type="submit" aria-busy={earnBusy} disabled={earnBusy}>
              {earnBusy ? copy.earning : copy.earn}
            </button>
            {earnMessage && (
              <p className={earnMessage === copy.earnSuccess ? "form-notice" : "form-error"} role={earnMessage === copy.earnSuccess ? "status" : "alert"}>{earnMessage}</p>
            )}
          </form>
        </>
      )}
    </section>
  );
}
