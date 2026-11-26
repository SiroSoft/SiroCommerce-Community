"use client";
import { createContext, useContext } from "react";
import type { ReactNode } from "react";
import { defaultTheme } from "./defaults";
import { themeCss } from "./theme-css";
import type { StorefrontTheme } from "./types";

const ThemeContext = createContext<StorefrontTheme>(defaultTheme);
export function ThemeProvider({ theme = defaultTheme, children }: { theme?: StorefrontTheme; children: ReactNode }) { return <ThemeContext.Provider value={theme}><div style={{ ...themeCss(theme), display: "contents" }} data-density={theme.tokens.typography.density}>{children}</div></ThemeContext.Provider>; }
export function useTheme(): StorefrontTheme { return useContext(ThemeContext); }
