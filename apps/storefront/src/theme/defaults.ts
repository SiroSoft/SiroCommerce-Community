import type { StorefrontTheme } from "./types";

export const defaultTheme: StorefrontTheme = {
  key: "general",
  version: "1.0.0",
  schemaVersion: 1,
  brand: { name: "SiroCommerce", wordmark: "siro/commerce" },
  tokens: {
    colors: {
      ink: "#1E2420", muted: "#536057", paper: "#F5F5F0", surface: "#FFFEFA",
      accent: "#883C29", accent_dark: "#693021", success: "#35664A", danger: "#A13F35",
    },
    typography: { display: "editorial", body: "system", density: "comfortable", display_stack: "", body_stack: "", base_size: 16 },
    shape: { radius: "soft", card: "bordered" },
  },
};

export const defaultThemeColors = Object.keys(defaultTheme.tokens.colors);
