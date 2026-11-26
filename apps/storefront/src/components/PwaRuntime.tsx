"use client";

import { useEffect, useState } from "react";
import { trackWebVitals } from "@/services/storefront-events";
import { useCopy } from "@/lib/i18n-client";

export function PwaRuntime() {
  const copy = useCopy();
  const [offline, setOffline] = useState(false);
  useEffect(() => {
    const update = () => setOffline(!navigator.onLine);
    update();
    window.addEventListener("online", update);
    window.addEventListener("offline", update);
    if ("serviceWorker" in navigator) void navigator.serviceWorker.register("/sw.js", { scope: "/" }).catch(() => undefined);
    trackWebVitals();
    return () => { window.removeEventListener("online", update); window.removeEventListener("offline", update); };
  }, []);
  return offline ? <div className="offline-banner" role="status">{copy.states.offlineBanner}</div> : null;
}
