"use client";

import { createContext, useContext, type ReactNode } from "react";

const ThemeMediaContext = createContext<string[]>([]);

export function ThemeMediaProvider({ images, children }: { images: string[]; children: ReactNode }) {
  return <ThemeMediaContext.Provider value={images}>{children}</ThemeMediaContext.Provider>;
}

function hash(value: string): number {
  let result = 0;
  for (let index = 0; index < value.length; index += 1) result = (result * 31 + value.charCodeAt(index)) >>> 0;
  return result;
}

export function useThemeProductImage(key: string, fallback: string): string {
  const images = useContext(ThemeMediaContext);
  if (images.length === 0) return fallback;
  return images[hash(key) % images.length] ?? images[0] ?? fallback;
}
