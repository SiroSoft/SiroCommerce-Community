"use client";

import { useEffect, useRef, useState, type MouseEvent as ReactMouseEvent, type ReactElement } from "react";
import { fill } from "@/lib/i18n";
import { useCopy } from "@/lib/i18n-client";
import { GALLERY_IMAGE_WIDTHS, srcSetFor, type ImageSources } from "@/lib/product-image";
import type { ImageVariant } from "@/types";

export type GalleryFrame = { url: string; alt: string; type?: string; variants?: ImageVariant[]; placeholder?: string };

function placeholderStyle(placeholder?: string): { backgroundImage: string; backgroundSize: string; backgroundPosition: string } | undefined {
  if (!placeholder) return undefined;
  return { backgroundImage: `url("${placeholder.replace(/"/g, "%22")}")`, backgroundSize: "cover", backgroundPosition: "center" };
}

function pictureMarkup(resolved: ImageSources, image: ReactElement): ReactElement {
  if (resolved.sources.length === 0) return image;
  return <picture style={{ display: "contents" }}>
    {resolved.sources.map((source) => <source key={source.type} type={source.type} srcSet={source.srcSet} sizes={resolved.sizes} />)}
    {image}
  </picture>;
}

const EMBED_HOSTS = ["youtube.com", "www.youtube.com", "m.youtube.com", "youtu.be", "vimeo.com", "www.vimeo.com", "player.vimeo.com"];

function toEmbedUrl(url: string): string | null {
  try {
    const u = new URL(url, "https://x.local");
    const host = u.hostname.toLowerCase();
    if (!EMBED_HOSTS.includes(host)) return null;
    if (host === "youtu.be") {
      const id = u.pathname.replace("/", "");
      if (!/^[\w-]{6,}$/.test(id)) return null;
      return `https://www.youtube.com/embed/${id}`;
    }
    if (host.includes("youtube.com")) {
      const id = u.searchParams.get("v");
      if (id && /^[\w-]{6,}$/.test(id)) return `https://www.youtube.com/embed/${id}`;
      const m = u.pathname.match(/^\/(embed|shorts)\/([\w-]{6,})/);
      if (m) return `https://www.youtube.com/embed/${m[2]}`;
      return null;
    }
    const m = u.pathname.match(/\/(\d{5,})/);
    if (m) return `https://player.vimeo.com/video/${m[1]}`;
    return null;
  } catch {
    return null;
  }
}

function isImageFrame(frame: GalleryFrame): boolean {
  return frame.type !== "video" && frame.type !== "youtube";
}

