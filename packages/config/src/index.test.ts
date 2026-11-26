import { describe, expect, it } from "vitest";
import { API_ACCEPT, API_VERSION, createPlatformConfig } from "./index";

describe("platform config", () => {
  it("provides stable API defaults and trims the base URL", () => {
    expect(API_ACCEPT).toBe("application/json"); expect(API_VERSION).toBe("v1");
    expect(createPlatformConfig("https://api.test///", { feToken: "fe", locale: "en" })).toEqual({ baseUrl: "https://api.test//", apiVersion: "v1", feToken: "fe", locale: "en" });
  });
});
