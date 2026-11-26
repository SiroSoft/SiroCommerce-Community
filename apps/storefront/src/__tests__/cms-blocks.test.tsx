import "@testing-library/jest-dom/vitest";
import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { CmsBlocks } from "@/components/cms/CmsBlocks";
import { sanitizeCmsHtml, readCmsFaq } from "@/lib/cms";
import type { CmsBlock } from "@/lib/cms";

function block(type: string, content: string, extra: Partial<CmsBlock> = {}): CmsBlock {
  return { type, content, status: "published", ...extra };
}

describe("CMS block rendering", () => {
  it("renders hero, rich text, image, cta and faq blocks", () => {
    const { container } = render(<CmsBlocks title="Welcome" blocks={[
      block("hero", JSON.stringify({ title: "Welcome", subtitle: "A slower edit", image: "/catalog/b2c/linen-mandarin-shirt-front.svg", cta: { label: "Shop now", href: "/search" } })),
      block("rich_text", "<p>Hello <strong>world</strong></p>"),
      block("image", JSON.stringify({ src: "/catalog/b2c/jasmine-oolong-tea-front.svg", alt: "Tea", caption: "Brewed slowly" })),
      block("cta", JSON.stringify({ label: "Contact us", href: "/pages/contact", copy: "Questions?" })),
      block("faq", JSON.stringify({ items: [{ question: "How long?", answer: "Two days" }] })),
    ]} />);
    expect(screen.getByRole("heading", { level: 1, name: "Welcome" })).toBeInTheDocument();
    expect(screen.getByText("A slower edit")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Shop now" })).toHaveAttribute("href", "/search");
    expect(screen.getByText("Hello").tagName).toBe("P");
    expect(screen.getByAltText("Tea")).toHaveAttribute("src", "/catalog/b2c/jasmine-oolong-tea-front.svg");
    expect(screen.getByText("Brewed slowly")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Contact us" })).toHaveAttribute("href", "/pages/contact");
    expect(screen.getByText("How long?")).toBeInTheDocument();
    expect(container.querySelectorAll(".sf-cms-faq-item")).toHaveLength(1);
  });

  it("falls back to sanitized page body when a page has no blocks", () => {
    render(<CmsBlocks title="About" body="<p>Our <em>story</em></p><script>alert(1)</script>" />);
    expect(screen.getByText("Our").closest("p")).toContainHTML("<em>story</em>");
    expect(document.body.innerHTML).not.toContain("<script");
  });
});

describe("CMS HTML sanitizer", () => {
  it("removes scripts, event handlers and javascript URLs", () => {
    const safe = sanitizeCmsHtml('<p onclick="steal()">Hi<script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)">link</a></p>');
    expect(safe).not.toContain("<script");
    expect(safe).not.toContain("onclick");
    expect(safe).not.toContain("onerror");
    expect(safe).not.toContain("javascript:");
    expect(safe).toContain("<p>Hi");
    expect(safe).toContain("<a>link</a>");
  });

  it("keeps the allowlisted structure and safe links", () => {
    const safe = sanitizeCmsHtml('<h2>Title</h2><ul><li>One</li></ul><a href="/pages/contact" target="_blank">Contact</a><details><summary>Q</summary><p>A</p></details>');
    expect(safe).toContain("<h2>Title</h2>");
    expect(safe).toContain("<li>One</li>");
    expect(safe).toContain('href="/pages/contact"');
    expect(safe).toContain('rel="noopener noreferrer"');
    expect(safe).toContain("<details><summary>Q</summary>");
  });

  it("reads FAQ JSON without inventing entries from unsafe markup", () => {
    const faq = readCmsFaq(block("faq", JSON.stringify([{ question: "Is it safe?", answer: "<script>alert(1)</script>Yes" }])));
    expect(faq.entries).toHaveLength(1);
    expect(faq.entries[0]?.answer).toBe("alert(1)Yes");
  });
});
