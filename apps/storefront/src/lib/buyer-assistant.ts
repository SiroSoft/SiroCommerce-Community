import type { Product } from "@/types";

export type BuyerAssistantState = "ready" | "loading" | "error" | "unavailable";
export type BuyerAssistantOutcome = "matches" | "no_match" | "empty" | "unsupported" | "too_long";

export const BUYER_ASSISTANT_MAX_INPUT = 240;
const unsupportedTerms = /\b(order|checkout|payment|tax|shipping|delivery|refund|return|policy|account|password|discount code|coupon)\b/i;

export function analyzeBuyerQuestion(question: unknown, products: Product[], limit = 3): { outcome: BuyerAssistantOutcome; question: string; suggestions: Product[] } {
  if (typeof question !== "string") return { outcome: "empty", question: "", suggestions: [] };
  const bounded = question.trim().slice(0, BUYER_ASSISTANT_MAX_INPUT);
  if (question.trim().length > BUYER_ASSISTANT_MAX_INPUT) return { outcome: "too_long", question: bounded, suggestions: [] };
  if (!bounded) return { outcome: "empty", question: "", suggestions: [] };
  if (unsupportedTerms.test(bounded)) return { outcome: "unsupported", question: bounded, suggestions: [] };
  const suggestions = suggestProducts(bounded, products, limit);
  return { outcome: suggestions.length ? "matches" : "no_match", question: bounded, suggestions };
}

export function suggestProducts(question: string, products: Product[], limit = 3): Product[] {
  if (typeof question !== "string" || !Number.isFinite(limit) || limit < 1) return [];
  const terms = question.trim().slice(0, BUYER_ASSISTANT_MAX_INPUT).toLowerCase().split(/\s+/).filter(Boolean);
  if (terms.length === 0) return [];
  return products.map((product, index) => ({ product, index, score: terms.reduce((score, term) => score + (`${product.name} ${product.description} ${product.categoryName} ${product.tags.join(" ")}`.toLowerCase().includes(term) ? 1 : 0), 0) }))
    .filter((entry) => entry.score > 0).sort((a, b) => b.score - a.score || a.index - b.index).slice(0, limit).map((entry) => entry.product);
}
