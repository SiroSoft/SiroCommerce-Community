import type { CSSProperties } from "react";
import type { StorefrontTheme } from "./types";

export type ThemeStyle = CSSProperties & Record<`--theme-${string}`, string>;
const radiusValues = { sharp: ["2px", "4px", "8px"], soft: ["3px", "8px", "16px"], round: ["6px", "14px", "999px"] } as const;

export function themeCss(theme: StorefrontTheme): ThemeStyle {
  const colors = theme.tokens.colors;
  const radius = radiusValues[theme.tokens.shape.radius];
  const color = (name: string) => colors[name] ?? "#000000";
  const type = theme.tokens.typography;
  const style: ThemeStyle = { "--theme-ink": color("ink"), "--theme-muted": color("muted"), "--theme-paper": color("paper"), "--theme-surface": color("surface"), "--theme-accent": color("accent"), "--theme-accent-dark": color("accent_dark"), "--theme-success": color("success"), "--theme-danger": color("danger"), "--theme-content-width": "1380px", "--theme-reading-width": "760px", "--theme-radius-sm": radius[0], "--theme-radius-md": radius[1], "--theme-radius-lg": radius[2] };
  if (type.display_stack) style["--theme-font-display"] = type.display_stack;
  if (type.body_stack) style["--theme-font-body"] = type.body_stack;
  if (typeof type.base_size === "number") style["--theme-font-size"] = `${type.base_size}px`;
  return style;
}
