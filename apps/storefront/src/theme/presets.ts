import { defaultTheme } from "./defaults";
import type { StorefrontTheme } from "./types";

/**
 * Built-in starting points a developer clones to make a child theme.
 * Copy one entry, change the key, keep only the overrides, and point
 * `extends` at the parent. Nothing here is fetched: these are authoring
 * defaults, while the backend remains the source of the active theme.
 */
export const themePresets: Record<string, StorefrontTheme> = {
  general: defaultTheme,
  noir: {
    ...defaultTheme,
    key: "noir",
    extends: "general",
    brand: { ...defaultTheme.brand },
    tokens: {
      colors: {
        ink: "#EDEAE2",
        muted: "#A8A294",
        paper: "#16130F",
        surface: "#201B15",
        accent: "#D08A5A",
        accent_dark: "#B96F42",
        success: "#7FB08A",
        danger: "#D9786A",
      },
      typography: { ...defaultTheme.tokens.typography },
      shape: { ...defaultTheme.tokens.shape, radius: "soft", card: "elevated" },
    },
  },
  forest: {
    ...defaultTheme,
    key: "forest",
    extends: "general",
    brand: { ...defaultTheme.brand },
    tokens: {
      colors: {
        ink: "#1C2620",
        muted: "#5C6E60",
        paper: "#F1F4EC",
        surface: "#FBFDF7",
        accent: "#2F6B3C",
        accent_dark: "#234E2D",
        success: "#2F6B3C",
        danger: "#A13F35",
      },
      typography: { ...defaultTheme.tokens.typography, density: "comfortable" },
      shape: { ...defaultTheme.tokens.shape, radius: "round", card: "bordered" },
    },
  },
  seasonal: {
    ...defaultTheme,
    key: "seasonal",
    extends: "forest",
    brand: { ...defaultTheme.brand, name: "Seasonal", wordmark: "seasonal/edit" },
    tokens: {
      colors: {
        ink: "#1A211D",
        muted: "#5F6D61",
        paper: "#F6F5EF",
        surface: "#FFFEFA",
        accent: "#7A3B2E",
        accent_dark: "#5E2E24",
        success: "#35664A",
        danger: "#A13F35",
      },
      typography: { ...defaultTheme.tokens.typography, density: "comfortable" },
      shape: { ...defaultTheme.tokens.shape, radius: "soft", card: "bordered" },
    },
  },
};
