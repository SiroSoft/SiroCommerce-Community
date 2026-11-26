import type { ReactNode } from "react";
import { getCopy, type Locale } from "@/lib/i18n";
import { readTextProp, readUspPoints } from "@/homepage/section-data";

/** Only these vetted glyphs can be rendered, so a config value can never
 * inject SVG markup. Unknown keys simply render without an icon. */
const ICONS: Record<string, ReactNode> = {
  truck: <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7z" /><circle cx="7" cy="18" r="1.6" /><circle cx="17" cy="18" r="1.6" /></svg>,
  shield: <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3l7 3v6c0 4.2-3 7.4-7 9-4-1.6-7-4.8-7-9V6z" /><path d="M9 12l2 2 4-4" /></svg>,
  leaf: <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 19c0-8 5-13 14-14 0 9-5 14-14 14z" /><path d="M5 19c4-4 7-6 11-8" /></svg>,
  heart: <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20s-7-4.4-7-9a4 4 0 017-2.6A4 4 0 0119 11c0 4.6-7 9-7 9z" /></svg>,
  sparkle: <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3l2 6 6 2-6 2-2 6-2-6-6-2 6-2z" /></svg>,
  return: <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 11a8 8 0 0114-5l2 2" /><path d="M20 4v5h-5" /><path d="M20 13a8 8 0 01-14 5l-2-2" /><path d="M4 20v-5h5" /></svg>,
  refresh: <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 11a8 8 0 0114-5l2 2" /><path d="M20 4v5h-5" /><path d="M20 13a8 8 0 01-14 5l-2-2" /><path d="M4 20v-5h5" /></svg>,
  support: <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 13a8 8 0 0116 0" /><path d="M4 13v4a2 2 0 002 2h1v-7H6a2 2 0 00-2 2z" /><path d="M20 13v4a2 2 0 01-2 2h-1v-7h1a2 2 0 012 2z" /></svg>,
};

export function UspStrip({ props, locale }: { props?: Record<string, unknown>; locale?: Locale }) {
  const copy = getCopy(locale);
  const points = readUspPoints(props);
  if (points.length === 0) return null;
  const eyebrow = readTextProp(props, "eyebrow") ?? copy.home.uspEyebrow;
  const title = readTextProp(props, "title") ?? copy.home.uspTitle;
  return <div className="sf-sec-usp-strip">
    <div className="sf-sec-heading">
      <p className="eyebrow">{eyebrow}</p>
      <h2>{title}</h2>
    </div>
    <ul className="sf-sec-usp-list" data-count={Math.min(4, Math.max(2, points.length))}>
      {points.map((point, index) => <li className="sf-sec-usp-item" key={`${point.title}-${index}`}>
        {point.icon && ICONS[point.icon] ? <span className="sf-sec-usp-icon">{ICONS[point.icon]}</span> : null}
        <div className="sf-sec-usp-copy">
          <h3>{point.title}</h3>
          {point.copy && <p>{point.copy}</p>}
        </div>
      </li>)}
    </ul>
  </div>;
}