const FOCUSABLE = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export function ProductGallery({ frames, name, label, counter, sizes = "(max-width: 700px) 100vw, 55vw" }: { frames: GalleryFrame[]; name: string; label: string; counter: string; sizes?: string }) {
  const copy = useCopy().product;
  const gallery = frames.length > 0 ? frames : [{ url: "/placeholder-product.svg", alt: name }];
  const [active, setActive] = useState(0);
  const [open, setOpen] = useState(false);
  const [zoomed, setZoomed] = useState(false);
  const dialogRef = useRef<HTMLDivElement | null>(null);
  const closeRef = useRef<HTMLButtonElement | null>(null);
  const lastTapRef = useRef(0);
  const safeIndex = Math.min(active, gallery.length - 1);
  const current = gallery[safeIndex]!;
  const imageIndices = gallery.flatMap((frame, index) => (isImageFrame(frame) ? [index] : []));
  const imageIndexKey = imageIndices.join(",");
  const zoomable = imageIndices.length > 0 && gallery.length > 1;
  const caption = counter.replace("{index}", String(safeIndex + 1)).replace("{total}", String(gallery.length));

  function step(delta: number) {
    setActive((index) => {
      const position = imageIndices.indexOf(Math.min(index, gallery.length - 1));
      if (position === -1) return index;
      return imageIndices[(position + delta + imageIndices.length) % imageIndices.length]!;
    });
    setZoomed(false);
  }

  function openZoom(event: ReactMouseEvent<HTMLElement>) {
    if (!zoomable || !isImageFrame(current)) return;
    event.currentTarget.focus();
    setZoomed(false);
    setOpen(true);
  }

  function closeZoom() {
    setOpen(false);
    setZoomed(false);
  }

  useEffect(() => {
    if (!open) return;
    const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    closeRef.current?.focus();
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") {
        event.preventDefault();
        closeZoom();
        return;
      }
      if (event.key === "ArrowRight") {
        event.preventDefault();
        step(1);
        return;
      }
      if (event.key === "ArrowLeft") {
        event.preventDefault();
        step(-1);
        return;
      }
      if (event.key !== "Tab" || !dialogRef.current) return;
      const focusable = Array.from(dialogRef.current.querySelectorAll<HTMLElement>(FOCUSABLE)).filter((element) => element.getAttribute("aria-hidden") !== "true");
      if (focusable.length === 0) return;
      const first = focusable[0]!;
      const last = focusable[focusable.length - 1]!;
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.removeEventListener("keydown", onKeyDown);
      previous?.focus();
    };
  }, [open, imageIndexKey]);

  const zoomSrc = srcSetFor(current.url, GALLERY_IMAGE_WIDTHS, "100vw", current.variants);

  const main = (() => {
    if (current.type === "youtube") {
      const embed = toEmbedUrl(current.url);
      if (embed) {
        return <div className="detail-image"><iframe src={embed} title={current.alt || name} loading="lazy" allow="accelerometer; encrypted-media; picture-in-picture" allowFullScreen style={{ width: "100%", height: "100%", minHeight: 320, border: 0 }} /></div>;
      }
      return <span className="cart-item-image cart-item-image-missing" aria-hidden="true">▶</span>;
    }
    if (current.type === "video") {
      return <div className="detail-image"><video src={current.url} controls preload="none" playsInline aria-label={current.alt || name} style={{ width: "100%", height: "100%" }} /></div>;
    }
    const mainSrc = srcSetFor(current.url, GALLERY_IMAGE_WIDTHS, sizes, current.variants);
    const image = pictureMarkup(mainSrc, <img src={current.url} srcSet={mainSrc.sources.length === 0 ? mainSrc.srcSet : undefined} sizes={mainSrc.sizes} style={placeholderStyle(current.placeholder)} alt={current.alt || name} loading={safeIndex === 0 ? "eager" : "lazy"} fetchPriority={safeIndex === 0 ? "high" : "auto"} decoding="async" width="1200" height="1500" />);
    if (!zoomable) return <div className="detail-image">{image}</div>;
    return <button type="button" className="detail-image sf-media-main-button" onClick={openZoom} aria-label={fill(copy.galleryOpen, { label, index: safeIndex + 1 })} aria-haspopup="dialog">
      {image}
      <span className="sf-media-zoom-cue" aria-hidden="true">+</span>
    </button>;
  })();

  return <div className="product-gallery" data-active={active}>
    {main}
    <p className="gallery-note" aria-live="polite">{label} · {caption}</p>
    {gallery.length > 1 && <ul className="gallery-thumbs sf-media-thumbs" aria-label={label}>
      {gallery.map((frame, index) => {
        const thumbSrc = srcSetFor(frame.url, [96, 192], "96px", frame.variants);
        return <li key={`${frame.url}-${index}`}>
          <button type="button" className="sf-media-thumb-button" onClick={() => { setActive(index); setZoomed(false); }} aria-pressed={index === safeIndex} aria-current={index === safeIndex ? "true" : undefined} aria-label={`${label} ${index + 1}`}>
            {isImageFrame(frame)
              ? pictureMarkup(thumbSrc, <img src={frame.url} srcSet={thumbSrc.sources.length === 0 ? thumbSrc.srcSet : undefined} sizes={thumbSrc.sizes} alt="" aria-hidden="true" loading="lazy" decoding="async" width="96" height="120" />)
              : <span className="cart-item-image cart-item-image-missing sf-media-thumb-glyph" aria-hidden="true">▶</span>}
          </button>
        </li>;
      })}
    </ul>}
    {open && zoomable && <div className="sf-media-zoom-overlay" role="dialog" aria-modal="true" aria-label={`${label}. ${caption}`} ref={dialogRef}>
      <div className="sf-media-zoom-top">
        <p>{caption}</p>
        <button type="button" className="sf-media-zoom-close" ref={closeRef} onClick={closeZoom} aria-label={copy.galleryClose}>×</button>
      </div>
      <div className="sf-media-zoom-stage">
        {imageIndices.length > 1 && <button type="button" className="sf-media-zoom-nav" onClick={() => step(-1)} aria-label={copy.galleryPrev}>‹</button>}
        <div
          className={`sf-media-zoom-frame${zoomed ? " is-zoomed" : ""}`}
          onDoubleClick={() => setZoomed((value) => !value)}
          onTouchEnd={(event) => {
            if (event.touches.length > 0) return;
            const now = Date.now();
            if (now - lastTapRef.current < 320) setZoomed((value) => !value);
            lastTapRef.current = now;
          }}
        >
          {pictureMarkup(zoomSrc, <img src={current.url} srcSet={zoomSrc.sources.length === 0 ? zoomSrc.srcSet : undefined} sizes="100vw" style={placeholderStyle(current.placeholder)} alt={current.alt || name} loading="lazy" decoding="async" width="1600" height="2000" />)}
        </div>
        {imageIndices.length > 1 && <button type="button" className="sf-media-zoom-nav" onClick={() => step(1)} aria-label={copy.galleryNext}>›</button>}
      </div>
      <p className="sf-media-zoom-hint">{copy.galleryZoomHint}</p>
    </div>}
  </div>;
}
