import "@testing-library/jest-dom/vitest";
import { act, render, screen } from "@testing-library/react";
import { describe, expect, it, vi, beforeEach } from "vitest";
import { TourProvider, WELCOME_TOUR_ID, WELCOME_TOUR_STEPS, hasSeenTour, useTour } from "@/components/guided-tour";

vi.mock("next/navigation", () => ({ usePathname: () => "/", useRouter: () => ({ push: vi.fn() }) }));

function Starter() {
  const tour = useTour();
  return <button onClick={() => tour.start(WELCOME_TOUR_ID, WELCOME_TOUR_STEPS.slice(0, 2))}>Start</button>;
}

describe("guided tour", () => {
  beforeEach(() => {
    localStorage.clear();
    Element.prototype.getBoundingClientRect = () =>
      ({ top: 100, left: 100, width: 200, height: 40, bottom: 140, right: 300, x: 100, y: 100, toJSON: () => ({}) }) as DOMRect;
  });

  it("walks steps, finishes, and remembers", async () => {
    render(
      <TourProvider>
        <div data-tour="sidebar">nav</div>
        <div data-tour="global-search">search</div>
        <Starter />
      </TourProvider>,
    );
    expect(hasSeenTour(WELCOME_TOUR_ID)).toBe(false);
    await act(async () => {
      screen.getByText("Start").click();
      await new Promise((resolve) => setTimeout(resolve, 120));
    });
    expect(await screen.findByText("Navigation, scoped by role")).toBeVisible();
    await act(async () => {
      screen.getByText("Next").click();
      await new Promise((resolve) => setTimeout(resolve, 60));
    });
    expect(await screen.findByText("Search orders, products, customers")).toBeVisible();
    await act(async () => {
      screen.getByText("Finish").click();
    });
    expect(hasSeenTour(WELCOME_TOUR_ID)).toBe(true);
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("skips silently when targets are missing", async () => {
    render(
      <TourProvider>
        <Starter />
      </TourProvider>,
    );
    await act(async () => {
      screen.getByText("Start").click();
      await new Promise((resolve) => setTimeout(resolve, 120));
    });
    expect(screen.queryByRole("dialog")).toBeNull();
  });
});
