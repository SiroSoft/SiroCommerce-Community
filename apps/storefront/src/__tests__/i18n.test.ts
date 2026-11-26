import { describe, expect, it } from "vitest";
import { readFileSync, readdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { negotiateLocale, normalizeLocale } from "../lib/locale";
import { getCopy } from "../lib/i18n";
import { parseCustomerPreferences, writeCustomerPreferences, readCustomerPreferences } from "../lib/preferences";

function readJson(path: string): unknown {
  return JSON.parse(readFileSync(path, "utf8").replace(/^﻿/, ""));
}

const root = join(dirname(fileURLToPath(import.meta.url)), "..", "locales");
const locales = ["en", "vi", "ja", "de", "zh"];

function keys(o: unknown, p = ""): string[] {
  if (typeof o !== "object" || o === null) return [p];
  return Object.entries(o as Record<string, unknown>).flatMap(([k, v]) =>
    keys(v, p === "" ? k : `${p}.${k}`),
  );
}

describe("frontend i18n parity (5 locales)", () => {
  it("all locales share en key set with no empty values", () => {
    const ref = keys(readJson(join(root, "en", "common.json")));
    for (const l of locales) {
      const data = readJson(join(root, l, "common.json"));
      const flat = new Map<string, unknown>();
      const walk = (o: unknown, p = ""): void => {
        if (typeof o !== "object" || o === null) {
          flat.set(p, o);
          return;
        }
        for (const [k, v] of Object.entries(o as Record<string, unknown>)) walk(v, p === "" ? k : `${p}.${k}`);
      };
      walk(data);
      for (const k of ref) {
        expect(flat.has(k), `${l} missing ${k}`).toBe(true);
        expect(flat.get(k), `${l} empty ${k}`).not.toBe("");
      }
    }
    expect(readdirSync(root).sort()).toEqual([...locales].sort());
  });

  it("negotiates supported regional tags and fails closed for unsupported locales", () => {
    expect(normalizeLocale("vi-VN")).toBe("vi");
    expect(negotiateLocale("fr-CA, de;q=0.8")).toBe("de");
    expect(negotiateLocale("fr-CA")).toBe("en");
    expect(getCopy("ja").navigation.search).toBe("検索");
  });

  it("persists only validated locale and currency preferences", () => {
    const storage = new Map<string, string>();
    const fake = { getItem: (key: string) => storage.get(key) ?? null, setItem: (key: string, value: string) => { storage.set(key, value); } } as unknown as Storage;
    expect(writeCustomerPreferences({ locale: "de", currency: "EUR" }, fake)).toBe(true);
    expect(readCustomerPreferences(fake)).toEqual({ locale: "de", currency: "EUR" });
    expect(parseCustomerPreferences({ locale: "xx", currency: "USD" })).toBeUndefined();
    expect(parseCustomerPreferences({ locale: "en", currency: "BTC" })).toBeUndefined();
  });
});
