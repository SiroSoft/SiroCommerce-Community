import { verticalPresets } from "./presets";
import type { VerticalKey, VerticalPreset } from "./types";
export function resolveVertical(value: unknown): VerticalPreset { return typeof value === "string" && value in verticalPresets ? verticalPresets[value as VerticalKey] : verticalPresets.general; }
