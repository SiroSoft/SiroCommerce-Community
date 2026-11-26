"use client";

import type { ReactNode } from "react";
import { CartDrawer } from "@/components/CartDrawer";

export function StorefrontShell({ children }: { children: ReactNode }) {
  return <>{children}<CartDrawer /></>;
}
