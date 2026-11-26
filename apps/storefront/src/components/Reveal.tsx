"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";

/**
 * Scroll-triggered entrance wrapper. Content stays visible without
 * JavaScript; the hidden pre-state is only applied after mount so crawlers
 * and no-JS clients always see the full page.
 */
export function Reveal({ children, className, delay = 0 }: { children: ReactNode; className?: string; delay?: number }) {
  const ref = useRef<HTMLDivElement>(null);
  const [visible, setVisible] = useState(false);
  useEffect(() => {
    // Reduced-motion users (and screenshot/a11y tooling) must never get a
    // hidden page: skip the pre-state entirely instead of arming it.
    if (typeof window !== "undefined" && typeof window.matchMedia === "function" && window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      setVisible(true);
      return;
    }
    document.documentElement.classList.add("reveal-armed");
    const node = ref.current;
    if (!node) return;
    if (typeof IntersectionObserver === "undefined") { setVisible(true); return; }
    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          setVisible(true);
          observer.disconnect();
        }
      },
      { threshold: 0.08, rootMargin: "0px 0px -6% 0px" },
    );
    observer.observe(node);
    return () => observer.disconnect();
  }, []);
  const style = delay > 0 ? { "--reveal-delay": `${Math.min(600, Math.max(0, delay))}ms` } as React.CSSProperties : undefined;
  return <div ref={ref} style={style} className={visible ? `reveal is-visible${className ? ` ${className}` : ""}` : `reveal${className ? ` ${className}` : ""}`}>{children}</div>;
}
