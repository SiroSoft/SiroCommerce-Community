import type { Metadata } from "next";
import Link from "next/link";
import { WishlistAccountView } from "@/components/WishlistProvider";
import { getCopy } from "@/lib/i18n";
import { requestLocale } from "@/lib/request-locale";

export async function generateMetadata(): Promise<Metadata> {
  const copy = getCopy(await requestLocale()).accountWishlist;
  return { title: copy.title, robots: { index: false, follow: false } };
}

export default async function AccountWishlistPage() {
  const copy = getCopy(await requestLocale());
  return <>
    <section className="page-intro">
      <p className="eyebrow">{copy.accountWishlist.title}</p>
      <h1>{copy.accountWishlist.title}</h1>
      <p>{copy.wishlistPanel.nearby}</p>
      <p className="muted"><Link className="text-link" href="/account">{copy.account.dashboard}</Link></p>
    </section>
    <WishlistAccountView />
  </>;
}
