import type { MetadataRoute } from "next";

export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "SIRO Commerce",
    short_name: "SIRO",
    description: "Shop the SIRO Commerce collection.",
    start_url: "/",
    display: "standalone",
    background_color: "#f5f5f0",
    theme_color: "#1e2420",
    icons: [
      { src: "/icon-192.svg", sizes: "192x192", type: "image/svg+xml", purpose: "maskable" },
      { src: "/icon-512.svg", sizes: "512x512", type: "image/svg+xml", purpose: "maskable" },
    ],
  };
}
