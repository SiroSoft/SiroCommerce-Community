import type { Metadata } from "next";
import { cookies } from "next/headers";
import { Inter } from "next/font/google";
import "./globals.css";
import { Providers } from "@/providers/providers";
import { Toaster } from "@/components/ui/toast";

const inter = Inter({ subsets: ["latin"] });
const SUPPORTED_LOCALES = ["en", "vi", "de", "zh", "ja"] as const;

function resolveLocale(value: string | undefined): string {
  return value && (SUPPORTED_LOCALES as readonly string[]).includes(value) ? value : "en";
}

export const metadata: Metadata = {
  title: "Siro Admin",
  description: "Siro Admin Starter - Modern admin panel for SiroPHP APIs",
  icons: { icon: "/favicon.svg" },
  openGraph: {
    title: "Siro Admin - SiroPHP Demo",
    description: "Try the live demo, then grab the open-source admin template and SiroPHP skeleton.",
    url: "https://admin-next.sirophp.com",
    siteName: "Siro Admin",
    type: "website",
  },
  twitter: { card: "summary", title: "Siro Admin - SiroPHP Demo" },
};

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const locale = resolveLocale((await cookies()).get("siro_locale")?.value);
  return (
    <html lang={locale} suppressHydrationWarning>
      <body className={inter.className}>
        <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[100] focus:rounded-md focus:bg-background focus:px-4 focus:py-3 focus:shadow">Skip to content</a>
        <Providers>
          {children}
          <Toaster />
        </Providers>
      </body>
    </html>
  );
}
