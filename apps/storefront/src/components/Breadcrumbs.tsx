import Link from "next/link";
import { getCopy, type Locale } from "@/lib/i18n";

export type BreadcrumbCategory = { label: string; href: string };

export function Breadcrumbs({ current, locale, category }: { current: string; locale?: Locale; category?: BreadcrumbCategory }) {
  const text = getCopy(locale);
  return (
    <nav className="breadcrumbs" aria-label={text.navigation.breadcrumb}>
      <ol>
        <li><Link href="/">{text.navigation.home}</Link></li>
        {category && category.label && (
          <>
            <li aria-hidden="true">/</li>
            <li><Link href={category.href}>{category.label}</Link></li>
          </>
        )}
        <li aria-hidden="true">/</li>
        <li aria-current="page">{current}</li>
      </ol>
    </nav>
  );
}
