import type { Metadata } from "next";
import { getPublicThemePresets } from "@/services/storefront-config";

export const metadata: Metadata = {
  title: "Theme preview",
  description: "Try SiroCommerce storefront themes with sample content.",
  robots: { index: false, follow: false },
};

export default async function PreviewGalleryPage() {
  const presets = await getPublicThemePresets();
  return (
    <section className="theme-preview-gallery" aria-labelledby="theme-preview-title">
      <p className="eyebrow">Theme gallery</p>
      <h1 id="theme-preview-title">Try a storefront theme</h1>
      <p className="lede">Preview mode only changes presentation. Prices, stock, payments and saved storefront config stay untouched. Exit the preview at any time.</p>
      {presets.length === 0 ? (
        <p className="theme-preview-empty" role="status">Theme previews are temporarily unavailable. The published storefront is unaffected.</p>
      ) : (
        <ul className="theme-preview-grid">
          {presets.map((preset) => {
            const tokens = preset.theme?.tokens as { colors?: Record<string, string> } | undefined;
            const colors = tokens?.colors ?? {};
            return (
              <li key={preset.key} className="theme-preview-card">
                {preset.preview.startsWith("/media/theme-previews/") ? (
                  <img className="theme-preview-image" src={preset.preview} alt="" loading="lazy" width={900} height={900} />
                ) : (
                  <div className="theme-preview-swatches" aria-hidden="true">
                    {[colors.ink, colors.paper, colors.accent, colors.surface].filter(Boolean).map((color) => (
                      <span key={color} style={{ background: color }} />
                    ))}
                  </div>
                )}
                <h2>{preset.name}</h2>
                {preset.layout_archetype ? <p className="theme-preview-style">{preset.layout_archetype}</p> : null}
                <p>{preset.description}</p>
                <a className="button" href={`/preview/start?theme=${encodeURIComponent(preset.key)}`}>Try this theme</a>
              </li>
            );
          })}
        </ul>
      )}
      <p className="muted"><a href="/preview/exit">Exit preview</a> · <a href="/">Back to the published storefront</a></p>
    </section>
  );
}
