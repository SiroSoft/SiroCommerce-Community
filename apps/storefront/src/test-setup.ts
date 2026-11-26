import "@testing-library/jest-dom/vitest";
import { cleanup } from "@testing-library/react";
import { afterEach } from "vitest";

// Every test renders real components; without unmounting them between tests,
// timers (flash countdowns, wishlist polling) keep firing after the jsdom
// environment is torn down and surface as "window is not defined" in CI.
afterEach(() => {
  cleanup();
});
