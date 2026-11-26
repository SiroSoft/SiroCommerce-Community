"use client";

import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export interface TourStep {
  target: string;
  title: string;
  body: string;
  href?: string;
}

interface TourState {
  tourId: string | null;
  stepIndex: number;
  start: (tourId: string, steps: TourStep[]) => void;
  stop: (completed: boolean) => void;
  next: () => void;
  back: () => void;
}

const TourContext = createContext<TourState | null>(null);

const seenKey = (tourId: string) => `siro-tour-seen-${tourId}`;

export function hasSeenTour(tourId: string): boolean {
  try {
    return localStorage.getItem(seenKey(tourId)) === "1";
  } catch {
    return true;
  }
}

function markTourSeen(tourId: string) {
  try {
    localStorage.setItem(seenKey(tourId), "1");
  } catch {
    // private mode — tour simply won't auto-start
  }
}

const noopTour: TourState = {
  tourId: null,
  stepIndex: 0,
  start: () => undefined,
  stop: () => undefined,
  next: () => undefined,
  back: () => undefined,
};

export function useTour(): TourState {
  // No-op outside the provider so isolated component renders never crash.
  return useContext(TourContext) ?? noopTour;
}

function rectOf(selector: string): DOMRect | null {
  const element = document.querySelector(selector);
  if (!element) return null;
  const rect = element.getBoundingClientRect();
  if (rect.width === 0 && rect.height === 0) return null;
  return rect;
}

export function TourProvider({ children }: { children: React.ReactNode }) {
  const [tourId, setTourId] = useState<string | null>(null);
  const [steps, setSteps] = useState<TourStep[]>([]);
  const [stepIndex, setStepIndex] = useState(0);
  const [rect, setRect] = useState<DOMRect | null>(null);
  const router = useRouter();
  const pathname = usePathname();
  const waitTimer = useRef(0);

  const stop = useCallback(
    (completed: boolean) => {
      if (tourId && completed) markTourSeen(tourId);
      if (tourId && !completed) markTourSeen(tourId);
      window.clearTimeout(waitTimer.current);
      setTourId(null);
      setSteps([]);
      setStepIndex(0);
      setRect(null);
    },
    [tourId],
  );

  const resolve = useCallback(
    (index: number, list: TourStep[]) => {
      const step = list[index];
      if (!step) {
        stop(true);
        return;
      }
      const found = rectOf(step.target);
      if (found) {
        setStepIndex(index);
        setRect(found);
        const element = document.querySelector(step.target);
        if (element && typeof (element as HTMLElement).scrollIntoView === "function") {
          (element as HTMLElement).scrollIntoView({ block: "nearest", behavior: "smooth" });
        }
        return;
      }
      // Target on another route: navigate once, then resolve again.
      if (step.href && window.location.pathname !== step.href) {
        router.push(step.href);
        window.clearTimeout(waitTimer.current);
        let attempts = 0;
        const poll = () => {
          attempts += 1;
          const next = rectOf(step.target);
          if (next) {
            setStepIndex(index);
            setRect(next);
            return;
          }
          if (attempts < 20) waitTimer.current = window.setTimeout(poll, 150);
          else resolve(index + 1, list);
        };
        waitTimer.current = window.setTimeout(poll, 150);
        return;
      }
      // Missing target: skip the step rather than stranding the user.
      resolve(index + 1, list);
    },
    [router, stop],
  );

  const start = useCallback(
    (id: string, list: TourStep[]) => {
      setTourId(id);
      setSteps(list);
      setStepIndex(0);
      setRect(null);
      window.setTimeout(() => resolve(0, list), 50);
    },
    [resolve],
  );

  const next = useCallback(() => {
    if (stepIndex + 1 >= steps.length) stop(true);
    else resolve(stepIndex + 1, steps);
  }, [stepIndex, steps, resolve, stop]);

  const back = useCallback(() => {
    if (stepIndex > 0) resolve(stepIndex - 1, steps);
  }, [stepIndex, steps, resolve]);

  // Re-anchor on route change while a tour is active.
  useEffect(() => {
    if (!tourId || steps.length === 0) return;
    resolve(stepIndex, steps);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pathname]);

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape" && tourId) stop(false);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [tourId, stop]);

  const step = steps[stepIndex] ?? null;
  const last = stepIndex === steps.length - 1;

  return (
    <TourContext.Provider value={{ tourId, stepIndex, start, stop, next, back }}>
      {children}
      {tourId && step && rect && (
        <div className="fixed inset-0 z-[90]" role="dialog" aria-modal="true" aria-label={step.title}>
          <div className="absolute inset-0 bg-black/55" onClick={() => stop(false)} />
          <div
            className="absolute rounded-lg ring-4 ring-primary transition-all duration-200"
            style={{ top: rect.top - 6, left: rect.left - 6, width: rect.width + 12, height: rect.height + 12, pointerEvents: "none" }}
          />
          <div
            className={cn(
              "absolute w-80 max-w-[calc(100vw-2rem)] rounded-lg border bg-popover p-4 shadow-xl",
              rect.top > 320 ? "-translate-y-[calc(100%+16px)]" : "translate-y-4",
            )}
            style={{ top: rect.top > 320 ? rect.top - 6 : rect.bottom + 6, left: Math.max(8, Math.min(rect.left, window.innerWidth - 336)) }}
          >
            <p className="text-xs font-medium text-muted-foreground">
              Step {stepIndex + 1} of {steps.length}
            </p>
            <h2 className="mt-1 font-semibold">{step.title}</h2>
            <p className="mt-1 text-sm text-muted-foreground">{step.body}</p>
            <div className="mt-3 flex items-center justify-between gap-2">
              <Button variant="ghost" size="sm" onClick={() => stop(false)}>
                Skip tour
              </Button>
              <div className="flex gap-2">
                {stepIndex > 0 && (
                  <Button variant="outline" size="sm" onClick={back}>
                    Back
                  </Button>
                )}
                <Button size="sm" onClick={next} autoFocus>
                  {last ? "Finish" : "Next"}
                </Button>
              </div>
            </div>
          </div>
        </div>
      )}
    </TourContext.Provider>
  );
}

export const WELCOME_TOUR_ID = "admin-welcome";

export const WELCOME_TOUR_STEPS: TourStep[] = [
  {
    target: '[data-tour="sidebar"]',
    title: "Navigation, scoped by role",
    body: "Every section here respects your role. You only see what you are allowed to operate — owners see all 26 sections.",
  },
  {
    target: '[data-tour="global-search"]',
    title: "Search orders, products, customers",
    body: "Type at least 2 characters to search live records. Tip: press Ctrl+K anywhere to jump here instantly.",
    href: "/",
  },
  {
    target: '[data-tour="dashboard-stats"]',
    title: "Store health at a glance",
    body: "Revenue, orders and refunds update live. Click any card to drill into the underlying workspace.",
    href: "/",
  },
  {
    target: '[data-tour="orders-table"]',
    title: "Orders with bulk power",
    body: "Select many orders to transition, refund or print them together. Open one for payments, timeline and returns.",
    href: "/orders",
  },
  {
    target: '[data-tour="theme-builder"]',
    title: "Theme builder",
    body: "Change any storefront look without a deploy: edit tokens, preview, then publish. Revisions let you roll back.",
    href: "/storefront/theme",
  },
];
