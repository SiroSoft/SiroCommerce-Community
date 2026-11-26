import { describe, expect, it } from "vitest";
import { getFieldErrors, unwrapData, unwrapList } from "../lib/envelope.js";

describe("unwrapData", () => {
  it("returns data on success", () => {
    expect(unwrapData({ success: true, message: "OK", data: { a: 1 } })).toEqual({ a: 1 });
  });
  it("throws on failure envelope", () => {
    expect(() => unwrapData({ success: false, message: "Bad", data: null })).toThrow("Bad");
  });
});

describe("unwrapList", () => {
  it("returns items + meta", () => {
    const meta = { page: 1, per_page: 20, total: 2, last_page: 1 };
    expect(unwrapList({ success: true, message: "OK", data: [1, 2], meta })).toEqual({ items: [1, 2], meta });
  });
  it("throws when meta missing", () => {
    expect(() => unwrapList({ success: true, message: "OK", data: [] })).toThrow("pagination");
  });
});

describe("getFieldErrors", () => {
  it("maps first message per field", () => {
    expect(getFieldErrors({ errors: { email: ["Required", "Invalid"] } })).toEqual({ email: "Required" });
  });
});
